<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package TQK_WP_CACHE
 * @author  Trương Quang Khải <https://www.facebook.com/Unk.khai/>
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// Delete options
delete_option('tqk_wp_cache_settings');
delete_option('tqk_cache_dropin_conflict');
delete_option('tqk_cache_sqlite_status');
wp_clear_scheduled_hook('tqk_wp_cache_daily');

// Remove dropin file if exists
$dropin_file = WP_CONTENT_DIR . '/advanced-cache.php';
if (file_exists($dropin_file)) {
    $content = @file_get_contents($dropin_file);
    if ($content && strpos($content, 'TQK_WP_CACHE_DROPIN') !== false) {
        @unlink($dropin_file);
    }
}

// Clean cache directory
$cache_dir = WP_CONTENT_DIR . '/cache/tqk-cache';
if (is_dir($cache_dir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($cache_dir);
}
