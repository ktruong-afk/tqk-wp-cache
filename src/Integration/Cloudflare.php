<?php
namespace TQK_WP_CACHE\Integration;

use TQK_WP_CACHE\Core\Config;

defined('ABSPATH') || exit;

/**
 * Keeps Cloudflare's copy of a page in step with ours: when TQK purges pages (or everything), the same pages are purged at Cloudflare,
 * otherwise the edge keeps serving the old HTML long after WordPress has a new one.
 *
 * Needs an API token with the permission "Zone > Cache Purge" (nothing else) and the Zone ID. The requests are sent by WP-Cron a few
 * seconds after the change (never while the editor waits), at most 30 URLs per request, as Cloudflare allows. The token is stored
 * server-side only and is never printed back into a page.
 */
class Cloudflare {
    public const API      = 'https://api.cloudflare.com/client/v4';
    public const QUEUE    = 'tqk_cache_cf_queue';
    public const HOOK     = 'tqk_cache_cf_run';
    public const PER_CALL = 30;
    private const MAX_QUEUE = 2000;

    private Config $config;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?: Config::instance();
    }

    public function enabled(): bool
    {
        return (int)$this->config->get('cf_enabled', 0) === 1 && self::valid_zone((string)$this->config->get('cf_zone', '')) && (string)$this->config->get('cf_token', '') !== '';
    }

    public static function valid_zone(string $zone): bool
    {
        return (bool)preg_match('/^[a-f0-9]{32}$/', $zone);
    }

    /** @return string[][] the URLs in groups of at most 30 (one API call each) */
    public static function chunk_urls(array $urls): array
    {
        $urls = array_values(array_unique(array_filter(array_map('strval', $urls), static fn($u) => preg_match('~^https?://~i', $u) === 1)));
        return array_chunk($urls, self::PER_CALL);
    }

    /** Remember what to purge and let WP-Cron send it. $urls = [] with $all = true means "everything". */
    public function enqueue(array $urls, bool $all = false): void
    {
        if (!$this->enabled()) {
            return;
        }
        $q = get_option(self::QUEUE, []);
        $q = is_array($q) ? $q : [];
        $q['all'] = !empty($q['all']) || $all;
        $q['urls'] = array_slice(array_values(array_unique(array_merge((array)($q['urls'] ?? []), $urls))), 0, self::MAX_QUEUE);
        update_option(self::QUEUE, $q, false);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + 3, self::HOOK);
        }
    }

    /** WP-Cron callback. */
    public static function run(): void
    {
        $cf = new self();
        $q = get_option(self::QUEUE, []);
        delete_option(self::QUEUE);
        if (!is_array($q) || !$cf->enabled()) {
            return;
        }
        $result = !empty($q['all']) ? $cf->purge_everything() : ['ok' => true, 'message' => ''];
        if (empty($q['all'])) {
            foreach (self::chunk_urls((array)($q['urls'] ?? [])) as $chunk) {
                $result = $cf->purge_urls($chunk);
                if (!$result['ok']) {
                    break;
                }
            }
        }
        if (!$result['ok']) {
            do_action('tqk_cache_incident', 'cloudflare', (string)$result['message']);
        }
        update_option('tqk_cache_cf_last', ['time' => time(), 'ok' => $result['ok'], 'message' => $result['message']], false);
    }

    public function purge_urls(array $urls): array
    {
        return $this->call('POST', '/zones/' . $this->config->get('cf_zone') . '/purge_cache', ['files' => array_values($urls)]);
    }

    public function purge_everything(): array
    {
        return $this->call('POST', '/zones/' . $this->config->get('cf_zone') . '/purge_cache', ['purge_everything' => true]);
    }

    /** Is the token valid for this zone? (a read-only request) */
    public function test(?string $zone = null, ?string $token = null): array
    {
        $zone = $zone ?? (string)$this->config->get('cf_zone', '');
        if (!self::valid_zone($zone)) {
            return ['ok' => false, 'message' => __('The Zone ID is 32 letters and digits (a-f, 0-9). Copy it from the right column of your domain\'s Overview page in Cloudflare.', 'tqk-wp-cache')];
        }
        $r = $this->call('GET', '/zones/' . $zone, null, $token);
        if ($r['ok'] && !empty($r['data']['result']['name'])) {
            $r['message'] = sprintf(__('Connected to %s (status: %s).', 'tqk-wp-cache'), $r['data']['result']['name'], $r['data']['result']['status'] ?? '?');
        }
        return $r;
    }

    /** @return array{ok:bool, message:string, data?:array} */
    private function call(string $method, string $path, ?array $body, ?string $token = null): array
    {
        $token = $token ?? (string)$this->config->get('cf_token', '');
        if ($token === '') {
            return ['ok' => false, 'message' => __('No Cloudflare API token saved.', 'tqk-wp-cache')];
        }
        $args = [
            'method'  => $method,
            'timeout' => 10,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $res = wp_remote_request(self::API . $path, $args);
        if (is_wp_error($res)) {
            return ['ok' => false, 'message' => $res->get_error_message()];
        }
        $data = json_decode((string)wp_remote_retrieve_body($res), true);
        if (is_array($data) && !empty($data['success'])) {
            return ['ok' => true, 'message' => __('Done.', 'tqk-wp-cache'), 'data' => $data];
        }
        $err = is_array($data) && !empty($data['errors'][0]['message']) ? $data['errors'][0]['message'] : 'HTTP ' . wp_remote_retrieve_response_code($res);
        return ['ok' => false, 'message' => 'Cloudflare: ' . $err];
    }
}
