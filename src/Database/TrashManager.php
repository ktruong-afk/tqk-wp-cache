<?php
namespace TQK_WP_CACHE\Database;

defined('ABSPATH') || exit;

/**
 * High-Performance Trash Manager with WooCommerce Optimization.
 * Author: Trương Quang Khải
 */
class TrashManager {
    /**
     * Get trash statistics grouped by post type.
     *
     * @return array
     */
    public function get_trash_summary(): array {
        global $wpdb;

        $sql = "
            SELECT post_type, COUNT(*) as count
            FROM {$wpdb->posts}
            WHERE post_status = 'trash'
            GROUP BY post_type
            ORDER BY count DESC
        ";

        $rows = $wpdb->get_results($sql, ARRAY_A);
        $summary = [];

        foreach ($rows as $row) {
            $pt = $row['post_type'];
            $pt_obj = get_post_type_object($pt);
            $label = $pt_obj ? $pt_obj->labels->name : ucfirst($pt);

            $summary[] = [
                'post_type' => $pt,
                'label'     => $label,
                'count'     => (int)$row['count'],
            ];
        }

        return $summary;
    }

    /**
     * Get trash items for a specific post type.
     *
     * @param string $post_type
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function get_trash_items(string $post_type, int $limit = 50, int $offset = 0): array {
        global $wpdb;

        $sql = "
            SELECT ID, post_title, post_type, post_modified, post_author
            FROM {$wpdb->posts}
            WHERE post_status = 'trash' AND post_type = %s
            ORDER BY post_modified DESC
            LIMIT %d OFFSET %d
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $post_type, $limit, $offset), ARRAY_A);
        $items = [];

        foreach ($rows as $row) {
            $author = get_userdata((int)$row['post_author']);
            $items[] = [
                'id'       => (int)$row['ID'],
                'title'    => !empty($row['post_title']) ? $row['post_title'] : sprintf(__('Item #%d (Untitled)', 'tqk-wp-cache'), $row['ID']),
                'type'     => $row['post_type'],
                'date'     => $row['post_modified'],
                'author'   => $author ? $author->display_name : __('Unknown', 'tqk-wp-cache'),
            ];
        }

        return $items;
    }

    /**
     * Restore trash items back to draft.
     *
     * @param array $post_ids
     * @return int
     */
    public function restore_items(array $post_ids): int {
        $restored = 0;
        foreach ($post_ids as $pid) {
            $pid = (int)$pid;
            if (wp_untrash_post($pid)) {
                $restored++;
            }
        }
        return $restored;
    }

    /**
     * High-speed batch deletion of trash items, optimized for WooCommerce.
     * Uses wp_defer_term_counting and selective hook bypass to prevent server timeouts.
     *
     * @param array $post_ids
     * @param string $post_type
     * @return array
     */
    public function delete_items_batch(array $post_ids, string $post_type = ''): array {
        global $wpdb;

        $time_start = microtime(true);
        $deleted_count = 0;

        // Defer heavy term/comment counting during bulk operations
        wp_defer_term_counting(true);
        wp_defer_comment_counting(true);

        $is_wc_product = ($post_type === 'product');

        foreach ($post_ids as $pid) {
            $pid = (int)$pid;
            if ($pid <= 0) {
                continue;
            }
            // Safety: this tool empties the TRASH. An id of a published / draft post (a forged request, a stale screen) is never deleted,
            // and when a post type is given the post must be of that type.
            if (get_post_status($pid) !== 'trash' || ($post_type !== '' && get_post_type($pid) !== $post_type)) {
                continue;
            }

            // WooCommerce special handling: purge variations before main product
            if ($is_wc_product) {
                $variations = $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
                    $pid
                ));
                foreach ($variations as $var_id) {
                    wp_delete_post((int)$var_id, true);
                }
            }

            // Delete post permanently (force delete bypasses trash)
            $res = wp_delete_post($pid, true);
            if ($res) {
                $deleted_count++;
            }
        }

        // Re-enable and trigger deferred counting once at the end
        wp_defer_term_counting(false);
        wp_defer_comment_counting(false);

        $time_spent = round((microtime(true) - $time_start) * 1000, 1);

        return [
            'deleted' => $deleted_count,
            'time'    => $time_spent,
        ];
    }

    /**
     * Empty entire trash for a specific post type in high-speed batches.
     *
     * @param string $post_type
     * @param int $batch_size
     * @return array
     */
    public function empty_trash_by_type(string $post_type, int $batch_size = 50): array {
        global $wpdb;

        $pids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_type = %s LIMIT %d",
            $post_type,
            $batch_size
        ));

        if (empty($pids)) {
            return [
                'remaining' => 0,
                'deleted'   => 0,
                'time'      => 0,
            ];
        }

        $res = $this->delete_items_batch($pids, $post_type);

        $remaining = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_type = %s",
            $post_type
        ));

        return [
            'remaining' => $remaining,
            'deleted'   => $res['deleted'],
            'time'      => $res['time'],
        ];
    }
}
