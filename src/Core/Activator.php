<?php
namespace TQK_WP_CACHE\Core;

use TQK_WP_CACHE\Cache\Dropin;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Maintenance;

defined('ABSPATH') || exit;

class Activator {
    public static function activate(): void {
        self::check_requirements();

        // Seed default options
        if (empty(get_option(TQK_WP_CACHE_OPTION))) {
            update_option(TQK_WP_CACHE_OPTION, Config::defaults());
        }

        // Create cache folder
        $storage = new DiskStorage();
        $storage->ensure_dir(TQK_WP_CACHE_STORAGE_DIR);
        // Nobody should be able to browse the cache over HTTP.
        if (!file_exists(TQK_WP_CACHE_STORAGE_DIR . '/index.php')) {
            @file_put_contents(TQK_WP_CACHE_STORAGE_DIR . '/index.php', "<?php // Silence is golden.\n");
        }

        // Install the drop-in. If another cache plugin already owns advanced-cache.php we do NOT overwrite it:
        // the admin is told on the plugin screen (see Plugin::admin_notices()).
        $status = Dropin::install();
        update_option('tqk_cache_dropin_conflict', $status === 'foreign' ? 1 : 0);

        Maintenance::schedule();
    }

    private static function check_requirements(): void {
        global $wp_version;

        if (version_compare(PHP_VERSION, '8.0', '<')) {
            wp_die(esc_html__('TQK WP Cache requires PHP 8.0 or higher.', 'tqk-wp-cache'));
        }

        if (isset($wp_version) && version_compare($wp_version, '6.0', '<')) {
            wp_die(esc_html__('TQK WP Cache requires WordPress 6.0 or higher.', 'tqk-wp-cache'));
        }
    }
}
