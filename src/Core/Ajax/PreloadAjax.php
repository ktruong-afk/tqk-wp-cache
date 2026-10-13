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
 * AJAX handlers: warm-up / rebuild queue.
 * A trait of TQK_WP_CACHE\Core\Plugin (the methods keep their names and $this, see Plugin::boot() for the hooks).
 */
trait PreloadAjax {
    public function ajax_preload_start(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        // The choice made in the warm-up card is remembered for next time.
        $mode  = isset($_POST['mode']) ? $this->sanitize_rebuild_mode(wp_unslash($_POST['mode'])) : (string)$this->config->get('rebuild_mode', 'essential');
        $limit = isset($_POST['limit']) ? max(10, min(5000, (int)$_POST['limit'])) : max(10, min(5000, (int)$this->config->get('warmup_limit', 200)));
        $this->config->save(['rebuild_mode' => $mode, 'warmup_limit' => $limit]);

        $storage = new DiskStorage();
        $preload = new Preload();
        $queue   = $preload->get_queue($mode, $limit);

        if ($mode === 'lazy') {
            // Purge only: the pages are rebuilt when visitors ask for them, so the server does no work now.
            $storage->purge_all();
        } elseif ($mode === 'essential') {
            // These pages are re-created, so drop their old copy first (the crawler would otherwise just receive the cached one).
            foreach ($queue as $item) {
                $storage->purge_url($item['url']);
            }
        }

        wp_send_json_success([
            'mode'  => $mode,
            'total' => count($queue),
            'items' => $queue,
        ]);
    }

    private function sanitize_rebuild_mode($mode): string {
        $mode = sanitize_key((string)$mode);
        return in_array($mode, ['essential', 'missing', 'lazy'], true) ? $mode : 'essential';
    }

    public function ajax_preload_step(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        $url     = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;

        if (!$url || !$this->is_own_url($url)) {
            wp_send_json_error(['message' => __('Invalid URL address.', 'tqk-wp-cache')]);
        }

        $preload = new Preload();
        $result  = $preload->warmup($url, $post_id);

        $storage = new DiskStorage();
        $stats   = $storage->get_stats();

        wp_send_json_success([
            'url'     => $url,
            'result'  => $result,
            'stats'   => $stats,
        ]);
    }

    public function ajax_preload_verify(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        if (!$url || !$this->is_own_url($url)) {
            wp_send_json_error(['message' => __('Invalid URL address.', 'tqk-wp-cache')]);
        }

        $storage = new DiskStorage();
        $html_file = (string)$storage->html_path($url);
        $is_cached = $html_file !== '' && file_exists($html_file);
        $file_size = $is_cached ? size_format(filesize($html_file), 2) : '0 KB';

        // Tell the browser-side crawler to slow down when the server is busy (Linux/macOS: 1-minute load average above the core count).
        $busy = false;
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cores = max(1, (int)(getenv('NUMBER_OF_PROCESSORS') ?: 2));
            $busy = is_array($load) && $load[0] > $cores * (float)apply_filters('tqk_cache_warmup_max_load', 1.2);
        }

        wp_send_json_success([
            'url'       => $url,
            'is_cached' => $is_cached,
            'file_size' => $file_size,
            'busy'      => $busy,
            'stats'     => $storage->get_stats(true),
        ]);
    }
}
