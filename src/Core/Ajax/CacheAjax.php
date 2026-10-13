<?php
namespace TQK_WP_CACHE\Core\Ajax;

use TQK_WP_CACHE\Cache\Engine;
use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\Purge;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;
use TQK_WP_CACHE\Admin\Dashboard;
use TQK_WP_CACHE\Admin\AdminBar;
use TQK_WP_CACHE\Integration\OpenBuilder;
use TQK_WP_CACHE\Cache\Preload;
use TQK_WP_CACHE\Database\Analyzer;
use TQK_WP_CACHE\Database\TrashManager;
use TQK_WP_CACHE\Cache\Maintenance;

defined('ABSPATH') || exit;

/**
 * AJAX handlers: purge, settings, language, statistics.
 * A trait of TQK_WP_CACHE\Core\Plugin (the methods keep their names and $this, see Plugin::boot() for the hooks).
 */
trait CacheAjax {
    public function ajax_switch_lang(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        $lang = sanitize_key($_POST['lang'] ?? 'vi');
        if (!in_array($lang, ['vi', 'en'], true)) {
            $lang = 'vi';
        }

        update_user_meta(get_current_user_id(), 'tqk_cache_ui_lang', $lang);
        update_option('tqk_cache_ui_lang', $lang);

        wp_send_json_success([
            'message' => $lang === 'vi' ? __('Switched to Vietnamese.', 'tqk-wp-cache') : __('Switched to English.', 'tqk-wp-cache'),
            'lang'    => $lang,
        ]);
    }

    public function ajax_purge_all(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'tqk-wp-cache')]);
        }

        $this->purge->purge_all();
        $storage = new DiskStorage();
        $stats = $storage->get_stats(true);

        wp_send_json_success([
            'message' => __('All cache cleared successfully!', 'tqk-wp-cache'),
            'stats'   => $stats,
        ]);
    }

    public function ajax_purge_current(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'tqk-wp-cache')]);
        }

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        if ($url && $this->is_own_url($url)) {
            $this->purge->purge_url($url);
            wp_send_json_success(['message' => __('Cache cleared for this page!', 'tqk-wp-cache')]);
        }

        wp_send_json_error(['message' => __('Invalid URL address.', 'tqk-wp-cache')]);
    }

    public function ajax_save_settings(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to save settings.', 'tqk-wp-cache')]);
        }

        // A key that is not posted keeps its current value (partial saves must never reset other options).
        // Checkboxes arrive as "0"/"1" (the form sends 0 for unchecked): isset() alone would turn every "0" into ON.
        $flag_keys = [
            'page_cache', 'cache_mobile', 'gzip_compression', 'combine_css', 'combine_js', 'delay_js', 'lazy_load', 'lazy_load_iframes',
            'instant_page', 'fonts_swap', 'lcp_hint', 'cdn_enabled', 'cf_enabled', 'html_minify', 'purge_on_post_update', 'purge_frontpage', 'precise_term_purge', 'litespeed_headers', 'admin_turbo',
            'admin_benchmark_hud', 'disable_auto_updates', 'browser_cache',
            'admin_http_block_telemetry', 'admin_http_cache', 'admin_http_skip_cron',
        ];
        $text_keys = [
            'combine_exclusions', 'delay_js_exclusions', 'excluded_urls', 'excluded_cookies', 'cache_query_allow', 'admin_turbo_exclusions',
            'admin_http_extra_block', 'admin_http_extra_cache', 'cdn_exclusions', 'meta_ignore_keys', 'live_meta_keys',
        ];

        $int_rules = ['cache_lifespan' => [1, 720], 'delay_js_timeout' => [0, 60], 'heartbeat_interval' => [15, 300], 'warmup_limit' => [10, 5000], 'admin_http_cache_ttl' => [1, 72], 'meta_refresh_cooldown' => [10, 3600]];
        // An add-on (TQK WP Cache PRO) lists the settings it owns, in the same three shapes.
        $flag_keys = (array)apply_filters('tqk_cache_flag_keys', $flag_keys);
        $text_keys = (array)apply_filters('tqk_cache_text_keys', $text_keys);
        $int_rules = (array)apply_filters('tqk_cache_int_rules', $int_rules);

        $fields = [];
        foreach ($flag_keys as $key) {
            if (isset($_POST[$key])) {
                $v = wp_unslash($_POST[$key]);
                $fields[$key] = ($v !== '0' && $v !== '' && $v !== 'false') ? 1 : 0;
            }
        }
        foreach ($text_keys as $key) {
            if (isset($_POST[$key])) {
                $fields[$key] = sanitize_textarea_field(wp_unslash($_POST[$key]));
            }
        }
        foreach ($int_rules as $key => [$min, $max]) {
            if (isset($_POST[$key])) {
                $fields[$key] = max($min, min($max, (int)$_POST[$key]));
            }
        }
        if (isset($_POST['active_preset'])) {
            $fields['active_preset'] = sanitize_key($_POST['active_preset']);
        }
        if (isset($_POST['cf_zone'])) {
            $zone = strtolower(trim((string)wp_unslash($_POST['cf_zone'])));
            $fields['cf_zone'] = ($zone === '' || \TQK_WP_CACHE\Integration\Cloudflare::valid_zone($zone)) ? $zone : (string)$this->config->get('cf_zone', '');
        }
        // The token is only replaced when a new one is typed (the field is empty while one is saved) and only if it looks like a token.
        if (isset($_POST['cf_token']) && trim((string)wp_unslash($_POST['cf_token'])) !== '') {
            $tok = trim((string)wp_unslash($_POST['cf_token']));
            if (preg_match('/^[A-Za-z0-9_\-]{20,80}$/', $tok)) {
                $fields['cf_token'] = $tok;
            }
        }
        if (isset($_POST['cdn_url'])) {
            // A CDN address is "https://host" only: no path, no query, no credentials.
            $u = trim((string)wp_unslash($_POST['cdn_url']));
            $fields['cdn_url'] = ($u === '' || preg_match('~^https?://[A-Za-z0-9.-]+(?::\d+)?/?$~', $u)) ? rtrim($u, '/') : (string)$this->config->get('cdn_url', '');
        }
        if (isset($_POST['rebuild_mode'])) {
            $fields['rebuild_mode'] = $this->sanitize_rebuild_mode(wp_unslash($_POST['rebuild_mode']));
        }
        if (isset($_POST['heartbeat_control'])) {
            $mode = sanitize_key($_POST['heartbeat_control']);
            $fields['heartbeat_control'] = in_array($mode, ['default', 'reduce', 'disable'], true) ? $mode : 'reduce';
        }

        // Raw code: only users allowed to publish unfiltered HTML may change it (multisite admins are not), and it is stored untouched.
        if (current_user_can('unfiltered_html')) {
            foreach (Config::RAW_KEYS as $key) {
                if (isset($_POST[$key])) {
                    $fields[$key] = (string)wp_unslash($_POST[$key]);
                }
            }
        }

        // The add-on may veto or adjust what is saved (e.g. its switches cannot be turned on without a licence).
        $fields = (array)apply_filters('tqk_cache_save_fields', $fields);

        $before = $this->config->all();
        $this->config->save($fields);
        do_action('tqk_cache_settings_saved', $fields, $before);

        $status = 'ok';
        if ($this->config->get('page_cache')) {
            $status = Dropin::install();   // rewrites the drop-in with the new settings baked in
        } else {
            Dropin::uninstall();
        }
        update_option('tqk_cache_dropin_conflict', $status === 'foreign' ? 1 : 0);
        if ($status === 'foreign' || $status === 'error') {
            do_action('tqk_cache_incident', 'dropin-' . $status, 'Saving the settings could not install the page-cache drop-in.');
        }

        $message = __('Settings saved successfully! Changes are active immediately.', 'tqk-wp-cache');
        if ($status === 'foreign') {
            $message = __('Settings saved, but another plugin owns wp-content/advanced-cache.php, so the static drop-in is NOT active. Disable the other cache plugin first.', 'tqk-wp-cache');
        } elseif ($status === 'error') {
            $message = __('Settings saved, but wp-content is not writable, so the drop-in could not be installed.', 'tqk-wp-cache');
        }

        wp_send_json_success([
            'message'       => $message,
            'dropin_active' => Dropin::is_active() && defined('WP_CACHE') && WP_CACHE,
            'dropin_status' => Dropin::status(),
        ]);
    }

    public function ajax_get_stats(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        $storage = new DiskStorage();
        wp_send_json_success([
            'stats' => $storage->get_stats(true),
        ]);
    }
}
