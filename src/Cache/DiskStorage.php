<?php
namespace TQK_WP_CACHE\Cache;

use TQK_WP_CACHE\Core\Config;

defined('ABSPATH') || exit;

/**
 * Static HTML store.
 *
 * Layout:  <base>/<host>[__http]/<path>/index[.q<md5>][.m].html  (+ .html.gz, + .meta.json)
 *  - variants (query string, mobile) are FILES next to each other, never sub-folders, so a page whose slug is "mobile" or "q_x"
 *    can no longer collide with them;
 *  - http and https are separate (host dir gets a "__http" suffix) so a page generated for http can never reach an https visitor;
 *  - <base>/_tags/<tag>.idx lists the files carrying that tag, so purging by tag reads one small file instead of scanning
 *    every meta.json (that scan ran 3-6 times per saved post).
 * The request -> file mapping must stay identical to the generated drop-in (Dropin::generate_dropin_code()).
 */
class DiskStorage {
    private string $base_dir;
    private Config $config;

    public function __construct(?string $base_dir = null) {
        $this->base_dir = rtrim($base_dir ?: (defined('TQK_WP_CACHE_STORAGE_DIR') ? TQK_WP_CACHE_STORAGE_DIR : WP_CONTENT_DIR . '/cache/tqk-cache'), '/\\');
        $this->config = Config::instance();
    }

    public function base_dir(): string {
        return $this->base_dir;
    }

    public function ensure_dir(string $path): bool {
        if (!is_dir($path)) {
            return wp_mkdir_p($path);
        }
        return true;
    }

    /* ------------------------------------------------------------------ key mapping */

    public static function host_dir(string $host, bool $https): string {
        return str_replace([':', '[', ']'], '_', strtolower($host)) . ($https ? '' : '__http');
    }

    /** Path part of a URL as used in the file system, or null when it is unsafe. */
    public static function clean_path(string $path): ?string {
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }
        $decoded = rawurldecode($path);
        if (strpos($path, '//') !== false || preg_match('#[^A-Za-z0-9/_\-.%~+,;=@!$&\'()]#', $path)
            || preg_match('#(^|/)\.\.?(/|$)#', $decoded) || strpos($decoded, "\0") !== false || strpos($decoded, '\\') !== false) {
            return null;
        }
        return $path;
    }

    /**
     * '' = no query worth keying, 'q<md5>' = allow-listed query, null = this query string must bypass the cache.
     */
    public function query_key(string $query): ?string {
        if ($query === '') {
            return '';
        }
        parse_str($query, $qs);
        $allow = Dropin::query_allow($this->config);
        foreach ($qs as $k => $v) {
            $k = (string)$k;
            if (in_array($k, Dropin::IGNORED_QUERY_EXACT, true)) {
                unset($qs[$k]);
                continue;
            }
            foreach (Dropin::IGNORED_QUERY_PREFIX as $prefix) {
                if (strpos($k, $prefix) === 0) {
                    unset($qs[$k]);
                    continue 2;
                }
            }
            if (!in_array($k, $allow, true) || !is_scalar($v)) {
                return null;
            }
        }
        if (!$qs) {
            return '';
        }
        ksort($qs);
        return 'q' . md5(http_build_query($qs));
    }

    /**
     * Where the page for $url lives. Returns null when the URL must not be cached (unsafe path / query not allowed).
     * @return array{dir:string, base:string, rel:string, hostdir:string}|null
     */
    public function variant(string $url, bool $mobile = false): ?array {
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            return null;
        }
        $https = (($parts['scheme'] ?? 'https') === 'https');
        $host  = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path  = self::clean_path((string)($parts['path'] ?? ''));
        if ($path === null) {
            return null;
        }
        $qkey = $this->query_key((string)($parts['query'] ?? ''));
        if ($qkey === null) {
            return null;
        }

        $hostdir = self::host_dir($host, $https);
        $base    = 'index' . ($qkey !== '' ? '.' . $qkey : '') . ($mobile ? '.m' : '');
        $reldir  = $hostdir . ($path !== '' ? '/' . $path : '');

        return [
            'dir'     => $this->base_dir . '/' . $reldir,
            'base'    => $base,
            'rel'     => $reldir . '/' . $base,
            'hostdir' => $hostdir,
        ];
    }

    /** Modification time of the "purge everything" marker (0 = never purged). */
    public function epoch(): int {
        return (int)@filemtime($this->base_dir . '/_epoch');
    }

    /** A page counts as cached only while it is fresh: not purged (mtime 1), not older than the last purge-all, not expired. */
    public function is_fresh_file(string $file): bool {
        $m = @filemtime($file);
        return $m !== false && $m > 1 && $m >= $this->epoch() && (time() - $m) <= max(1, (int)$this->config->get('cache_lifespan', 10)) * 3600;
    }

    public function exists(string $url, bool $mobile = false): bool {
        $v = $this->variant($url, $mobile);
        return $v !== null && $this->is_fresh_file($v['dir'] . '/' . $v['base'] . '.html');
    }

    /**
     * Remember that this URL cannot be cached (password page, DONOTCACHEPAGE, Set-Cookie...). The drop-in then skips the
     * rebuild queue for it instead of making concurrent visitors wait for a page that will never be stored.
     */
    public function mark_uncacheable(string $url, bool $mobile = false): void {
        $v = $this->variant($url, $mobile);
        if ($v !== null && is_dir($v['dir']) || ($v !== null && $this->ensure_dir($v['dir']))) {
            @touch($v['dir'] . '/' . $v['base'] . '.nocache');
        }
    }

    /** True when the URL was found to be uncacheable (a crawler must not keep retrying it). */
    public function is_uncacheable(string $url, bool $mobile = false): bool {
        $v = $this->variant($url, $mobile);
        return $v !== null && is_file($v['dir'] . '/' . $v['base'] . '.nocache');
    }

    public function html_path(string $url, bool $mobile = false): ?string {
        $v = $this->variant($url, $mobile);
        return $v ? $v['dir'] . '/' . $v['base'] . '.html' : null;
    }

    /* ------------------------------------------------------------------ write */

    public function write(string $url, string $content, array $tags = [], bool $is_mobile = false): bool {
        $v = $this->variant($url, $is_mobile);
        if ($v === null || !$this->ensure_dir($v['dir'])) {
            return false;
        }

        $html_file = $v['dir'] . '/' . $v['base'] . '.html';
        $final = $content . sprintf("\n<!-- TQK WP Cache | generated %s UTC -->", gmdate('Y-m-d H:i:s'));

        if (!$this->atomic_write($html_file, $final)) {
            return false;
        }
        // Purge-all stamps the marker 1 s in the future (file times have 1 s resolution). A page built AFTER that purge must not look
        // older than the marker, or the very next visitor would rebuild it again for nothing.
        $epoch = $this->epoch();
        if ($epoch > time()) {
            @touch($html_file, $epoch);
        }

        $gz_file = $html_file . '.gz';
        if ((int)$this->config->get('gzip_compression', 1) === 1 && function_exists('gzencode')) {
            $gz = gzencode($final, 9);
            if ($gz !== false && $this->atomic_write($gz_file, $gz)) {
                // The drop-in only serves the .gz when its mtime matches the .html (guards against a stale .gz).
                @touch($gz_file, (int)@filemtime($html_file));
            }
        } else {
            @unlink($gz_file); // gzip switched off: never leave an old copy that could be served
        }

        @unlink($v['dir'] . '/' . $v['base'] . '.nocache');   // it can be cached after all
        $this->atomic_write($v['dir'] . '/' . $v['base'] . '.meta.json', wp_json_encode([
            'url'        => $url,
            'tags'       => $tags,
            'created_at' => time(),
            'is_mobile'  => $is_mobile,
        ]));

        foreach (array_unique($tags) as $tag) {
            $this->index_tag($tag, $v['rel']);
        }
        return true;
    }

    /** Write via temp file + rename so a concurrent reader never sees a half-written file. */
    public function atomic_write(string $file, string $content): bool {
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $file)) {
            // Windows cannot rename over a file another process has open.
            $ok = @copy($tmp, $file);
            @unlink($tmp);
            return $ok;
        }
        return true;
    }

    private function tag_file(string $tag): string {
        return $this->base_dir . '/_tags/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $tag) . '.idx';
    }

    /** Does any cached page currently depend on this tag? (one file_exists) */
    public function has_tag(string $tag): bool {
        return file_exists($this->tag_file($tag));
    }

    private function index_tag(string $tag, string $rel): void {
        $file = $this->tag_file($tag);
        $this->ensure_dir(dirname($file));
        @file_put_contents($file, $rel . "\n", FILE_APPEND | LOCK_EX);
    }

    /* ------------------------------------------------------------------ purge */

    /**
     * $hard = false (purge): the HTML stays on disk but is flagged stale (mtime 1). The drop-in keeps answering concurrent visitors
     * from that stale copy while ONE request rebuilds the page, so a purge on a busy site no longer makes every visitor render the page.
     * $hard = true: really delete (expired files, uninstall).
     */
    /** Pages (non-mobile) that purges made stale during this request: the refresh queue rebuilds them. url => true */
    private array $purged_urls = [];

    /** @return string[] */
    public function take_purged_urls(): array {
        $urls = array_keys($this->purged_urls);
        $this->purged_urls = [];
        return $urls;
    }

    private function remove_variant_files(string $stem, bool $hard = false): void {
        if ($hard) {
            foreach (['.html', '.html.gz', '.meta.json', '.nocache'] as $ext) {
                if (file_exists($stem . $ext)) {
                    @unlink($stem . $ext);
                }
            }
            return;
        }
        if (file_exists($stem . '.html')) {
            @touch($stem . '.html', 1);
        }
        foreach (['.html.gz', '.nocache'] as $ext) {
            if (file_exists($stem . $ext)) {
                @unlink($stem . $ext);
            }
        }
    }

    /**
     * Remove every cached variant (query string, mobile, http and https) of ONE page. Child pages are left alone.
     */
    public function purge_url(string $url): bool {
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            return false;
        }
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = self::clean_path((string)($parts['path'] ?? ''));
        if ($path === null) {
            return false;
        }

        // A URL WITH a query string names one exact page ("/?p=7"). Purging the whole folder would also remove the homepage, which
        // lives in the same folder on sites with plain permalinks.
        $qkey = false;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $qkey = $this->query_key((string)$parts['query']);
            if ($qkey === null) {
                return true;   // such a query is never cached, nothing to remove
            }
        }

        $had_page = false;
        foreach ([true, false] as $https) {
            $dir = $this->base_dir . '/' . self::host_dir($host, $https) . ($path !== '' ? '/' . $path : '');
            if (!is_dir($dir)) {
                continue;
            }
            if ($qkey !== false) {
                foreach (['', '.m'] as $mobile) {
                    $stem = $dir . '/index' . ($qkey !== '' ? '.' . $qkey : '') . $mobile;
                    if (file_exists($stem . '.html') || file_exists($stem . '.nocache')) {
                        $had_page = $had_page || (file_exists($stem . '.html') && (int)@filemtime($stem . '.html') > 1 && $mobile === '');
                        $this->remove_variant_files($stem);
                    }
                }
                continue;
            }
            foreach ((array)@scandir($dir) as $f) {
                if (strpos($f, 'index') === 0 && substr($f, -5) === '.html') {
                    $stem = $dir . '/' . substr($f, 0, -5);
                    if ($f === 'index.html' && (int)@filemtime($stem . '.html') > 1) {
                        $had_page = true;
                    }
                    $this->remove_variant_files($stem);
                }
            }
        }
        if ($had_page) {
            $this->purged_urls[$url] = true;
        }
        return true;
    }

    public function purge_by_tag(string $tag): int {
        $idx = $this->tag_file($tag);
        if (!file_exists($idx)) {
            return 0;
        }
        $lines = @file($idx, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        @unlink($idx);

        $count = 0;
        foreach (array_unique($lines) as $rel) {
            if (strpos($rel, '..') !== false) {
                continue;
            }
            $stem = $this->base_dir . '/' . $rel;
            // The index is append-only: a page that was rendered again WITHOUT this tag is still listed under it. Trust the page's own
            // record of what it showed, so a stale index line never purges a page that does not depend on the tag any more.
            $info = null;
            $meta = @file_get_contents($stem . '.meta.json');
            if ($meta !== false) {
                $info = json_decode($meta, true);
                if (is_array($info) && isset($info['tags']) && is_array($info['tags']) && !in_array($tag, $info['tags'], true)) {
                    continue;
                }
            }
            $m = @filemtime($stem . '.html');
            if ($m !== false && $m > 1) {   // already stale copies are not counted twice
                $this->remove_variant_files($stem);
                $count++;
                if (isset($info) && is_array($info) && !empty($info['url']) && empty($info['is_mobile'])) {
                    $this->purged_urls[(string)$info['url']] = true;
                }
            }
        }
        return $count;
    }

    /**
     * $hard = false: touch ONE marker file; every page older than it is stale from now on (O(1) whatever the cache size, and visitors
     * are served stale copies while pages are rebuilt one by one).
     * $hard = true: delete the whole cache directory (uninstall / "remove everything").
     */
    public function purge_all(bool $hard = false): bool {
        $remove_assets = $hard;
        if (!$hard) {
            if (!$this->ensure_dir($this->base_dir)) {
                return false;
            }
            // mtime = now + 1s: a page written in the same second as the purge must count as purged too (file times have 1 s resolution).
            return (bool)@file_put_contents($this->base_dir . '/_epoch', (string)microtime(true)) && @touch($this->base_dir . '/_epoch', time() + 1);
        }
        if (!is_dir($this->base_dir)) {
            return true;
        }
        $items = @scandir($this->base_dir);
        if ($items === false) {
            return false;
        }
        foreach (array_diff($items, ['.', '..']) as $d) {
            // Compiled CSS/JS bundles survive a normal purge (pages are rebuilt without recompiling them); Maintenance prunes old ones.
            if ($d === 'assets' && !$remove_assets) {
                continue;
            }
            $target = $this->base_dir . DIRECTORY_SEPARATOR . $d;
            if (is_dir($target)) {
                $this->delete_directory($target, true);
            } else {
                @unlink($target);
            }
        }
        return true;
    }

    /** Delete page files older than $ttl seconds. Returns how many pages were removed. */
    public function delete_expired(int $ttl): int {
        if (!is_dir($this->base_dir)) {
            return 0;
        }
        $removed = 0;
        $limit = time() - $ttl;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base_dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $p = $file->getPathname();
                if (strpos(str_replace('\\', '/', $p), '/assets/') !== false) {
                    continue;
                }
                if ($file->isFile() && substr($p, -5) === '.html' && $file->getMTime() < $limit) {   // purged copies (mtime 1) are old too
                    $this->remove_variant_files(substr($p, 0, -5), true);
                    $removed++;
                }
            }
        } catch (\Throwable $e) {
            // iterator disrupted by a concurrent purge: harmless, next run continues
        }
        return $removed;
    }

    /**
     * Walking a big cache directory is expensive, and the admin screen asks for these numbers on every load and after every action.
     * The result is kept for a minute; $fresh = true forces a new walk (explicit "refresh" buttons).
     */
    public function get_stats(bool $fresh = false): array {
        if (!$fresh && function_exists('get_transient')) {
            $cached = get_transient('tqk_cache_stats');
            if (is_array($cached)) {
                return $cached;
            }
        }
        $stats = $this->walk_stats();
        if (function_exists('set_transient')) {
            set_transient('tqk_cache_stats', $stats, 60);
        }
        return $stats;
    }

    private function walk_stats(): array {
        if (!is_dir($this->base_dir)) {
            return ['size' => 0, 'files' => 0, 'size_formatted' => '0 KB'];
        }
        $size = 0;
        $files = 0;
        $epoch = $this->epoch();
        $limit = time() - max(1, (int)$this->config->get('cache_lifespan', 10)) * 3600;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base_dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $size += $file->getSize();
                if (substr($file->getFilename(), -5) === '.html') {
                    $m = $file->getMTime();
                    if ($m > 1 && $m >= $epoch && $m >= $limit) {
                        $files++;   // only fresh pages count as "cached"
                    }
                }
            }
        } catch (\Throwable $e) {
            // iterator disrupted by a concurrent purge
        }
        return ['size' => $size, 'files' => $files, 'size_formatted' => size_format($size, 2)];
    }

    public function delete_directory(string $dir, bool $remove_root = true): bool {
        if (!is_dir($dir)) {
            return false;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach (array_diff($items, ['.', '..']) as $file) {
            $item = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($item) && !is_link($item)) {
                $this->delete_directory($item, true);
            } else {
                @unlink($item);
            }
        }
        return $remove_root ? @rmdir($dir) : true;
    }
}
