<?php
namespace TQK_WP_CACHE\Optimization;

use TQK_WP_CACHE\Core\Config;

defined('ABSPATH') || exit;

/**
 * Numbers that must stay current on a cached page (comment counts, member totals, likes...).
 *
 * A cached page is plain HTML: nothing runs on the server when a visitor opens it, so a number printed at render time is frozen until the
 * page is rebuilt. Wrap such a number in [tqk_live] (or tqk_live() in a template). The page keeps the value it had at render time, which
 * is what search engines and no-JS visitors see, and a few lines of JavaScript replace it with the current value from a tiny REST route.
 * The route is cacheable for a short time, so a busy page does not turn into a busy database.
 *
 *   [tqk_live source="comments_total"]
 *   [tqk_live source="comments_post"]                  (this post; or post="123")
 *   [tqk_live source="users_total"]
 *   [tqk_live source="posts_count" type="product"]
 *   [tqk_live source="meta" post="123" key="likes"]    (key must be listed under "Live meta keys")
 *
 * More sources: add_filter('tqk_live_sources', fn($s) => $s + ['my_count' => fn($args) => 42]);
 */
class LiveCounters {
    public const ROUTE_NS   = 'tqk/v1';
    public const ROUTE      = '/live';
    private const MAX_ITEMS = 20;

    private static bool $needs_js = false;

    public function init(): void {
        add_shortcode('tqk_live', [$this, 'shortcode']);
        add_action('rest_api_init', [$this, 'register_route']);
        add_action('wp_footer', [$this, 'print_js'], 99);
        require_once __DIR__ . '/live-functions.php';
    }

    /* ------------------------------------------------------------------ pure helpers (unit tested) */

    /** "meta:123:likes" => ['meta', ['123', 'likes']]. The source name is restricted to [a-z0-9_]. */
    public static function parse_item(string $item): ?array
    {
        $parts = explode(':', trim($item), 4);
        $source = strtolower((string)array_shift($parts));
        if ($source === '' || !preg_match('/^[a-z0-9_]{1,40}$/', $source)) {
            return null;
        }
        foreach ($parts as $p) {
            if (strlen($p) > 80 || preg_match('/[\x00-\x1f]/', $p)) {
                return null;
            }
        }
        return [$source, array_values($parts)];
    }

    /* ------------------------------------------------------------------ sources */

    /** @return array<string, callable> name => fn(array $args): mixed */
    public function sources(): array
    {
        $sources = [
            'comments_total' => static function (): int {
                return (int)wp_count_comments()->approved;
            },
            'comments_post' => static function (array $a): ?int {
                $post = get_post(isset($a[0]) && $a[0] !== '' ? (int)$a[0] : 0);
                return self::visible($post) ? (int)get_comments_number($post) : null;
            },
            'users_total' => static function (): int {
                $n = get_transient('tqk_live_users');
                if ($n === false) {
                    global $wpdb;
                    $n = (int)$wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->users}");
                    set_transient('tqk_live_users', $n, MINUTE_IN_SECONDS);
                }
                return (int)$n;
            },
            'posts_count' => static function (array $a): ?int {
                $type = isset($a[0]) ? sanitize_key($a[0]) : 'post';
                $obj = get_post_type_object($type);
                if (!$obj || !$obj->public) {
                    return null;
                }
                return (int)(wp_count_posts($type)->publish ?? 0);
            },
            'meta' => function (array $a) {
                $post = get_post(isset($a[0]) ? (int)$a[0] : 0);
                $key = isset($a[1]) ? (string)$a[1] : '';
                $allowed = Config::instance()->lines('live_meta_keys');
                if (!self::visible($post) || $key === '' || !in_array($key, (array)apply_filters('tqk_live_meta_keys', $allowed), true)) {
                    return null;   // only keys the site owner listed: never expose private meta
                }
                $v = get_post_meta($post->ID, $key, true);
                return is_numeric($v) ? $v + 0 : (is_scalar($v) ? (string)$v : null);
            },
        ];
        return (array)apply_filters('tqk_live_sources', $sources);
    }

    /** Public, published, not password protected: the only posts whose numbers may be shown to anybody. */
    private static function visible($post): bool
    {
        return $post instanceof \WP_Post && $post->post_status === 'publish' && !post_password_required($post);
    }

    public function value_of(string $item)
    {
        $parsed = self::parse_item($item);
        if ($parsed === null) {
            return null;
        }
        $sources = $this->sources();
        if (!isset($sources[$parsed[0]]) || !is_callable($sources[$parsed[0]])) {
            return null;
        }
        try {
            return call_user_func($sources[$parsed[0]], $parsed[1]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ REST route */

    public function register_route(): void
    {
        register_rest_route(self::ROUTE_NS, self::ROUTE, [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest'],
            'permission_callback' => '__return_true',
            'args'                => ['i' => ['required' => true, 'type' => 'string']],
        ]);
    }

    public function rest(\WP_REST_Request $request): \WP_REST_Response
    {
        $out = [];
        foreach (array_slice(array_unique(array_filter(explode(',', (string)$request->get_param('i')))), 0, self::MAX_ITEMS) as $item) {
            $out[$item] = $this->value_of($item);
        }
        $response = new \WP_REST_Response($out);
        $seconds = max(0, (int)apply_filters('tqk_live_cache_seconds', 30));
        $response->header('Cache-Control', $seconds > 0 ? 'public, max-age=' . $seconds : 'no-store');
        return $response;
    }

    /* ------------------------------------------------------------------ output */

    public function shortcode($atts): string
    {
        $a = shortcode_atts(['source' => 'comments_total', 'post' => '', 'key' => '', 'type' => '', 'refresh' => '0', 'tag' => 'span'], (array)$atts, 'tqk_live');
        $source = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string)$a['source']));
        $args = [];
        if (in_array($source, ['comments_post', 'meta'], true)) {
            $args[] = $a['post'] !== '' ? (string)(int)$a['post'] : (string)get_the_ID();
        }
        if ($source === 'meta') {
            $args[] = (string)$a['key'];
        }
        if ($source === 'posts_count' && $a['type'] !== '') {
            $args[] = sanitize_key($a['type']);
        }
        if (!in_array($source, ['comments_total', 'users_total', 'posts_count', 'comments_post', 'meta'], true)) {
            $args[] = $a['post'] !== '' ? (string)(int)$a['post'] : (string)get_the_ID();   // a source added with the tqk_live_sources filter (e.g. TQK WP Counter) gets the post id
        }
        return self::render($source, $args, (int)$a['refresh'], in_array($a['tag'], ['span', 'strong', 'b', 'em', 'div', 'p'], true) ? $a['tag'] : 'span');
    }

    public static function render(string $source, array $args = [], int $refresh = 0, string $tag = 'span'): string
    {
        self::$needs_js = true;
        $item = $source . ($args ? ':' . implode(':', $args) : '');
        $value = (new self())->value_of($item);
        $text = is_int($value) || is_float($value) ? number_format_i18n($value) : (is_string($value) ? $value : '');
        return sprintf(
            '<%1$s class="tqk-live" data-tqk-live="%2$s"%3$s>%4$s</%1$s>',
            $tag,
            esc_attr($item),
            $refresh >= 15 ? ' data-refresh="' . (int)$refresh . '"' : '',
            esc_html($text)
        );
    }

    /** One small inline script, printed once and only on pages that use a live number (ES5, no comments, never delayed). */
    public function print_js(): void
    {
        if (!self::$needs_js) {
            return;
        }
        $url = wp_json_encode(esc_url_raw(rest_url(self::ROUTE_NS . self::ROUTE)));
        echo '<script data-tqk-no-delay>(function(){var u=' . $url . ',q=document.querySelectorAll("[data-tqk-live]");if(!q.length||!window.fetch)return;'
            . 'function go(){var m={},k=[],i,e,a;for(i=0;i<q.length;i++){e=q[i];a=e.getAttribute("data-tqk-live");if(!m[a]){m[a]=[];k.push(a);}m[a].push(e);}'
            . 'fetch(u+(u.indexOf("?")<0?"?":"&")+"i="+encodeURIComponent(k.join(",")),{credentials:"omit"}).then(function(r){return r.json();}).then(function(d){'
            . 'for(var n in d){if(d[n]===null||!m[n])continue;for(i=0;i<m[n].length;i++){m[n][i].textContent=typeof d[n]==="number"?d[n].toLocaleString(document.documentElement.lang||undefined):String(d[n]);}}}).catch(function(){});}'
            . 'go();var s=0;for(var j=0;j<q.length;j++){var r=parseInt(q[j].getAttribute("data-refresh"),10);if(r>=15&&(!s||r<s))s=r;}'
            . 'if(s)setInterval(function(){if(!document.hidden)go();},s*1000);})();</script>' . "\n";
    }
}
