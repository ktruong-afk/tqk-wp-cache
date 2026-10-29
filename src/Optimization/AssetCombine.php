<?php
namespace TQK_WP_CACHE\Optimization;

use TQK_WP_CACHE\Core\Config;

defined('ABSPATH') || exit;

/**
 * Merges CSS / JS files into bundles.
 *
 * Safety rules (each one is a bug the first version had):
 *  - ONLY neighbouring tags are merged. WordPress prints inline <style> / <script> (wp_localize_script, wp_add_inline_style) between
 *    files, and they must keep their place in the cascade / execution order, so an inline block ends a run;
 *  - stylesheets with a media attribute other than all/screen (print, min-width...) are left alone, merging them forced media="all";
 *  - files with @import / @charset, or a "use strict" directive, are left alone (the directive would apply to the whole bundle);
 *  - url() inside CSS is rewritten to root-relative paths, so one bundle works for http and https;
 *  - the bundle name contains each source's mtime + size, so editing a file without changing ?ver= still produces a new bundle;
 *  - bundles are written atomically and "touched" when reused, so Maintenance can delete the ones nobody references any more.
 */
class AssetCombine {
    private bool $combine_css;
    private bool $combine_js;
    private array $exclusions;
    private string $storage_dir;
    private string $storage_url;

    public function __construct() {
        $config = Config::instance();
        $this->combine_css = (bool)$config->get('combine_css', 1);
        $this->combine_js  = (bool)$config->get('combine_js', 1);
        $this->exclusions  = $config->lines('combine_exclusions');

        $this->storage_dir = rtrim(TQK_WP_CACHE_STORAGE_DIR, '/\\') . '/assets';
        $this->storage_url = content_url('/cache/tqk-cache/assets');
    }

    public function process(string $html): string {
        if (!$this->combine_css && !$this->combine_js) {
            return $html;
        }

        $head_end = stripos($html, '</head>');
        if ($head_end === false) {
            return $html;
        }
        $context = $this->detect_context();
        $head = substr($html, 0, $head_end);
        $body = substr($html, $head_end);

        if ($this->combine_css) {
            $head = $this->combine_runs($head, 'css', $context, 'head');
        }
        if ($this->combine_js) {
            $head = $this->combine_runs($head, 'js', $context, 'head');
            $body = $this->combine_runs($body, 'js', $context, 'footer');
        }
        return $head . $body;
    }

    private function detect_context(): string {
        if (is_front_page() || is_home()) {
            return 'frontpage';
        }
        if (is_singular()) {
            return 'single-' . sanitize_key(get_post_type() ?: 'post');
        }
        if (is_archive()) {
            return 'archive';
        }
        return 'general';
    }

    /* ------------------------------------------------------------------ run building */

    private function combine_runs(string $fragment, string $kind, string $context, string $location): string {
        $pattern = $kind === 'css'
            ? '#<link\b[^>]*>#i'
            : '#<script\b[^>]*\bsrc\s*=[^>]*>\s*</script\s*>#i';

        if (!preg_match_all($pattern, $fragment, $m, PREG_OFFSET_CAPTURE)) {
            return $fragment;
        }

        // Candidates in document order.
        $items = [];
        foreach ($m[0] as [$tag, $offset]) {
            $info = $kind === 'css' ? $this->css_candidate($tag) : $this->js_candidate($tag);
            if ($info !== null) {
                $items[] = ['tag' => $tag, 'start' => $offset, 'end' => $offset + strlen($tag)] + $info;
            }
        }

        // Group neighbours: only whitespace between two candidates.
        $runs = [];
        $current = [];
        foreach ($items as $item) {
            if ($current) {
                $prev = end($current);
                $gap = substr($fragment, $prev['end'], $item['start'] - $prev['end']);
                if (trim($gap) !== '') {
                    $runs[] = $current;
                    $current = [];
                }
            }
            $current[] = $item;
        }
        if ($current) {
            $runs[] = $current;
        }

        // Replace from the end so earlier offsets stay valid.
        foreach (array_reverse($runs) as $run) {
            if (count($run) < 2) {
                continue;
            }
            $bundle_url = $kind === 'css' ? $this->build_css($run, $context) : $this->build_js($run, $context, $location);
            if ($bundle_url === null) {
                continue;
            }
            $tag = $kind === 'css'
                ? sprintf('<link rel="stylesheet" id="tqk-combined-%s-css" href="%s" media="all" />', esc_attr($location), esc_url($bundle_url))
                : sprintf('<script id="tqk-combined-%s-js" src="%s"></script>', esc_attr($location), esc_url($bundle_url));
            $start = $run[0]['start'];
            $end = end($run)['end'];
            $fragment = substr($fragment, 0, $start) . $tag . substr($fragment, $end);
        }

        return $fragment;
    }

    private function css_candidate(string $tag): ?array {
        $rel = strtolower((string)$this->attr($tag, 'rel'));
        if (!preg_match('/(^|\s)stylesheet(\s|$)/', $rel) || strpos($rel, 'alternate') !== false) {
            return null;
        }
        $href = (string)$this->attr($tag, 'href');
        if ($href === '' || !preg_match('#\.css(?:\?|$)#i', $href)) {
            return null;
        }
        $media = strtolower(trim((string)$this->attr($tag, 'media')));
        if (!in_array($media, ['', 'all', 'screen'], true)) {
            return null;
        }
        if (preg_match('#\b(?:onload|disabled|data-tqk-no-combine)\b#i', $tag) || $this->is_excluded($href) || $this->is_excluded($tag)) {
            return null;
        }
        $path = $this->url_to_local_path($href);
        if ($path === null) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false || preg_match('/@import|@charset/i', $content)) {
            return null;
        }
        return ['url' => html_entity_decode($href), 'path' => $path];
    }

    private function js_candidate(string $tag): ?array {
        if (preg_match('#\b(?:async|defer|nomodule|data-tqk-no-combine|data-no-optimize)\b#i', $tag)) {
            return null;
        }
        $type = strtolower(trim((string)$this->attr($tag, 'type')));
        if (!in_array($type, ['', 'text/javascript', 'application/javascript'], true)) {
            return null; // modules, json, importmap...
        }
        $src = (string)$this->attr($tag, 'src');
        if ($src === '' || !preg_match('#\.js(?:\?|$)#i', $src) || $this->is_excluded($src) || $this->is_excluded($tag)) {
            return null;
        }
        $path = $this->url_to_local_path($src);
        if ($path === null) {
            return null;
        }
        $head = (string)@file_get_contents($path, false, null, 0, 600);
        // A leading "use strict" would make the whole bundle strict.
        if (preg_match('#^\s*(?:/\*.*?\*/\s*|//[^\n]*\n\s*)*[\'"]use strict[\'"]#s', $head)) {
            return null;
        }
        return ['url' => html_entity_decode($src), 'path' => $path];
    }

    private function attr(string $tag, string $name): ?string {
        if (preg_match('#\b' . preg_quote($name, '#') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $tag, $m)) {
            return $m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''));
        }
        return null;
    }

    /* ------------------------------------------------------------------ bundles */

    private function bundle_name(array $run, string $context, string $location, string $ext): array {
        $parts = [];
        foreach ($run as $item) {
            $parts[] = $item['url'] . '@' . (int)@filemtime($item['path']) . ':' . (int)@filesize($item['path']);
        }
        $hash = substr(md5($context . '|' . $location . '|' . implode('|', $parts)), 0, 16);
        $file = $location . '-' . $hash . '.' . $ext;
        $dir = $this->storage_dir . '/' . $ext . '/' . $context;
        return [
            'path' => $dir . '/' . $file,
            'dir'  => $dir,
            'url'  => $this->storage_url . '/' . $ext . '/' . $context . '/' . $file,
        ];
    }

    private function build_css(array $run, string $context): ?string {
        $b = $this->bundle_name($run, $context, 'head', 'css');
        if (!$this->reuse($b['path'])) {
            $css = '';
            foreach ($run as $item) {
                $content = @file_get_contents($item['path']);
                if ($content === false) {
                    return null;
                }
                $css .= $this->rewrite_urls($content, $item['url']) . "\n";
            }
            if (!$this->write($b['dir'], $b['path'], $this->minify_css($css))) {
                return null;
            }
        }
        return $b['url'];
    }

    private function build_js(array $run, string $context, string $location): ?string {
        $b = $this->bundle_name($run, $context, $location, 'js');
        if (!$this->reuse($b['path'])) {
            $js = '';
            foreach ($run as $item) {
                $content = @file_get_contents($item['path']);
                if ($content === false) {
                    return null;
                }
                $js .= $this->prepare_js($content) . "\n;\n";
            }
            // Sanity check: a bundle much smaller than its sources means something went wrong while merging. Never ship that.
            $source_bytes = 0;
            foreach ($run as $item) {
                $source_bytes += (int)@filesize($item['path']);
            }
            if (strlen($js) < $source_bytes * 0.8 || !$this->write($b['dir'], $b['path'], $js)) {
                return null;
            }
        }
        return $b['url'];
    }

    /** Strip the source-map comment (its relative path would be wrong inside the bundle). Never lose the code if the regex fails. */
    private function prepare_js(string $content): string {
        $clean = preg_replace('~//[#@]\s*sourceMappingURL=\S+\s*$~m', '', $content);
        return trim($clean ?? $content);
    }

    /** An existing bundle is reused; touching it (at most hourly) tells Maintenance it is still referenced. */
    private function reuse(string $path): bool {
        $mtime = @filemtime($path);
        if ($mtime === false || (int)@filesize($path) < 16) {
            return false; // missing, or an empty bundle left by an earlier bug: rebuild
        }
        if (time() - $mtime > 3600) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions
            @touch($path);
        }
        return true;
    }

    private function write(string $dir, string $path, string $content): bool {
        if (!wp_mkdir_p($dir)) {
            return false;
        }
        $storage = new \TQK_WP_CACHE\Cache\DiskStorage();
        if (!$storage->atomic_write($path, $content)) {
            return false;
        }
        if (function_exists('gzencode')) {
            $storage->atomic_write($path . '.gz', gzencode($content, 9));
        }
        return true;
    }

    /* ------------------------------------------------------------------ CSS helpers */

    private function rewrite_urls(string $css, string $css_url): string {
        $base = $this->absolute($css_url);
        return preg_replace_callback('#url\(\s*([\'"]?)([^\'")]*)\1\s*\)#i', function ($m) use ($base) {
            $ref = trim($m[2]);
            if ($ref === '' || preg_match('#^(data:|about:|\#|https?:|//)#i', $ref)) {
                return $m[0];
            }
            return 'url("' . $this->resolve($base, $ref) . '")';
        }, $css) ?? $css;
    }

    /** Absolute (scheme://host/path) form of a URL that may be root-relative or protocol-relative. */
    private function absolute(string $url): string {
        if (strpos($url, '//') === 0) {
            return (is_ssl() ? 'https:' : 'http:') . $url;
        }
        if ($url !== '' && $url[0] === '/') {
            $origin = (string)wp_parse_url(site_url(), PHP_URL_SCHEME) . '://' . (string)wp_parse_url(site_url(), PHP_URL_HOST);
            $port = wp_parse_url(site_url(), PHP_URL_PORT);
            return $origin . ($port ? ':' . $port : '') . $url;
        }
        return $url;
    }

    /** Resolve $ref against the CSS file URL; same-site targets become root-relative, others protocol-relative. */
    private function resolve(string $base_url, string $ref): string {
        $suffix = '';
        if (preg_match('#^([^?\#]*)([?\#].*)$#', $ref, $m)) {
            $ref = $m[1];
            $suffix = $m[2];
        }
        $b = wp_parse_url($base_url);
        $host = (string)($b['host'] ?? '');
        $port = isset($b['port']) ? ':' . $b['port'] : '';

        if ($ref !== '' && $ref[0] === '/') {
            $path = $ref;
        } else {
            $dir = preg_replace('#/[^/]*$#', '/', (string)($b['path'] ?? '/'));
            $path = $dir . $ref;
        }

        $stack = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($stack);
            } else {
                $stack[] = $seg;
            }
        }
        $clean = '/' . implode('/', $stack);

        $site_host = (string)wp_parse_url(site_url(), PHP_URL_HOST);
        $out = strcasecmp($host, $site_host) === 0 ? $clean : '//' . $host . $port . $clean;
        return $out . $suffix;
    }

    /**
     * Whitespace/comment removal that keeps meaning: spaces BEFORE ":" stay (".menu :first-child" is not ".menu:first-child"),
     * "+" / "-" keep their spaces (calc()), strings and url() are never touched.
     */
    private function minify_css(string $css): string {
        $keep = [];
        $css = preg_replace_callback('#("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|url\(\s*[^)]*\))#', function ($m) use (&$keep) {
            $key = "\x01" . count($keep) . "\x02";
            $keep[$key] = $m[0];
            return $key;
        }, $css) ?? $css;

        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;
        $css = preg_replace('/\s*([{};,>~])\s*/', '$1', $css) ?? $css;
        $css = preg_replace('/:\s+/', ':', $css) ?? $css;
        $css = str_replace(';}', '}', $css);

        return trim(strtr($css, $keep));
    }

    /* ------------------------------------------------------------------ path helpers */

    private function is_excluded(string $target): bool {
        foreach ($this->exclusions as $ex) {
            if ($ex !== '' && stripos($target, $ex) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Only files inside WordPress (core, content dir) with a .css/.js extension; never arbitrary paths. */
    private function url_to_local_path(string $url): ?string {
        $url = html_entity_decode($url);
        $clean = strtok($url, '?#');
        if ($clean === false || !preg_match('#\.(css|js)$#i', $clean)) {
            return null;
        }
        $abs = preg_replace('#^https?:#i', '', $this->absolute($clean));

        $roots = [
            preg_replace('#^https?:#i', '', trailingslashit(content_url())) => trailingslashit(WP_CONTENT_DIR),
            preg_replace('#^https?:#i', '', trailingslashit(includes_url())) => trailingslashit(ABSPATH . WPINC),
            preg_replace('#^https?:#i', '', trailingslashit(site_url())) => trailingslashit(ABSPATH),
        ];
        foreach ($roots as $prefix => $dir) {
            if (strpos($abs, $prefix) === 0) {
                $candidate = $dir . rawurldecode(substr($abs, strlen($prefix)));
                $real = realpath($candidate);
                $allowed = [realpath(ABSPATH), realpath(WP_CONTENT_DIR)];
                if ($real && is_readable($real)) {
                    foreach ($allowed as $root) {
                        if ($root && strpos($real, $root) === 0) {
                            return $real;
                        }
                    }
                }
                return null;
            }
        }
        return null;
    }
}