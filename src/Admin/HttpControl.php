<?php
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;

defined('ABSPATH') || exit;

/**
 * Outgoing HTTP requests made while a wp-admin page is being built.
 *
 * Every page of a typical admin waits for a few of them one after the other: an update-check of some theme, the tracking pixel of a
 * shop plugin, the loop-back request that starts WP-Cron, the list of translations. On the demo site that was ~1.1 s of a ~4 s page and
 * the database only ~40 ms. Three optional, independent measures (all OFF by default):
 *
 *  - block telemetry: tracking pixels are answered with an error at once (the callers ignore it);
 *  - cache feeds: GET answers of a short list of harmless endpoints are kept for a few hours;
 *  - skip the cron loop-back on admin page views (the front end and real cron still run WP-Cron).
 *
 * Never touched: the WordPress core / plugin / theme update checks (api.wordpress.org/core, /plugins, /themes). Hiding a security
 * update is not a trade worth making, and WordPress already caches those.
 */
class HttpControl {
    /** Tracking only. */
    public const TELEMETRY = ['pixel.wp.com', 'stats.wp.com'];
    /** Harmless, rarely changing GET endpoints. A rule is "host" or "host/path-prefix". */
    public const CACHEABLE = ['envato.github.io', 'api.wordpress.org/translations/', 'api.wordpress.org/events/'];

    private const MAX_BODY = 1048576;

    private Config $config;

    public function __construct() {
        $this->config = Config::instance();
    }

    public function init(): void {
        $block = (int)$this->config->get('admin_http_block_telemetry', 0) === 1 || trim((string)$this->config->get('admin_http_extra_block', '')) !== '';
        $cache = (int)$this->config->get('admin_http_cache', 0) === 1;
        $cron  = (int)$this->config->get('admin_http_skip_cron', 0) === 1;
        if (!$block && !$cache && !$cron) {
            return;
        }
        add_filter('pre_http_request', [$this, 'pre'], 5, 3);
        if ($cache) {
            add_filter('http_response', [$this, 'store'], 10, 3);
        }
    }

    /* ------------------------------------------------------------------ pure helpers (unit tested) */

    /** @return string[] one rule per non-empty line, lower case, without scheme. */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', strtolower($text)) as $line) {
            $line = trim(preg_replace('~^[a-z]+://~', '', trim($line)));
            if ($line !== '' && $line[0] !== '#') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /** Does the URL match one of the rules ("host" = that host or a sub-domain of it, "host/prefix" = that host and path prefix)? */
    public static function matches(string $url, array $rules): bool
    {
        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        $path = ltrim((string)($parts['path'] ?? ''), '/');
        foreach ($rules as $rule) {
            $rule = strtolower(trim((string)$rule));
            if ($rule === '') {
                continue;
            }
            $pos = strpos($rule, '/');
            $rule_host = $pos === false ? $rule : substr($rule, 0, $pos);
            $rule_path = $pos === false ? '' : ltrim(substr($rule, $pos), '/');
            if ($host !== $rule_host && substr($host, -strlen('.' . $rule_host)) !== '.' . $rule_host) {
                continue;
            }
            if ($rule_path === '' || strncmp($path, $rule_path, strlen($rule_path)) === 0) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------------ filters */

    private function rules(string $builtin_flag, array $builtin, string $extra_key): array
    {
        $rules = (int)$this->config->get($builtin_flag, 0) === 1 ? $builtin : [];
        return array_merge($rules, self::lines((string)$this->config->get($extra_key, '')));
    }

    public function pre($pre, $args, $url)
    {
        if ($pre !== false || !is_string($url)) {
            return $pre;
        }
        $method = strtoupper((string)($args['method'] ?? 'GET'));

        // 1. The loop-back that starts WP-Cron.
        if ((int)$this->config->get('admin_http_skip_cron', 0) === 1 && strpos($url, 'wp-cron.php') !== false && self::matches($url, [(string)wp_parse_url(site_url(), PHP_URL_HOST)])) {
            return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null, 'http_response' => null];
        }

        // 2. Tracking pixels.
        if (self::matches($url, $this->rules('admin_http_block_telemetry', self::TELEMETRY, 'admin_http_extra_block'))) {
            return new \WP_Error('tqk_blocked', 'Blocked by TQK WP Cache (admin telemetry).');
        }

        // 3. Cached feeds (GET only, no credentials).
        if ($method === 'GET' && (int)$this->config->get('admin_http_cache', 0) === 1 && empty($args['headers']['Authorization'])
            && self::matches($url, array_merge(self::CACHEABLE, self::lines((string)$this->config->get('admin_http_extra_cache', ''))))) {
            $hit = get_transient($this->key($url));
            if (is_array($hit) && isset($hit['body'])) {
                return ['headers' => $hit['headers'], 'body' => $hit['body'], 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null, 'http_response' => null];
            }
        }
        return $pre;
    }

    public function store($response, $args, $url)
    {
        try {
            if (is_wp_error($response) || !is_string($url) || strtoupper((string)($args['method'] ?? 'GET')) !== 'GET' || empty($args['blocking']) && array_key_exists('blocking', $args)) {
                return $response;
            }
            if (!empty($args['headers']['Authorization']) || !self::matches($url, array_merge(self::CACHEABLE, self::lines((string)$this->config->get('admin_http_extra_cache', ''))))) {
                return $response;
            }
            $body = wp_remote_retrieve_body($response);
            if (wp_remote_retrieve_response_code($response) !== 200 || $body === '' || strlen($body) > self::MAX_BODY) {
                return $response;
            }
            $headers = wp_remote_retrieve_headers($response);
            $headers = is_object($headers) && method_exists($headers, 'getAll') ? $headers->getAll() : (array)$headers;
            $hours = max(1, min(72, (int)$this->config->get('admin_http_cache_ttl', 12)));
            set_transient($this->key($url), ['headers' => $headers, 'body' => $body], $hours * HOUR_IN_SECONDS);
        } catch (\Throwable $e) {
            // A cache that fails must never break the request it wraps.
        }
        return $response;
    }

    private function key(string $url): string
    {
        return 'tqk_http_' . md5($url);
    }
}
