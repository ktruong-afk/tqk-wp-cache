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
 * AJAX handlers: revisions and database junk.
 * A trait of TQK_WP_CACHE\Core\Plugin (the methods keep their names and $this, see Plugin::boot() for the hooks).
 */
trait DatabaseAjax {
    public function ajax_db_get_revisions(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        $analyzer = new Analyzer();
        $items = $analyzer->get_posts_with_revisions(50);

        wp_send_json_success([
            'items' => $items,
            'count' => count($items),
        ]);
    }

    public function ajax_db_clean_revisions(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        $items = isset($_POST['items']) && is_array($_POST['items']) ? $_POST['items'] : [];
        $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];
        $keep = isset($_POST['keep']) ? max(0, (int)$_POST['keep']) : 3;

        $posts_data = [];
        if (!empty($items)) {
            foreach ($items as $it) {
                $pid = (int)($it['id'] ?? 0);
                if ($pid > 0) {
                    $posts_data[] = [
                        'id'   => $pid,
                        'keep' => isset($it['keep']) ? max(0, (int)$it['keep']) : $keep,
                    ];
                }
            }
        } elseif (!empty($post_ids)) {
            foreach ($post_ids as $pid) {
                $posts_data[] = [
                    'id'   => $pid,
                    'keep' => $keep,
                ];
            }
        }

        if (empty($posts_data)) {
            wp_send_json_error(['message' => __('No posts selected for revision cleanup.', 'tqk-wp-cache')]);
        }

        $analyzer = new Analyzer();
        $res = $analyzer->clean_post_revisions($posts_data, $keep);

        wp_send_json_success([
            'message'       => sprintf(__('Cleaned revisions for %d posts, removed %d revision rows safely.', 'tqk-wp-cache'), $res['cleaned_posts'], $res['deleted_count']),
            'cleaned_posts' => $res['cleaned_posts'],
            'deleted_count' => $res['deleted_count'],
        ]);
    }

    public function ajax_db_get_junk_stats(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        $analyzer = new Analyzer();
        wp_send_json_success([
            'stats' => $analyzer->get_database_junk_stats(),
        ]);
    }

    public function ajax_db_clean_junk(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        $analyzer = new Analyzer();
        $res = $analyzer->clean_system_junk();

        wp_send_json_success([
            'message' => sprintf(__('System junk cleaned: %d transients, %d auto-drafts, %d orphan metas.', 'tqk-wp-cache'), $res['transients'], $res['auto_drafts'], $res['orphan_meta']),
            'details' => $res,
        ]);
    }
}
