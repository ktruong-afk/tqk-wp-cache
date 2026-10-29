<?php
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;
use TQK_WP_CACHE\Core\Config;

defined('ABSPATH') || exit;

/**
 * Support report: one JSON file with everything needed to understand a problem on somebody else's site (versions, drop-in state,
 * theme + builder, other cache plugins, settings, last log lines), with secrets removed. The customer downloads it and sends it; the
 * add-on may append its own sections through the `tqk_cache_diagnostics` filter.
 */
class Diagnostics {
    public const ACTION = 'tqk_cache_diagnostics';

    /** Settings whose value must never leave the site. */
    private const SECRET = '/token|secret|password|passwd|licen[cs]e|api[_-]?key|authorization|auth[_-]?key|salt/i';

    public function init(): void {
        add_action('wp_ajax_' . self::ACTION, [$this, 'ajax']);
    }

    public function ajax(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }
        wp_send_json_success(['report' => $this->collect(), 'filename' => 'tqk-report-' . gmdate('Ymd-His') . '.json']);
    }

    /** Replace secret values, recursively. Public and pure so it can be unit tested. */
    public static function redact($data, string $key = '')
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = self::redact($v, (string)$k);
            }
            return $out;
        }
        if ($key !== '' && preg_match(self::SECRET, $key) && $data !== '' && $data !== null && $data !== 0 && $data !== false) {
            return '***';
        }
        if (is_string($data)) {
            // an e-mail address inside any text is personal data
            return preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '***@***', $data);
        }
        return $data;
    }

    /** Known cache / optimisation plugins that fight over the same files. */
    public const OTHER_CACHE = [
        'wp-rocket', 'w3-total-cache', 'litespeed-cache', 'wp-super-cache', 'wp-fastest-cache', 'cache-enabler', 'sg-cachepress', 'breeze',
        'hummingbird-performance', 'flying-press', 'nitropack', 'autoptimize', 'redis-cache', 'wp-optimize', 'comet-cache', 'swift-performance',
    ];

    /** @return array<string,mixed> */
    public function collect(bool $probe = true): array
    {
        global $wp_version;
        $config = Config::instance();
        $plugins = [];
        $slugs = [];
        foreach ((array)get_option('active_plugins', []) as $file) {
            $slug = strtok((string)$file, '/');
            $slugs[] = $slug;
            $plugins[] = $slug . ' ' . ($this->plugin_version((string)$file) ?: '?');
        }

        $report = [
            'generated'  => gmdate('c'),
            'plugin'     => ['version' => defined('TQK_WP_CACHE_VERSION') ? TQK_WP_CACHE_VERSION : '', 'api' => defined('TQK_WP_CACHE_API') ? TQK_WP_CACHE_API : 0, 'pro' => $config->is_pro()],
            'site'       => [
                'host'       => (string)wp_parse_url(home_url(), PHP_URL_HOST),
                'https'      => is_ssl(),
                'wp'         => $wp_version ?? '',
                'php'        => PHP_VERSION,
                'sapi'       => PHP_SAPI,
                'server'     => (string)($_SERVER['SERVER_SOFTWARE'] ?? ''),
                'memory'     => ini_get('memory_limit'),
                'multisite'  => is_multisite(),
                'locale'     => get_locale(),
                'permalinks' => (string)get_option('permalink_structure', '') ?: '(plain)',
                'wp_debug'   => defined('WP_DEBUG') && WP_DEBUG,
                'wp_cron_off' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            ],
            'theme'      => $this->theme(),
            'builders'   => $this->builders(),
            'plugins'    => $plugins,
            'conflicts'  => array_values(array_intersect(self::OTHER_CACHE, $slugs)),
            'dropin'     => [
                'status'          => Dropin::status(),
                'active'          => Dropin::is_active(),
                'wp_cache_const'  => defined('WP_CACHE') && WP_CACHE,
                // phpcs:ignore WordPress.WP.AlternativeFunctions
                'content_writable' => is_writable(WP_CONTENT_DIR),
                'cache_dir_exists' => is_dir(TQK_WP_CACHE_STORAGE_DIR),
                // phpcs:ignore WordPress.WP.AlternativeFunctions
                'cache_dir_writable' => is_dir(TQK_WP_CACHE_STORAGE_DIR) ? is_writable(TQK_WP_CACHE_STORAGE_DIR) : null,
                'object_cache'    => wp_using_ext_object_cache(),
                'conflict_flag'   => (bool)get_option('tqk_cache_dropin_conflict', 0),
            ],
            'cache'      => (new DiskStorage())->get_stats(true),
            'cron'       => $this->cron(),
            'cloudflare' => get_option('tqk_cache_cf_last', null),
            'front_page' => $probe ? $this->probe_front_page() : null,
            'log'        => $this->log_tail(),
            'settings'   => $config->all(),
        ];

        // Add-ons add their own sections (and may redact nothing themselves: everything below is redacted here).
        $report = (array)apply_filters('tqk_cache_diagnostics', $report);
        return self::redact($report);
    }

    private function plugin_version(string $file): string
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $path = WP_PLUGIN_DIR . '/' . $file;
        return is_readable($path) ? (string)(get_plugin_data($path, false, false)['Version'] ?? '') : '';
    }

    private function theme(): array
    {
        $t = wp_get_theme();
        return ['name' => $t->get('Name'), 'version' => $t->get('Version'), 'slug' => $t->get_stylesheet(), 'parent' => $t->parent() ? $t->parent()->get_stylesheet() : '', 'block_theme' => function_exists('wp_is_block_theme') && wp_is_block_theme()];
    }

    /** Page builders / shop plugins whose output the fragment scanner has to understand. */
    private function builders(): array
    {
        $checks = [
            'elementor' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
            'divi'      => defined('ET_BUILDER_VERSION') ? ET_BUILDER_VERSION : null,
            'wpbakery'  => defined('WPB_VC_VERSION') ? WPB_VC_VERSION : null,
            'beaver'    => defined('FL_BUILDER_VERSION') ? FL_BUILDER_VERSION : null,
            'oxygen'    => defined('CT_VERSION') ? CT_VERSION : null,
            'bricks'    => defined('BRICKS_VERSION') ? BRICKS_VERSION : null,
            'breakdance' => defined('__BREAKDANCE_VERSION') ? __BREAKDANCE_VERSION : null,
            'woocommerce' => defined('WC_VERSION') ? WC_VERSION : null,
            'flatsome'  => get_template() === 'flatsome' ? wp_get_theme('flatsome')->get('Version') : null,
        ];
        return array_filter($checks, static fn($v) => $v !== null);
    }

    private function cron(): array
    {
        $out = [];
        foreach (['tqk_wp_cache_daily', 'tqk_cache_cf_run', 'tqk_cache_refresh_run', 'tqk_counter_flush'] as $hook) {
            $next = wp_next_scheduled($hook);
            if ($next) {
                $out[$hook] = $next - time();
            }
        }
        return $out;
    }

    /** Ask the home page like a visitor: is it answered from the cache, how fast, with which headers? */
    private function probe_front_page(): array
    {
        $t = microtime(true);
        $res = wp_remote_get(home_url('/'), ['timeout' => 8, 'sslverify' => (bool)apply_filters('tqk_cache_warmup_sslverify', false), 'redirection' => 0, 'headers' => ['Accept-Encoding' => 'identity']]);
        if (is_wp_error($res)) {
            return ['error' => $res->get_error_message()];
        }
        $h = wp_remote_retrieve_headers($res);
        return [
            'status'  => wp_remote_retrieve_response_code($res),
            'ms'      => (int)round((microtime(true) - $t) * 1000),
            'x_tqk_cache' => (string)($h['x-tqk-cache'] ?? ''),
            'cache_control' => (string)($h['cache-control'] ?? ''),
            'set_cookie' => isset($h['set-cookie']),
            'server'  => (string)($h['server'] ?? ''),
            'cf_cache' => (string)($h['cf-cache-status'] ?? ''),
        ];
    }

    /** The last lines of the PHP error log that mention this plugin (at most 25, each cut to 300 characters). */
    private function log_tail(): array
    {
        $file = (string)ini_get('error_log');
        if ($file === '' || !is_readable($file) || filesize($file) === 0) {
            return [];
        }
        $size = filesize($file);
        // phpcs:ignore WordPress.WP.AlternativeFunctions
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return [];
        }
        fseek($fh, max(0, $size - 400000));
        $chunk = (string)stream_get_contents($fh);
        // phpcs:ignore WordPress.WP.AlternativeFunctions
        fclose($fh);
        $lines = array_filter(explode("\n", $chunk), static fn($l) => stripos($l, 'tqk') !== false);
        return array_map(static fn($l) => substr(trim($l), 0, 300), array_slice(array_values($lines), -25));
    }
}