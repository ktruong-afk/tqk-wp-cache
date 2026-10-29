<?php
namespace TQK_WP_CACHE\Database;

defined('ABSPATH') || exit;

/**
 * High-Performance Database & Revisions Analyzer.
 * Author: Trương Quang Khải
 */
class Analyzer {
    /**
     * Get posts with highest number of revisions.
     *
     * @param int $limit
     * @return array
     */
    public function get_posts_with_revisions(int $limit = 50): array {
        global $wpdb;

        $sql = "
            SELECT p.ID, p.post_title, p.post_type, p.post_date, p.post_modified,
                   COUNT(r.ID) as revision_count,
                   SUM(LENGTH(r.post_content)) as estimated_bytes
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->posts} r ON r.post_parent = p.ID AND r.post_type = 'revision'
            WHERE p.post_status NOT IN ('trash', 'auto-draft')
            GROUP BY p.ID
            HAVING revision_count > 0
            ORDER BY revision_count DESC
            LIMIT %d
        ";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A);
        if (!$results) {
            return [];
        }

        $items = [];
        foreach ($results as $row) {
            $bytes = (int)($row['estimated_bytes'] ?? 0);
            $items[] = [
                'id'              => (int)$row['ID'],
                'title'           => !empty($row['post_title']) ? $row['post_title'] : sprintf(__('Post #%d (Untitled)', 'tqk-wp-cache'), $row['ID']),
                'type'            => $row['post_type'],
                'edit_url'        => get_edit_post_link($row['ID'], 'raw'),
                'view_url'        => get_permalink($row['ID']),
                'revision_count'  => (int)$row['revision_count'],
                'estimated_bytes' => $bytes,
                'size_formatted'  => size_format($bytes, 2),
                'modified'        => $row['post_modified'],
            ];
        }

        return $items;
    }

    /**
     * Clean revisions for specific posts with individual or global safety limit (e.g. keep last 3, 5, 10).
     *
     * @param array $posts_data Either array of post IDs or array of ['id' => int, 'keep' => int]
     * @param int $default_keep Fallback keep count if not specified per item
     * @return array
     */
    public function clean_post_revisions(array $posts_data, int $default_keep = 3): array {
        global $wpdb;

        $deleted_total = 0;
        $cleaned_posts = 0;

        foreach ($posts_data as $item) {
            if (is_array($item)) {
                $pid = (int)($item['id'] ?? 0);
                $keep_count = isset($item['keep']) ? max(0, (int)$item['keep']) : $default_keep;
            } else {
                $pid = (int)$item;
                $keep_count = max(0, $default_keep);
            }

            if ($pid <= 0) {
                continue;
            }

            // Get all revision IDs for this parent ordered by ID DESC
            $rev_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' ORDER BY ID DESC",
                $pid
            ));

            if (empty($rev_ids)) {
                continue;
            }

            // Slice out the kept ones: if keep_count is 0, delete all; otherwise slice from offset keep_count
            $to_delete = $keep_count > 0 ? array_slice($rev_ids, $keep_count) : $rev_ids;
            if (empty($to_delete)) {
                continue;
            }

            // wp_delete_post_revision() removes the row, its meta and clears the object cache; only revisions of THIS post are touched.
            $deleted = 0;
            foreach ($to_delete as $rev_id) {
                if (wp_delete_post_revision((int)$rev_id)) {
                    $deleted++;
                }
            }

            if ($deleted) {
                $deleted_total += $deleted;
                $cleaned_posts++;
            }
        }

        return [
            'cleaned_posts' => $cleaned_posts,
            'deleted_count' => $deleted_total,
        ];
    }

    /**
     * Get counts of system database junk (transients, auto-drafts, orphan meta).
     *
     * @return array
     */
    public function get_database_junk_stats(): array {
        global $wpdb;

        // Expired transients
        $time_now = time();
        $expired_transients = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
            '_transient_timeout_%',
            $time_now
        ));

        // Auto drafts
        $auto_drafts = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date < DATE_SUB(NOW(), INTERVAL 7 DAY)");

        // Orphan postmeta
        $orphan_meta = (int)$wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->postmeta} pm 
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
            WHERE p.ID IS NULL
        ");

        // Total revisions count
        $total_revisions = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");

        return [
            'expired_transients' => $expired_transients,
            'auto_drafts'        => $auto_drafts,
            'orphan_meta'        => $orphan_meta,
            'total_revisions'    => $total_revisions,
        ];
    }

    /**
     * 1-Click clean all expired transients and auto-drafts.
     *
     * @return array
     */
    public function clean_system_junk(): array {
        global $wpdb;

        $time_now = time();

        // 1. Clean expired transients
        $expired_names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
            '_transient_timeout_%',
            $time_now
        ));

        $transients_deleted = 0;
        if (!empty($expired_names)) {
            // Only the timeout row and its matching value row. (The old code also deleted an option named exactly like the
            // transient, which could remove an unrelated option.)
            $transient_keys = [];
            foreach ($expired_names as $timeout_key) {
                $transient_keys[] = $timeout_key;
                $transient_keys[] = '_transient_' . substr($timeout_key, strlen('_transient_timeout_'));
            }

            $escaped_keys = "'" . implode("','", array_map('esc_sql', array_unique($transient_keys))) . "'";
            $transients_deleted = (int)$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name IN ({$escaped_keys})");
        }

        // 2. Clean auto drafts
        // Older than 7 days only, like WordPress itself: a fresh auto-draft may be the post someone is writing right now.
        $auto_drafts_deleted = (int)$wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date < DATE_SUB(NOW(), INTERVAL 7 DAY)");

        // 3. Clean orphan meta
        $orphan_meta_deleted = (int)$wpdb->query("
            DELETE pm FROM {$wpdb->postmeta} pm 
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
            WHERE p.ID IS NULL
        ");

        return [
            'transients'  => $transients_deleted,
            'auto_drafts' => $auto_drafts_deleted,
            'orphan_meta' => $orphan_meta_deleted,
            'total'       => $transients_deleted + $auto_drafts_deleted + $orphan_meta_deleted,
        ];
    }
}
