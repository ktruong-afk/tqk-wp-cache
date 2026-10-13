<?php
namespace TQK_WP_CACHE\Admin;

defined('ABSPATH') || exit;

/**
 * The ONE place that loads assets/js/admin.js.
 *
 * It used to be enqueued twice on the plugin screen (once by the dashboard, once by the admin-bar) under two handles, each with its own
 * `tqkCacheData` object: the script ran twice (every button handler bound twice) and the later object overwrote the earlier one, so
 * the translated texts of the dashboard disappeared. Now every caller adds its data here and the script is localized exactly once.
 */
class Assets {
    public const HANDLE = 'tqk-cache-admin-js';

    private static array $data = [];
    private static bool $hooked = false;

    public static function enqueue(array $data = []): void {
        wp_enqueue_script(self::HANDLE, TQK_WP_CACHE_URL . 'assets/js/admin.js', ['jquery'], TQK_WP_CACHE_VERSION, true);
        self::$data = array_merge(self::$data, $data);

        if (!self::$hooked) {
            self::$hooked = true;
            // Footer scripts are printed at priority 10; localize just before, when every caller has had its say.
            add_action(is_admin() ? 'admin_print_footer_scripts' : 'wp_print_footer_scripts', [self::class, 'localize'], 1);
        }
    }

    public static function localize(): void {
        wp_localize_script(self::HANDLE, 'tqkCacheData', array_merge([
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('tqk_cache_nonce'),
        ], self::$data));
    }
}
