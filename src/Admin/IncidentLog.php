<?php
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;

/**
 * Incident log: when something goes wrong on a site (a fatal error in this plugin, the drop-in cannot be written, the cache folder is
 * read-only, Cloudflare refuses, the home page is not served from the cache), the support report is saved by itself in ONE fixed folder:
 *
 *     wp-content/tqk-logs/<site token>/latest.json            (the newest incident)
 *     wp-content/tqk-logs/<site token>/incident-<date>-<type>.json
 *
 * so support only needs the link to that folder. The token is derived from the site's salt: it is not a password (the files hold nothing
 * secret, Diagnostics removes tokens, keys and e-mail addresses) but it keeps the folder from being guessed, and the folder gets a deny
 * rule for directory listing. One incident of a type is recorded at most once per COOLDOWN, the newest KEEP files are kept.
 */
class IncidentLog {
    public const FOLDER   = 'tqk-logs';
    public const COOLDOWN = 21600;   // 6 hours per type
    public const KEEP     = 40;
    public const MAX_AGE  = 2592000; // 30 days
    public const OPTION   = 'tqk_cache_incident_last';

    public function init(): void
    {
        add_action('tqk_cache_incident', [$this, 'record_action'], 10, 3);
        register_shutdown_function([$this, 'on_shutdown']);
        // WordPress's own fatal-error handler runs first at shutdown and ends the request, so ours would never run: it announces the error here.
        add_filter('wp_php_error_args', [$this, 'on_wp_fatal'], 10, 2);
    }

    /* ------------------------------------------------------------------ where */

    public static function token(): string
    {
        return substr(hash_hmac('sha256', 'tqk-logs', wp_salt('auth')), 0, 24);
    }

    public static function dir(): string
    {
        return rtrim(WP_CONTENT_DIR, '/\\') . '/' . self::FOLDER . '/' . self::token();
    }

    /** The link support can open (works when the web server serves wp-content, which it does by default). */
    public static function url(string $file = 'latest.json'): string
    {
        return content_url('/' . self::FOLDER . '/' . self::token() . '/' . $file);
    }

    /* ------------------------------------------------------------------ pure helpers (unit tested) */

    /** May an incident of this type be recorded now? $last = type => unix time of the previous one. */
    public static function allowed(string $type, array $last, int $now): bool
    {
        return !isset($last[$type]) || ($now - (int)$last[$type]) >= self::COOLDOWN;
    }

    public static function slug(string $type): string
    {
        $s = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $type));
        return trim(substr($s, 0, 40), '-') ?: 'incident';
    }

    /** Delete incident files older than $max_age or beyond the newest $keep. latest.json is never touched. @return int removed */
    public static function prune(string $dir, int $keep, int $max_age, int $now): int
    {
        $files = [];
        foreach ((array)glob(rtrim($dir, '/\\') . '/incident-*.json') as $f) {
            $files[$f] = (int)@filemtime($f);
        }
        arsort($files);
        $removed = 0;
        $i = 0;
        foreach ($files as $f => $mtime) {
            if ($i++ >= $keep || ($now - $mtime) > $max_age) {
                if (@wp_delete_file($f)) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    /* ------------------------------------------------------------------ recording */

    public function record_action($type, $message = '', $context = []): void
    {
        self::record((string)$type, (string)$message, (array)$context);
    }

    /**
     * Save a report for this incident. Never throws and never blocks the request for long (no network probe unless asked).
     * @return string|null path of the file, or null when it was skipped (cooldown) or could not be written
     */
    public static function record(string $type, string $message, array $context = [], bool $probe = false): ?string
    {
        try {
            $last = get_option(self::OPTION, []);
            $last = is_array($last) ? $last : [];
            $now = time();
            if (!self::allowed($type, $last, $now)) {
                return null;
            }
            $dir = self::dir();
            if (!self::prepare($dir)) {
                return null;
            }
            $last[$type] = $now;
            update_option(self::OPTION, $last, false);

            $report = (new Diagnostics())->collect($probe);
            $report['incident'] = Diagnostics::redact(['type' => $type, 'message' => $message, 'context' => $context, 'time' => gmdate('c', $now)]);
            $json = wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $file = $dir . '/incident-' . gmdate('Ymd-His', $now) . '-' . self::slug($type) . '.json';
            if (@file_put_contents($file, $json, LOCK_EX) === false) {
                return null;
            }
            @file_put_contents($dir . '/latest.json', $json, LOCK_EX);
            self::prune($dir, self::KEEP, self::MAX_AGE, $now);
            return $file;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Create the folder with a deny rule and an index file, once. */
    private static function prepare(string $dir): bool
    {
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }
        $root = dirname($dir);
        if (!is_file($root . '/index.html')) {
            @file_put_contents($root . '/index.html', '');
        }
        if (!is_file($root . '/.htaccess')) {
            @file_put_contents($root . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\n  Require all denied\n</FilesMatch>\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions
        return is_writable($dir);
    }

    /** A fatal error in this plugin (or its add-on) ends the request: record it from the shutdown handler. */
    public function on_shutdown(): void
    {
        $this->capture(error_get_last());
    }

    public function on_wp_fatal($args, $error = null)
    {
        $this->capture(is_array($error) ? $error : null);
        return $args;
    }

    private function capture(?array $e): void
    {
        if (!$e || !in_array($e['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            return;
        }
        $file = str_replace(chr(92), '/', (string)($e['file'] ?? ''));
        if (strpos($file, '/tqk-wp-cache/') === false && strpos($file, '/tqk-wp-cache-pro/') === false) {
            return;
        }
        self::record('fatal', (string)($e['message'] ?? ''), ['file' => basename($file), 'line' => $e['line'] ?? 0, 'uri' => (string)($_SERVER['REQUEST_URI'] ?? '')]);
    }

    /* ------------------------------------------------------------------ daily health check */

    /** Called once a day by the maintenance job: record what is wrong, if anything. */
    public static function health_check(): void
    {
        $status = Dropin::status();
        if ($status === 'foreign' || $status === 'missing') {
            self::record('dropin-' . $status, 'The page-cache drop-in is ' . $status . '.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions
        if (is_dir(TQK_WP_CACHE_STORAGE_DIR) && !is_writable(TQK_WP_CACHE_STORAGE_DIR)) {
            self::record('storage-readonly', 'The cache folder is not writable.', ['dir' => TQK_WP_CACHE_STORAGE_DIR]);
        }
        $cfg = \TQK_WP_CACHE\Core\Config::instance();
        if ($cfg->get('page_cache') && $status === 'ok') {
            $res = wp_remote_get(home_url('/'), ['timeout' => 8, 'sslverify' => (bool)apply_filters('tqk_cache_warmup_sslverify', false), 'redirection' => 0]);
            if (is_wp_error($res)) {
                self::record('home-unreachable', $res->get_error_message());
            } elseif (wp_remote_retrieve_header($res, 'x-tqk-cache') === '') {
                self::record('home-not-cached', 'The home page answers without the X-TQK-Cache header (a server rule or another plugin may be answering first).', ['status' => wp_remote_retrieve_response_code($res)]);
            }
        }
    }
}