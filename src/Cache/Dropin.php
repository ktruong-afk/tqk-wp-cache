<?php
namespace TQK_WP_CACHE\Cache;

use TQK_WP_CACHE\Core\Config;

defined('ABSPATH') || exit;

/**
 * Builds, installs and verifies wp-content/advanced-cache.php.
 *
 * The generated file runs BEFORE WordPress loads, so it cannot call any plugin code: every setting it needs (storage dir, lifespan,
 * query allow-list, cookies that must bypass the cache...) is baked in as a PHP array when the file is generated. Settings changes
 * regenerate it (see Dropin::sync()). The request -> cache-file mapping here MUST stay identical to DiskStorage::variant();
 * tests/run.php checks both against the same inputs.
 */
class Dropin {
    public const VERSION = '3';
    public const MARKER  = 'TQK_WP_CACHE_DROPIN';

    /** Cookies (prefix match) that mean "this visitor sees personalised HTML": never serve from / write to the page cache. */
    public const BYPASS_COOKIES = [
        'wordpress_logged_in_', 'wordpress_sec_', 'wp-postpass_', 'comment_author_',
        'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_',
    ];

    /** Query keys that never change the page (tracking). Ignored when building the cache key. */
    public const IGNORED_QUERY_EXACT  = ['fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'mc_cid', 'mc_eid', '_ga', '_gl', 'igshid', 'yclid', 'ttclid', 'dclid'];
    public const IGNORED_QUERY_PREFIX = ['utm_', 'tqk_'];

    /** Paths that are never cached (matched against "/path/"). */
    public const BYPASS_PATHS = ['/wp-admin/', '/wp-login.php', '/xmlrpc.php', '/wp-cron.php', '/wp-json/', '/feed/', '/wp-comments-post.php', '/wp-trackback.php'];

    public static function get_dropin_path(): string {
        return WP_CONTENT_DIR . '/advanced-cache.php';
    }

    /** All settings the drop-in needs, as a plain array (also used by tests). */
    public static function runtime_config(?Config $config = null): array {
        $config = $config ?: Config::instance();
        $cookies = array_merge(self::BYPASS_COOKIES, $config->lines('excluded_cookies'));
        $cookies = apply_filters('tqk_cache_bypass_cookies', $cookies);

        return [
            'dir'           => TQK_WP_CACHE_STORAGE_DIR,
            'ttl'           => max(1, (int)$config->get('cache_lifespan', 10)) * 3600,
            'mobile'        => (int)$config->get('cache_mobile', 0) === 1,
            'cookies'       => array_values(array_unique(array_map('strval', $cookies))),
            'bypass_paths'  => self::bypass_needles($config),
            'query_allow'   => self::query_allow($config),
            'ignore_exact'  => self::IGNORED_QUERY_EXACT,
            'ignore_prefix' => self::IGNORED_QUERY_PREFIX,
            'member_paths'  => (array)apply_filters('tqk_cache_member_paths', []),   // add-on (PRO): paths where plain members get the shared copy
            'stale_max'     => 86400,   // a purged / expired copy may be served to concurrent visitors for this long while ONE request rebuilds it
            'slots'         => (int)apply_filters('tqk_cache_max_parallel_builds', 4),   // pages being rebuilt at the same moment, site wide
            'wait_ms'       => 800,     // cold page: how long a concurrent visitor sleeps (no CPU) waiting for the builder
        ];
    }

    /**
     * Query keys that get their own cache entry: the configured list PLUS what WordPress itself uses to address content on a site
     * with "plain" permalinks (?p=, ?page_id=, ?product=slug, ?product_cat=slug ...). Without the automatic part a plain-permalink
     * shop would never cache a single product page.
     */
    public static function query_allow(Config $config): array {
        static $memo = [];
        $memo_key = (string)$config->get('cache_query_allow', '');
        if (isset($memo[$memo_key])) {
            return $memo[$memo_key];
        }
        $keys = $config->lines('cache_query_allow');
        $keys = array_merge($keys, ['p', 'page_id', 'name', 'pagename', 'cat', 'category_name', 'tag', 'tag_id', 'author', 'author_name',
            'year', 'monthnum', 'day', 'm', 'w', 'paged', 'page', 'cpage', 'attachment_id', 'taxonomy', 'term', 'post_type']);

        if (function_exists('get_post_types')) {
            foreach (get_post_types(['public' => true], 'objects') as $type) {
                if (!empty($type->query_var) && is_string($type->query_var)) {
                    $keys[] = $type->query_var;
                }
            }
        }
        if (function_exists('get_taxonomies')) {
            foreach (get_taxonomies(['public' => true], 'objects') as $tax) {
                if (!empty($tax->query_var) && is_string($tax->query_var)) {
                    $keys[] = $tax->query_var;
                }
            }
        }
        return $memo[$memo_key] = array_values(array_unique(apply_filters('tqk_cache_query_allow', $keys)));
    }

    /**
     * Needles matched against "/<path>/". User exclusions like "/cart" become "/cart/" so they only match the whole path segment
     * (the old substring match also excluded /cartoons/). Needles containing "?" or "=" are matched against the raw URI instead.
     */
    public static function bypass_needles(Config $config): array {
        $needles = self::BYPASS_PATHS;
        foreach ($config->lines('excluded_urls') as $line) {
            if (strpos($line, '?') === false && strpos($line, '=') === false && substr($line, -1) !== '/' && strpos(basename($line), '.') === false) {
                $line .= '/';
            }
            $needles[] = ($line[0] ?? '') === '/' ? $line : '/' . $line;
        }
        return array_values(array_unique($needles));
    }

    public static function path_is_bypassed(string $path, string $uri, array $needles): bool {
        $haystack = '/' . trim($path, '/') . '/';
        foreach ($needles as $needle) {
            if (strpbrk($needle, '?=') !== false) {
                if (stripos($uri, $needle) !== false) {
                    return true;
                }
            } elseif (stripos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Install (or refresh) the drop-in. Returns 'ok', 'foreign' (another plugin owns advanced-cache.php) or 'error'.
     */
    public static function install(bool $force = false): string {
        self::remove_legacy_backup();
        $path = self::get_dropin_path();

        if (file_exists($path) && !$force) {
            $current = (string)@file_get_contents($path);
            if ($current !== '' && strpos($current, self::MARKER) === false && strpos($current, '<?php') !== false && trim(preg_replace('#<\?php|/\*.*?\*/|//[^\n]*#s', '', $current)) !== '') {
                return 'foreign';
            }
        }

        $code = self::generate_dropin_code();
        $tmp  = $path . '.tmp.' . uniqid('', true);
        if (@file_put_contents($tmp, $code, LOCK_EX) === false) {
            return 'error';
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            if (@file_put_contents($path, $code, LOCK_EX) === false) {
                return 'error';
            }
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        self::ensure_wp_cache_constant(true);
        return 'ok';
    }

    /** Rewrite the file only when its content would change (cheap enough to call from admin_init). */
    public static function sync(): void {
        self::remove_legacy_backup();
        $path = self::get_dropin_path();
        if (!file_exists($path)) {
            return;
        }
        $current = (string)@file_get_contents($path);
        if (strpos($current, self::MARKER) === false) {
            return; // not ours
        }
        if ($current !== self::generate_dropin_code()) {
            self::install();
        }
    }

    public static function uninstall(): bool {
        $path = self::get_dropin_path();
        if (file_exists($path) && strpos((string)@file_get_contents($path), self::MARKER) !== false) {
            @unlink($path);
        }
        self::ensure_wp_cache_constant(false);
        return true;
    }

    public static function is_active(): bool {
        $path = self::get_dropin_path();
        return file_exists($path) && strpos((string)@file_get_contents($path), self::MARKER) !== false;
    }

    /** 'ok' | 'missing' | 'foreign' | 'outdated' */
    public static function status(): string {
        $path = self::get_dropin_path();
        if (!file_exists($path)) {
            return 'missing';
        }
        $content = (string)@file_get_contents($path);
        if (strpos($content, self::MARKER) === false) {
            return 'foreign';
        }
        return $content === self::generate_dropin_code() ? 'ok' : 'outdated';
    }

    private static function ensure_wp_cache_constant(bool $enable): void {
        $wp_config = ABSPATH . 'wp-config.php';
        if (!file_exists($wp_config) || !is_writable($wp_config)) {
            $wp_config = dirname(ABSPATH) . '/wp-config.php'; // Bedrock / custom layouts
            if (!file_exists($wp_config) || !is_writable($wp_config)) {
                return;
            }
        }

        $content = file_get_contents($wp_config);
        if ($content === false) {
            return;
        }

        $marker  = '// Added by TQK WP Cache';
        $pattern = "/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*(true|false)\s*\)\s*;[^\n]*/i";
        $new     = $content;

        if ($enable) {
            if (preg_match($pattern, $content, $m)) {
                if (strtolower($m[1]) === 'true') {
                    return; // already on (not ours, leave it alone)
                }
                $new = preg_replace($pattern, "define('WP_CACHE', true); $marker", $content, 1);
            } else {
                $new = preg_replace('/^<\?php/i', "<?php\ndefine('WP_CACHE', true); $marker", $content, 1);
            }
        } else {
            // Only undo what we added.
            $new = preg_replace('/^[ \t]*define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)\s*;\s*' . preg_quote($marker, '/') . '[^\n]*\n?/mi', '', $content);
        }

        if ($new === null || $new === $content) {
            return;
        }

        // One-time backup, then atomic replace so a crash mid-write cannot leave a half-written wp-config.php.
        self::write_config_backup($wp_config, $content);
        $tmp = dirname($wp_config) . '/wp-config.tqk-tmp.php';
        if (@file_put_contents($tmp, $new, LOCK_EX) !== false && @rename($tmp, $wp_config)) {
            return;
        }
        @unlink($tmp);
        @file_put_contents($wp_config, $new, LOCK_EX);
    }

    /**
     * Copy of wp-config.php made before WP_CACHE is added. It holds the database password and the salts, so it must NOT be a plain
     * file such as "wp-config.php.tqk-backup": a web server serves that as text to anybody (version 1.0 did exactly that).
     * It is a PHP file whose first statement is exit, mode 0600: requesting it returns an empty page, and the original is below the guard.
     */
    private static function write_config_backup(string $wp_config, string $content): void
    {
        $backup = dirname($wp_config) . '/wp-config.tqk-backup.php';
        if (file_exists($backup)) {
            return;
        }
        $guard = "<?php\nexit; // TQK WP Cache: copy of wp-config.php made before WP_CACHE was added. To restore it, delete these two lines and the next one.\n?>\n";
        if (@file_put_contents($backup, $guard . $content, LOCK_EX) !== false) {
            @chmod($backup, 0600);
        }
    }

    /** Version 1.0 left "wp-config.php.tqk-backup" (readable over HTTP, with the database password). Convert it to the safe form and delete it. */
    public static function remove_legacy_backup(): void
    {
        foreach (array_unique([ABSPATH, trailingslashit(dirname(untrailingslashit(ABSPATH)))]) as $dir) {
            $old = $dir . 'wp-config.php.tqk-backup';
            if (!is_file($old)) {
                continue;
            }
            $content = (string)@file_get_contents($old);
            if ($content !== '') {
                self::write_config_backup($dir . 'wp-config.php', $content);
            }
            @unlink($old);
            @unlink($dir . 'wp-config.php.tqk-tmp');
        }
    }

    public static function generate_dropin_code(): string {
        $cfg = var_export(self::runtime_config(), true);
        $version = self::VERSION;
        $marker  = self::MARKER;

        $head = "<?php\n/**\n * TQK WP Cache drop-in (v{$version}) - generated, do not edit. Regenerated whenever the plugin settings change.\n * Defines: {$marker}\n */\n"
            . "defined('ABSPATH') || exit;\n\ndefine('{$marker}', true);\ndefine('TQK_WP_CACHE_DROPIN_VERSION', '{$version}');\n\n\$tqk_cache_cfg = {$cfg};\n\n";

        $body = <<<'PHP'
(function () use ($tqk_cache_cfg) {
    $cfg = $tqk_cache_cfg;

    // Only plain GET page views. Anything else must reach WordPress.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    if (defined('DOING_CRON') || defined('DOING_AJAX') || defined('XMLRPC_REQUEST') || defined('WP_INSTALLING') || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    if (!empty($_SERVER['PHP_AUTH_USER']) || !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['HTTP_X_TQK_WARMUP'])) {
        return;
    }
    // "Load more" / infinite scroll that asks for the SAME url with an XMLHttpRequest header: many themes answer that with a fragment.
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        return;
    }

    // Personalised visitors (logged in, password-protected post unlocked, commenter, cart...) never get the shared copy.
    // One exception (PRO, listed paths only): a plain member whose `tqk_member` cookie is set, see the check below once the path is known.
    $memberCandidate = false;
    foreach ($_COOKIE as $name => $value) {
        foreach ($cfg['cookies'] as $prefix) {
            if (strpos((string)$name, $prefix) === 0) {
                if (!empty($cfg['member_paths']) && ($prefix === 'wordpress_logged_in_' || $prefix === 'wordpress_sec_')) {
                    $memberCandidate = true;
                    continue 2;
                }
                return;
            }
        }
    }

    $uri   = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path  = (string)parse_url($uri, PHP_URL_PATH);
    $query = (string)parse_url($uri, PHP_URL_QUERY);
    if ($path === '' || $path[0] !== '/') {
        return;
    }
    $path = trim($path, '/');

    if ($memberCandidate) {
        $listed = false;
        if (($_COOKIE['tqk_member'] ?? '') === '1') {
            $p = '/' . $path . ($path !== '' ? '/' : '');
            foreach ($cfg['member_paths'] as $mp) {
                if (substr($mp, -1) === '*' ? strncmp($p, substr($mp, 0, -1), strlen($mp) - 1) === 0 : $p === $mp) {
                    $listed = true;
                    break;
                }
            }
        }
        if (!$listed) {
            return;   // staff, or a page that is not on the list: the live page
        }
    }

    // The path becomes part of a file path: allow only safe characters and no dot segments.
    if ($path !== '') {
        $decoded = rawurldecode($path);
        if (strpos($path, '//') !== false || preg_match('#[^A-Za-z0-9/_\-.%~+,;=@!$&\'()]#', $path)
            || preg_match('#(^|/)\.\.?(/|$)#', $decoded) || strpos($decoded, "\0") !== false || strpos($decoded, '\\') !== false) {
            return;
        }
    }

    $haystack = '/' . $path . '/';
    foreach ($cfg['bypass_paths'] as $needle) {
        if (strpbrk($needle, '?=') !== false) {
            if (stripos($uri, $needle) !== false) {
                return;
            }
        } elseif (stripos($haystack, $needle) !== false) {
            return;
        }
    }

    // Query string: tracking keys are ignored, allow-listed keys get their own entry, anything else bypasses the cache.
    $qkey = '';
    if ($query !== '') {
        parse_str($query, $qs);
        foreach ($qs as $k => $v) {
            $k = (string)$k;
            if (in_array($k, $cfg['ignore_exact'], true)) {
                unset($qs[$k]);
                continue;
            }
            foreach ($cfg['ignore_prefix'] as $p) {
                if (strpos($k, $p) === 0) {
                    unset($qs[$k]);
                    continue 2;
                }
            }
            if (!in_array($k, $cfg['query_allow'], true) || !is_scalar($v)) {
                return;
            }
        }
        if ($qs) {
            ksort($qs);
            $qkey = md5(http_build_query($qs));
        }
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    $host  = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || preg_match('/[^a-z0-9.\-:\[\]]/', $host)) {
        return;
    }
    $hostdir = str_replace([':', '[', ']'], '_', $host) . ($https ? '' : '__http');

    $mobile = !empty($cfg['mobile']) && preg_match('/Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi/', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

    $root = $cfg['dir'];
    $dir  = $root . '/' . $hostdir . ($path !== '' ? '/' . $path : '');
    $base = 'index' . ($qkey !== '' ? '.q' . $qkey : '') . ($mobile ? '.m' : '');
    $html = $dir . '/' . $base . '.html';

    $now   = time();
    $epoch = (int)@filemtime($root . '/_epoch');          // "purge everything" = touch this one file (O(1), no mass deletion)
    $mtime = @filemtime($html);
    // mtime 1 = purged (kept on disk as a stale copy); older than the epoch = purged by "purge all"; older than ttl = expired.
    $fresh = $mtime !== false && $mtime > 1 && $mtime >= $epoch && ($now - $mtime) <= $cfg['ttl'];

    $send = static function ($file, $stale) use ($mtime, $cfg) {
        $m = @filemtime($file);
        if ($m === false) {
            return;
        }
        $isGz = false;
        if (!$stale && strpos((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false) {
            $gzTime = @filemtime($file . '.gz');
            if ($gzTime !== false && abs($gzTime - $m) <= 2) {
                $file .= '.gz';
                $isGz = true;
            }
        }
        $size = @filesize($file);
        if ($size === false) {
            return;
        }
        header('X-TQK-Cache: ' . ($stale ? 'STALE' : 'HIT'));
        header('Content-Type: text/html; charset=UTF-8');
        header('Vary: Accept-Encoding');
        if ($stale) {
            header('Cache-Control: no-cache, must-revalidate, max-age=0');   // browsers must not keep a stale copy
        } else {
            $etag = '"' . dechex($m) . '-' . dechex($size) . ($isGz ? 'g' : '') . '"';
            header('Cache-Control: public, max-age=0, must-revalidate');
            header('ETag: ' . $etag);
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $m) . ' GMT');
            $inm = preg_replace('#^W/#', '', trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')));
            if ($inm !== '' && $inm === $etag) {
                http_response_code(304);
                exit;
            }
        }
        if ($isGz) {
            header('Content-Encoding: gzip');
        }
        header('Content-Length: ' . $size);
        readfile($file);
        exit;
    };

    if ($fresh) {
        $send($html, false);
        return;
    }

    // ---- Not fresh. STAMPEDE PROTECTION: only ONE request per page (and only a few site wide) may rebuild it.
    // Without this, every visitor arriving right after a purge renders the same page at the same time and the CPU is saturated.
    $nc = @filemtime($dir . '/' . $base . '.nocache');
    if ($nc !== false && ($now - $nc) < 300) {
        return;   // this URL is known to be uncacheable: no point queueing visitors behind it
    }
    $staleOk = $mtime !== false && ($mtime <= 1 || ($now - $mtime) <= $cfg['stale_max']);

    $lockDir = $root . '/_locks';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0777, true);
    }
    $lock = @fopen($lockDir . '/' . md5($html) . '.lock', 'c');
    if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
        // We are the builder. Respect the site-wide limit of parallel builds.
        $slot = null;
        for ($i = 0; $i < $cfg['slots']; $i++) {
            $s = @fopen($lockDir . '/slot' . $i . '.lock', 'c');
            if ($s && flock($s, LOCK_EX | LOCK_NB)) {
                $slot = $s;
                break;
            }
            if ($s) {
                fclose($s);
            }
        }
        if ($slot === null && $staleOk) {
            flock($lock, LOCK_UN);
            fclose($lock);
            $send($html, true);   // too many builds already running: answer from the stale copy
            return;
        }
        $GLOBALS['tqk_cache_locks'] = [$lock, $slot];   // held until the request ends, then released by PHP
        return;                                         // fall through to WordPress: this request rebuilds the page
    }
    if ($lock) {
        fclose($lock);
    }

    // Somebody else is already rebuilding this page.
    if ($staleOk) {
        $send($html, true);
        return;
    }
    $deadline = microtime(true) + $cfg['wait_ms'] / 1000;
    while (microtime(true) < $deadline) {
        usleep(50000);   // sleeping costs no CPU
        clearstatcache(true, $html);
        $m = @filemtime($html);
        if ($m !== false && $m > 1 && $m >= $epoch) {
            $mtime = $m;
            $send($html, false);
            break;
        }
    }
})();

PHP;

        return $head . $body;
    }
}
