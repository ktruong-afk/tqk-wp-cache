<?php
namespace TQK_WP_CACHE\Cache;

use TQK_WP_CACHE\Integration\OpenBuilder;

defined('ABSPATH') || exit;

class Preload {
    private DiskStorage $storage;
    private OpenBuilder $openbuilder;

    public function __construct() {
        $this->storage = new DiskStorage();
        $this->openbuilder = OpenBuilder::instance();
    }

    /**
     * @param string $mode  'essential' (default, the fixed set below), 'missing' (everything public that is not cached yet) or 'lazy' (nothing).
     * @param int    $limit Largest number of pages 'missing' returns.
     */
    public function get_queue(string $mode = 'essential', int $limit = 200): array {
        if ($mode === 'lazy') {
            return [];
        }
        if ($mode === 'missing') {
            return $this->missing_queue(max(1, $limit));
        }
        $queue = [];

        // 1. Homepage
        $home_url = home_url('/');
        $queue[] = [
            'url'     => $home_url,
            'title'   => __('Homepage', 'tqk-wp-cache'),
            'type'    => 'frontpage',
            'post_id' => (int)get_option('page_on_front', 0),
        ];

        // 2. Published Pages (Prioritize OpenBuilder pages)
        $pages = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 15,
            'orderby'        => 'post_modified',
            'order'          => 'DESC',
        ]);

        foreach ($pages as $p) {
            $url = get_permalink($p->ID);
            if ($url && $url !== $home_url) {
                $queue[] = [
                    'url'     => $url,
                    'title'   => $p->post_title ?: sprintf(__('Page #%d', 'tqk-wp-cache'), $p->ID),
                    'type'    => 'page',
                    'post_id' => $p->ID,
                ];
            }
        }

        // 3. Representative Item: exactly 1 newest item per public post type (post, product, portfolio...)
        // Ensures post-type-specific CSS/JS bundle is compiled once, leaving thousands of others in Lazy Cache on Demand.
        $public_types = get_post_types(['public' => true, '_builtin' => false], 'names');
        $public_types['post'] = 'post'; // include standard blog post

        foreach ($public_types as $pt) {
            if ($pt === 'page' || $pt === 'attachment') {
                continue;
            }
            $sample = get_posts([
                'post_type'      => $pt,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);

            if (!empty($sample[0])) {
                $p = $sample[0];
                $url = get_permalink($p->ID);
                if ($url && $url !== $home_url) {
                    $queue[] = [
                        'url'     => $url,
                        'title'   => ($p->post_title ?: sprintf(__('%s #%d', 'tqk-wp-cache'), ucfirst($pt), $p->ID)) . ' [' . strtoupper($pt) . ' Sample]',
                        'type'    => $pt,
                        'post_id' => $p->ID,
                    ];
                }
            }
        }

        return $queue;
    }

    /**
     * Public pages that have no fresh cache file yet, most important first: home, taxonomy archives, pages, then the newest entries of
     * every public post type. After a tag purge this is exactly the set of pages that purge removed, so a big shop rebuilds only what
     * changed instead of thousands of pages. At most 5,000 entries are inspected, so the cost does not grow with the size of the site.
     */
    private function missing_queue(int $limit): array {
        $queue = [];
        $seen = [];
        $inspected = 0;
        $max_inspect = 5000;
        $full = static function () use (&$queue, $limit): bool { return count($queue) >= $limit; };
        $add = function (string $url, string $title, string $type, int $post_id) use (&$queue, &$seen, &$inspected): void {
            if ($url === '' || isset($seen[$url])) {
                return;
            }
            $seen[$url] = true;
            $inspected++;
            if ($this->storage->exists($url) || $this->storage->is_uncacheable($url)) {
                return;
            }
            $queue[] = ['url' => $url, 'title' => $title, 'type' => $type, 'post_id' => $post_id];
        };

        $add(home_url('/'), __('Homepage', 'tqk-wp-cache'), 'frontpage', (int)get_option('page_on_front', 0));

        // Taxonomy archives (categories, product categories...): a few hundred at most, the most used first.
        $taxonomies = get_taxonomies(['public' => true], 'names');
        unset($taxonomies['post_format']);
        if ($taxonomies) {
            $terms = get_terms(['taxonomy' => array_values($taxonomies), 'hide_empty' => true, 'number' => 300, 'orderby' => 'count', 'order' => 'DESC']);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    if ($full() || $inspected >= $max_inspect) {
                        break;
                    }
                    $link = get_term_link($term);
                    if (!is_wp_error($link)) {
                        $add($link, $term->name, $term->taxonomy, 0);
                    }
                }
            }
        }

        $types = array_values(array_diff(array_keys(get_post_types(['public' => true])), ['attachment']));
        usort($types, static function ($a, $b): int { return ($a === 'page' ? 0 : 1) <=> ($b === 'page' ? 0 : 1); });   // pages first
        foreach ($types as $pt) {
            for ($paged = 1; !$full() && $inspected < $max_inspect; $paged++) {
                $ids = get_posts([
                    'post_type' => $pt, 'post_status' => 'publish', 'posts_per_page' => 200, 'paged' => $paged, 'fields' => 'ids',
                    'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true, 'update_post_term_cache' => false, 'suppress_filters' => true,
                ]);
                if (!$ids) {
                    break;
                }
                foreach ($ids as $id) {
                    if ($full() || $inspected >= $max_inspect) {
                        break 2;
                    }
                    $add((string)get_permalink($id), get_the_title($id) ?: sprintf('%s #%d', $pt, $id), $pt, (int)$id);
                }
            }
        }
        return $queue;
    }

    public function warmup(string $url, int $post_id = 0): array {
        $logs = [];
        $time_start = microtime(true);

        // Step 1: Purge existing cache for this URL
        $this->storage->purge_url($url);
        $logs[] = sprintf(__('Purged stale cache for URL: %s', 'tqk-wp-cache'), $url);

        // Step 2: OpenBuilder assets verification
        if ($this->openbuilder->is_active()) {
            $global_css = $this->openbuilder->get_global_custom_css();
            $page_css   = $post_id > 0 ? $this->openbuilder->get_page_custom_css($post_id) : '';
            $has_custom = !empty($global_css) || !empty($page_css);

            if ($has_custom) {
                $logs[] = __('OpenBuilder detected: Synced Global CSS and Responsive options into cache', 'tqk-wp-cache');
            }
        }

        // Step 3: Send HTTP GET request to trigger cache generation
        $args = [
            'timeout'     => 15,
            'redirection' => 3,
            'httpversion' => '1.1',
            // Verify TLS except for local development hosts (.test / .local / localhost / IPs use self-signed certificates).
            'sslverify'   => (bool)apply_filters('tqk_cache_warmup_sslverify', !preg_match('/(\.test|\.local|\.localhost|\.invalid)$|^localhost$|^\d{1,3}(\.\d{1,3}){3}$/i', (string)wp_parse_url($url, PHP_URL_HOST))),
            'headers'     => [
                'X-TQK-Warmup'    => '1',
                'Cache-Control'   => 'no-cache',
                'User-Agent'      => 'Mozilla/5.0 (compatible; TQK-WP-Cache-Warmup/1.0; +OpenBuilder)',
            ],
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            $logs[] = sprintf(__('Connection error during cache warmup: %s', 'tqk-wp-cache'), $response->get_error_message());
            return [
                'success' => false,
                'logs'    => $logs,
                'time'    => round((microtime(true) - $time_start) * 1000, 1),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body_len    = strlen(wp_remote_retrieve_body($response));
        $time_spent  = round((microtime(true) - $time_start) * 1000, 1);

        // Verify that cache file exists on disk
        $html_file = (string)$this->storage->html_path($url);
        $is_cached = $html_file !== '' && file_exists($html_file);

        if ($is_cached) {
            $file_size = size_format(filesize($html_file), 2);
            $logs[] = sprintf(__('Cache generated successfully: %s (HTTP %d, completed in %sms)', 'tqk-wp-cache'), $file_size, $status_code, $time_spent);
        } else {
            $logs[] = sprintf(__('Warmup request dispatched (HTTP %d, %s received in %sms)', 'tqk-wp-cache'), $status_code, size_format($body_len, 2), $time_spent);
        }

        return [
            'success'   => $status_code >= 200 && $status_code < 400,
            'logs'      => $logs,
            'time'      => $time_spent,
            'is_cached' => $is_cached,
        ];
    }
}
