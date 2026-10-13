<?php
namespace TQK_WP_CACHE\Cache;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Optimization\DelayJs;
use TQK_WP_CACHE\Optimization\LazyLoad;
use TQK_WP_CACHE\Optimization\FrontEnd;
use TQK_WP_CACHE\Optimization\HtmlMinify;
use TQK_WP_CACHE\Optimization\InstantPage;
use TQK_WP_CACHE\Optimization\AssetCombine;
use TQK_WP_CACHE\Integration\OpenBuilder;

defined('ABSPATH') || exit;

/**
 * Generates the cached page. The drop-in (Dropin.php) serves it afterwards.
 *
 * Golden rule: a page is stored only when its HTML is identical for every anonymous visitor. Every check below exists because the
 * opposite leaked data in testing (password-protected content, commenter name/e-mail pre-filled in the comment form, carts...).
 */
class Engine {
    private const MAX_TAG_IDS = 300;

    private Config $config;
    private DiskStorage $storage;
    private bool $buffering = false;
    private bool $is_warmup = false;
    private ?string $bypass_reason = null;

    /** Post IDs and post types rendered by any WP_Query on this request (becomes the cache tags). */
    private array $seen_ids = [];
    private array $seen_list_types = [];

    public function __construct() {
        $this->config = Config::instance();
        $this->storage = new DiskStorage();
    }

    public function init(): void {
        if (!$this->config->get('page_cache')) {
            return;
        }

        // Guest warm-up: the admin's browser (cookies and all) loads the page with this flag, we render it as an anonymous visitor.
        $this->is_warmup = isset($_GET['tqk_cache_guest']) && $_GET['tqk_cache_guest'] === '1';
        if ($this->is_warmup) {
            add_filter('show_admin_bar', '__return_false', 99999);
            add_filter('determine_current_user', '__return_false', 99999);
        }

        add_filter('the_posts', [$this, 'collect_posts'], 99, 2);
        add_filter('posts_pre_query', [$this, 'collect_query_types'], 99, 2);
        add_action('template_redirect', [$this, 'start_buffering'], -99999);
    }

    public function start_buffering(): void {
        $this->bypass_reason = $this->detect_bypass();

        if ($this->bypass_reason !== null) {
            if (!headers_sent() && !is_admin()) {
                @header('X-TQK-Cache: BYPASS (' . $this->bypass_reason . ')');
            }
            $this->remember_uncacheable($this->bypass_reason);
            return;
        }

        if (!$this->buffering) {
            $this->buffering = true;
            ob_start([$this, 'handle_buffer']);
        }
    }

    /** First reason this request must not be cached, or null when it can be. */
    private function detect_bypass(): ?string {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return 'method';
        }
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
            return 'context';
        }
        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
            return 'donotcachepage';
        }
        // A script's request (XHR / fetch) for a page url can be answered with a fragment: it must never become THE cached page.
        // Real navigations send Sec-Fetch-Dest "document" (the admin warm-up uses an iframe, link prefetch says it is a prefetch);
        // server-side warm-ups send no Sec-Fetch headers at all.
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            return 'ajax-request';
        }
        $dest = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
        $prefetch = stripos((string)($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ' ' . (string)($_SERVER['HTTP_PURPOSE'] ?? ''), 'prefetch') !== false;
        if ($dest !== '' && !in_array($dest, ['document', 'iframe', 'frame'], true) && !$prefetch) {
            return 'fetch-request';
        }
        if (!empty($_SERVER['PHP_AUTH_USER']) || !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return 'http-auth';
        }

        // Personalised visitor? (same cookie list the drop-in uses; read once, not once per cookie)
        $prefixes = $_COOKIE ? Dropin::runtime_config($this->config)['cookies'] : [];
        foreach (array_keys($_COOKIE) as $name) {
            foreach ($prefixes as $prefix) {
                if (strpos((string)$name, $prefix) === 0) {
                    // The warm-up renders as a guest even though the admin's login cookie is present.
                    if ($this->is_warmup && in_array($prefix, ['wordpress_logged_in_', 'wordpress_sec_'], true)) {
                        continue;
                    }
                    return 'cookie';
                }
            }
        }
        if (!$this->is_warmup && is_user_logged_in()) {
            return 'logged-in';   // personalised pages are never cached (the old cache_logged_in option leaked them to guests)
        }
        if (is_admin_bar_showing()) {
            return 'admin-bar';
        }

        if (is_search() || is_404() || is_feed() || is_preview() || is_customize_preview() || is_robots() || is_trackback() || is_embed()) {
            return 'view-type';
        }
        if (is_singular() && post_password_required()) {
            return 'password-protected';
        }
        foreach (['unapproved', 'moderation-hash', 'preview', 'replytocom'] as $param) {
            if (isset($_GET[$param])) {
                return 'query';
            }
        }

        // WooCommerce
        if (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page())) {
            return 'woocommerce';
        }
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url()) {
            return 'woocommerce';
        }

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string)wp_parse_url($uri, PHP_URL_PATH);
        if (Dropin::path_is_bypassed($path, $uri, Dropin::bypass_needles($this->config))) {
            return 'excluded-url';
        }

        if (!$this->host_is_ours()) {
            return 'foreign-host';
        }
        if ($this->storage->variant($this->get_current_url(), $this->is_mobile()) === null) {
            return 'query-or-path';
        }

        return null;
    }

    /** Output-buffer callback. Whatever goes wrong while optimising, the visitor must still get the original page. */
    public function handle_buffer(string $html): string {
        try {
            return $this->do_handle_buffer($html);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[tqk-wp-cache] page optimisation failed, serving the page untouched: ' . $e->getMessage());
            }
            if (!headers_sent()) {
                @header('X-TQK-Cache: BYPASS (error)');
            }
            return $html;
        }
    }

    private function do_handle_buffer(string $html): string {
        if (strlen($html) < 200 || (stripos($html, '<html') === false && stripos($html, '<!DOCTYPE') === false)) {
            return $html;
        }

        $reason = $this->output_blocks_caching($html);
        if ($reason !== null) {
            if (!headers_sent()) {
                @header('X-TQK-Cache: BYPASS (' . $reason . ')');
            }
            $this->remember_uncacheable($reason);
            return $html;
        }

        // 1. Front-end optimisations
        $openbuilder = OpenBuilder::instance();
        if ($openbuilder->is_active()) {
            $html = $openbuilder->inject_custom_assets($html);
        }

        if ($this->config->get('combine_css') || $this->config->get('combine_js')) {
            $html = (new AssetCombine())->process($html);
        }
        if ($this->config->get('lazy_load')) {
            $html = (new LazyLoad($this->config->get('lazy_load_iframes') == 1, (int)$this->config->get('lcp_hint', 1) === 1))->process($html);
        }
        if ((int)$this->config->get('fonts_swap', 1) === 1) {
            $html = FrontEnd::fonts_swap($html);
        }
        if ((int)$this->config->get('cdn_enabled', 0) === 1 && (string)$this->config->get('cdn_url', '') !== '') {
            $html = FrontEnd::cdn($html, untrailingslashit(site_url()), (string)$this->config->get('cdn_url'), $this->config->lines('cdn_exclusions'));
        }
        if ($this->config->get('delay_js')) {
            $html = (new DelayJs(
                (int)$this->config->get('delay_js_timeout', 0),
                (string)$this->config->get('delay_js_exclusions', '')
            ))->process($html);
        }
        if ($this->config->get('instant_page')) {
            $html = (new InstantPage())->process($html);
        }
        // Minify last. It leaves <script>, <style>, <pre>, <textarea> untouched, so the scripts injected above stay valid.
        if ($this->config->get('html_minify')) {
            $html = HtmlMinify::process($html);
        }

        // 2. Tags for smart purge
        $tags = $this->collect_tags();

        if ($this->config->get('litespeed_headers') && $this->is_litespeed() && !headers_sent()) {
            @header('X-LiteSpeed-Cache-Control: public,max-age=' . ($this->config->get('cache_lifespan', 10) * 3600));
            if (!empty($tags)) {
                @header('X-LiteSpeed-Tag: ' . implode(',', array_slice($tags, 0, 50)));
            }
        }

        // 3. Store
        $stored = $this->storage->write($this->get_current_url(), $html, $tags, $this->is_mobile());
        do_action('tqk_cache_page_stored', $this->get_current_url(), $stored);
        if (!headers_sent()) {
            @header('X-TQK-Cache: ' . ($stored ? 'MISS (stored)' : 'MISS (not stored)'));
        }

        return $html;
    }

    /** Checks that can only be made once the page is rendered (status, headers, markup). */
    private function output_blocks_caching(string $html): ?string {
        if (http_response_code() !== 200) {
            return 'status';
        }
        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
            return 'donotcachepage'; // a plugin may set it while the page renders
        }
        if (stripos($html, 'id="wpadminbar"') !== false || stripos($html, 'class="admin-bar"') !== false) {
            return 'admin-bar';
        }

        $ignored = apply_filters('tqk_cache_ignored_set_cookies', ['pll_language', 'wp-wpml_current_language', 'wpml_browser_redirect_test']);
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $name = trim(strtok(substr($header, 11), '='));
                if (!in_array($name, $ignored, true)) {
                    return 'set-cookie';
                }
            } elseif (stripos($header, 'Cache-Control:') === 0 && preg_match('/no-store|private|no-cache/i', $header)) {
                return 'cache-control';
            }
        }
        return null;
    }

    /**
     * URL-level reasons only (not "this visitor has a cookie"): tell the drop-in not to queue visitors behind a page that will never be stored.
     * 404s are never marked: that would let anybody create files by requesting random URLs.
     */
    private function remember_uncacheable(string $reason): void {
        if (!in_array($reason, ['donotcachepage', 'password-protected', 'set-cookie', 'cache-control', 'woocommerce'], true) || is_404()) {
            return;
        }
        if ($this->host_is_ours()) {
            $this->storage->mark_uncacheable($this->get_current_url(), $this->is_mobile());
        }
    }

    /* ------------------------------------------------------------------ tags */

    /**
     * Precise mode: the MAIN query of a category / tag / attribute archive is described by the TERM (term-<id> tag), so its post type
     * (product) is not tagged as "list-product" for it - otherwise every product change would purge every category page.
     */
    private function list_type_counts(string $type): bool {
        if (!(int)$this->config->get('precise_term_purge', 1)) {
            return true;
        }
        $main = $GLOBALS['wp_query'] ?? null;
        if (!($main instanceof \WP_Query) || !($main->is_category() || $main->is_tag() || $main->is_tax())) {
            return true;
        }
        $obj = $main->get_queried_object();
        $tax = is_object($obj) && !empty($obj->taxonomy) ? get_taxonomy($obj->taxonomy) : null;
        return !($tax && in_array($type, (array)$tax->object_type, true));
    }

    public function collect_posts($posts, $query = null) {
        if (!is_array($posts) || !$posts || count($this->seen_ids) >= self::MAX_TAG_IDS * 2) {
            return $posts;
        }

        $main = $GLOBALS['wp_query'] ?? null;
        // A single post / product page lists related items through secondary queries. Tagging "list-product" there would make
        // every product edit purge every product page, so only the main object counts on those pages.
        $detail_page = $main instanceof \WP_Query && $main->is_singular() && !$main->is_page();
        $is_main = $query instanceof \WP_Query && $query->is_main_query();

        foreach ($posts as $post) {
            if (!is_object($post) || empty($post->ID)) {
                continue;
            }
            $this->seen_ids[(int)$post->ID] = true;
            if (!$is_main && $detail_page) {
                continue;
            }
            // Only the MAIN query of a term archive is described by its term tag. A secondary list (a "latest products" widget in a sidebar,
            // a block in the page) is a dependency the scan has found: that page stays tagged list-<type>.
            if ((!$detail_page || !$is_main) && (!$is_main || $this->list_type_counts((string)$post->post_type))) {
                $this->seen_list_types[$post->post_type] = true;
            }
        }
        return $posts;
    }

    /**
     * `the_posts` never runs for queries that skip filters (get_posts(), fields=ids, WooCommerce product lists...), so a homepage block
     * built that way was not tagged and kept showing a deleted product. `posts_pre_query` runs for every query, before the result is
     * known: the post TYPES it reads become `list-<type>` tags (coarser than post ids, but never wrong).
     */
    public function collect_query_types($posts, $query = null) {
        if (!($query instanceof \WP_Query) || $query->is_main_query()) {
            return $posts;
        }
        $main = $GLOBALS['wp_query'] ?? null;
        if ($main instanceof \WP_Query && $main->is_singular() && !$main->is_page()) {
            return $posts;   // same rule as collect_posts(): related items on a detail page do not make it depend on the whole list
        }
        $types = array_filter((array)$query->get('post_type'));
        if (!$types) {
            $types = ['post'];
        }
        if (in_array('any', $types, true)) {
            $types = array_values(get_post_types(['public' => true]));
        }
        foreach ($types as $type) {
            if (is_string($type) && $type !== '' && count($this->seen_list_types) < 40) {
                $this->seen_list_types[$type] = true;
            }
        }
        return $posts;
    }

    /** True while a page is being built for the cache (an add-on reading SQL needs to know). */
    public function is_buffering(): bool {
        return $this->buffering;
    }

    /** A list of this post type was shown on the page being built: the page then refreshes when any post of that type changes. */
    public function note_list_type(string $type): void {
        if ($type !== '' && count($this->seen_list_types) < 40) {
            $this->seen_list_types[$type] = true;
        }
    }

    private function collect_tags(): array {
        $tags = ['all'];

        if (is_front_page() || is_home()) {
            $tags[] = 'frontpage';
        }

        if (is_singular()) {
            $post_id = get_queried_object_id();
            if ($post_id) {
                foreach (get_object_taxonomies(get_post_type($post_id)) as $tax) {
                    $terms = wp_get_post_terms($post_id, $tax, ['fields' => 'ids']);
                    if (!is_wp_error($terms)) {
                        foreach ($terms as $term_id) {
                            $tags[] = 'term-' . $term_id;
                        }
                    }
                }
            }
        } elseif (is_category() || is_tag() || is_tax()) {
            $term_id = get_queried_object_id();
            if ($term_id) {
                $tags[] = 'term-' . $term_id;
            }
        } elseif (is_author()) {
            $author_id = get_queried_object_id();
            if ($author_id) {
                $tags[] = 'author-' . $author_id;
            }
        }

        foreach (array_slice(array_keys($this->seen_ids), 0, self::MAX_TAG_IDS) as $id) {
            $tags[] = 'post-' . $id;
        }
        foreach (array_keys($this->seen_list_types) as $type) {
            $tags[] = 'list-' . $type;
        }

        // An add-on may add its own tags (TQK WP Cache PRO: `tbl-<table>` for the tables the page counted).
        $tags = apply_filters('tqk_cache_page_tags', $tags);

        return array_values(array_unique((array)$tags));
    }

    /* ------------------------------------------------------------------ helpers */

    private function is_mobile(): bool {
        return (int)$this->config->get('cache_mobile', 0) === 1 && wp_is_mobile();
    }

    private function is_litespeed(): bool {
        $server = $_SERVER['SERVER_SOFTWARE'] ?? '';
        return stripos($server, 'LiteSpeed') !== false;
    }

    /** The Host header must belong to this site: a forged Host would otherwise create cache entries (and fill the disk) for any name. */
    private function host_is_ours(): bool {
        $host = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($host === '') {
            return false;
        }
        $allowed = [];
        foreach ([home_url(), site_url()] as $u) {
            $h = strtolower((string)wp_parse_url($u, PHP_URL_HOST));
            if ($h !== '') {
                $allowed[] = $h;
                $allowed[] = strpos($h, 'www.') === 0 ? substr($h, 4) : 'www.' . $h;
            }
        }
        return in_array($host, $allowed, true) || (bool)apply_filters('tqk_cache_allow_host', false, $host);
    }

    private function get_current_url(): string {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        return $scheme . '://' . $host . $uri;
    }
}
