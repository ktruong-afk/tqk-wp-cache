<?php
namespace TQK_WP_CACHE\Core\Ajax;

use TQK_WP_CACHE\Integration\Cloudflare;

defined('ABSPATH') || exit;

/**
 * AJAX handler: "Test connection" of the Cloudflare card. A trait of TQK_WP_CACHE\Core\Plugin.
 */
trait CloudflareAjax {

    public function ajax_cf_test(): void {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }
        // What is typed in the form is tested; an empty token field means "the saved one".
        $zone  = strtolower(trim((string)wp_unslash($_POST['cf_zone'] ?? '')));
        $token = trim((string)wp_unslash($_POST['cf_token'] ?? ''));
        $cf = new Cloudflare();
        $r = $cf->test($zone !== '' ? $zone : null, $token !== '' ? $token : null);
        $r['ok'] ? wp_send_json_success(['message' => $r['message']]) : wp_send_json_error(['message' => $r['message']]);
    }
}
