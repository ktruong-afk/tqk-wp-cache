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
 * AJAX handlers: trash manager.
 * A trait of TQK_WP_CACHE\Core\Plugin (the methods keep their names and $this, see Plugin::boot() for the hooks).
 */
trait TrashAjax {
    public function ajax_trash_get_summary(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        $trash = new TrashManager();
        wp_send_json_success([
            'summary' => $trash->get_trash_summary(),
        ]);
    }

    public function ajax_trash_get_items(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        $post_type = sanitize_key($_POST['post_type'] ?? 'post');
        $trash = new TrashManager();
        $items = $trash->get_trash_items($post_type, 50, 0);

        wp_send_json_success([
            'items'     => $items,
            'post_type' => $post_type,
        ]);
    }

    public function ajax_trash_restore_items(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];
        if (empty($post_ids)) {
            wp_send_json_error(['message' => __('No items selected.', 'tqk-wp-cache')]);
        }

        $trash = new TrashManager();
        $restored = $trash->restore_items($post_ids);

        wp_send_json_success([
            'message'  => sprintf(__('Restored %d items successfully.', 'tqk-wp-cache'), $restored),
            'restored' => $restored,
        ]);
    }

    public function ajax_trash_delete_items(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];
        $post_type = sanitize_key($_POST['post_type'] ?? '');

        if (empty($post_ids)) {
            wp_send_json_error(['message' => __('No items selected.', 'tqk-wp-cache')]);
        }

        $trash = new TrashManager();
        $res = $trash->delete_items_batch($post_ids, $post_type);

        wp_send_json_success([
            'message' => sprintf(__('Permanently deleted %d items in %sms.', 'tqk-wp-cache'), $res['deleted'], $res['time']),
            'deleted' => $res['deleted'],
            'time'    => $res['time'],
        ]);
    }

    public function ajax_trash_empty_type(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        $post_type = sanitize_key($_POST['post_type'] ?? '');
        if (!$post_type) {
            wp_send_json_error(['message' => __('Invalid post type.', 'tqk-wp-cache')]);
        }

        $trash = new TrashManager();
        $res = $trash->empty_trash_by_type($post_type, 50);

        wp_send_json_success([
            'message'   => sprintf(__('Deleted batch of %d items (%d remaining).', 'tqk-wp-cache'), $res['deleted'], $res['remaining']),
            'deleted'   => $res['deleted'],
            'remaining' => $res['remaining'],
            'time'      => $res['time'],
        ]);
    }
}
