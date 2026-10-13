<?php
namespace TQK_WP_CACHE\Cache;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Integration\Cloudflare;

defined('ABSPATH') || exit;

/**
 * Cache invalidation. Events only QUEUE work; the queue is flushed once at shutdown, de-duplicated, so a bulk import that saves
 * 2,000 products purges each tag once instead of 2,000 times.
 */
class Purge {
    /** After this many distinct tags in one request a full purge is cheaper than purging them one by one. */
    private const FULL_PURGE_THRESHOLD = 300;

    /** Options whose change alters markup on (almost) every page. */
    private const GLOBAL_OPTIONS = [
        'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'permalink_structure', 'category_base',
        'tag_base', 'posts_per_page', 'date_format', 'time_format', 'timezone_string', 'gmt_offset', 'WPLANG', 'sidebars_widgets',
        'stylesheet', 'template', 'site_icon', 'custom_logo', 'users_can_register', 'default_comment_status', 'comment_registration',
    ];

    /**
     * Post types that hold templates / layouts of page builders and block themes. They are never displayed through a query on the page
     * that uses them (the builder loads them by id), so the page tags cannot tell which pages depend on them: saving one purges everything.
     */
    public const TEMPLATE_TYPES = [
        'elementor_library', 'elementor_snippet', 'elementor_font', 'e-landing-page', 'et_pb_layout', 'et_template', 'et_header_layout',
        'et_body_layout', 'et_footer_layout', 'bricks_template', 'fusion_tb_layout', 'fusion_tb_section', 'fusion_element', 'fl-theme-layout',
        'jet-theme-core', 'oxy_user_library', 'ct_template', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_block',
        'nav_menu_item', 'custom_css',
    ];

    /** Meta keys that change without the content changing (WordPress internals, builder caches, view counters): they never refresh a page. */
    private const META_IGNORED_EXACT = [
        '_edit_lock', '_edit_last', '_encloseme', '_pingme', '_thumbnail_id', '_wp_old_slug', '_wp_old_date', '_oembed_', '_elementor_css',
        '_elementor_page_assets', '_elementor_element_cache', '_elementor_controls_usage', '_elementor_edit_mode', '_elementor_version',
        'views', 'post_views', 'post_views_count', '_post_views', '_post_views_count', 'wpb_post_views_count', 'entry_views', '_entry_views',
        'pvc_views', '_total_views', 'total_views', 'hits', '_hits', 'post_hit_count', '_wpp_views', 'tie_views', 'wp_postviews',
    ];
    private const META_IGNORED_PREFIX = ['_wp_', '_menu_item_', '_oembed_', '_edit_', '_elementor_css', '_transient_'];

    private const META_FLUSH_HOOK = 'tqk_cache_meta_flush';
    private const META_PENDING    = 'tqk_cache_meta_pending';

    private DiskStorage $storage;
    private Config $config;

    private array $tags = [];
    private array $urls = [];
    private bool $all = false;
    private bool $flush_hooked = false;

    public function __construct() {
        $this->storage = new DiskStorage();
        $this->config = Config::instance();
    }

    /**
     * PRO "smart purge": the homepage is no longer purged just because something was published. It is purged when it SHOWED the changed
     * content, which the page tags (post ids, post types listed, terms) already say.
     */
    private function smart(): bool {
        return (bool)apply_filters('tqk_cache_smart_purge', false);
    }

    /** Does a change of this post meta key leave the visible page alone? $extra = the site owner's own list. */
    public static function meta_ignored(string $key, array $extra = []): bool
    {
        if ($key === '' || in_array($key, self::META_IGNORED_EXACT, true) || in_array($key, $extra, true)) {
            return true;
        }
        foreach (self::META_IGNORED_PREFIX as $prefix) {
            if (strncmp($key, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }
        return false;
    }

    public function init(): void {
        if ($this->config->get('purge_on_post_update')) {
            add_action('transition_post_status', [$this, 'on_transition_post_status'], 10, 3);
            add_action('wp_trash_post', [$this, 'on_post_delete']);
            add_action('before_delete_post', [$this, 'on_post_delete']);
            add_action('untrashed_post', [$this, 'on_post_change']);
            add_action('set_object_terms', [$this, 'on_object_terms'], 10, 6);

            // A like / vote / rating changes a post meta without saving the post. Refreshed with a cool-down, see on_meta_change().
            foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
                add_action($hook, [$this, 'on_meta_change'], 10, 3);
            }
        }
        add_action(self::META_FLUSH_HOOK, [$this, 'flush_pending_meta']);

        // Comments (the `transition_comment_status` action passes ($new, $old, $comment): the old code read the first argument as an ID).
        add_action('comment_post', [$this, 'on_comment_post'], 10, 2);
        add_action('wp_insert_comment', [$this, 'on_comment_inserted'], 10, 2);   // imports / WP-CLI / REST insert without comment_post
        add_action('edit_comment', [$this, 'on_comment_change']);
        add_action('wp_set_comment_status', [$this, 'on_comment_change']);
        add_action('transition_comment_status', [$this, 'on_comment_transition'], 10, 3);
        add_action('deleted_comment', [$this, 'on_comment_change']);
        add_action('trashed_comment', [$this, 'on_comment_change']);

        // Terms
        add_action('created_term', [$this, 'on_term_change'], 10, 3);
        add_action('edited_term', [$this, 'on_term_change'], 10, 3);
        add_action('delete_term', [$this, 'on_term_change'], 10, 3);

        // WooCommerce stock / price changes happen without save_post
        foreach (['woocommerce_product_set_stock', 'woocommerce_variation_set_stock'] as $hook) {
            add_action($hook, [$this, 'on_wc_stock']);
        }
        foreach (['woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status'] as $hook) {
            add_action($hook, [$this, 'on_wc_stock_status'], 10, 1);
        }

        // Site wide appearance
        add_action('switch_theme', [$this, 'queue_all']);
        add_action('wp_update_nav_menu', [$this, 'queue_all']);
        add_action('customize_save_after', [$this, 'queue_all']);
        add_action('activated_plugin', [$this, 'queue_all']);
        add_action('deactivated_plugin', [$this, 'queue_all']);
        add_action('upgrader_process_complete', [$this, 'queue_all']);
        add_action('updated_option', [$this, 'on_option_updated'], 10, 1);
    }

    /* ------------------------------------------------------------------ queue */

    public function queue_tag(string $tag): void {
        $this->tags[$tag] = true;
        $this->hook_flush();
    }

    public function queue_url(string $url): void {
        $this->urls[$url] = true;
        $this->hook_flush();
    }

    public function queue_all(): void {
        $this->all = true;
        $this->hook_flush();
    }

    private function hook_flush(): void {
        if (!$this->flush_hooked) {
            $this->flush_hooked = true;
            add_action('shutdown', [$this, 'flush'], 5);
        }
    }

    public function flush(): void {
        if ($this->all || count($this->tags) > self::FULL_PURGE_THRESHOLD) {
            $this->purge_all();
        } else {
            foreach (array_keys($this->tags) as $tag) {
                $this->storage->purge_by_tag((string)$tag);
            }
            foreach (array_keys($this->urls) as $url) {
                $this->storage->purge_url((string)$url);
            }
            $this->send_litespeed_purge(array_keys($this->tags));
            $purged = $this->storage->take_purged_urls();
            // Add-ons: TQK WP Cache PRO rebuilds these pages in the background.
            do_action('tqk_cache_pages_purged', $purged);
            // The edge (Cloudflare) holds copies of the same pages: purge those, plus every URL named directly.
            (new Cloudflare($this->config))->enqueue(array_merge($purged, array_keys($this->urls)));
        }
        $this->tags = [];
        $this->urls = [];
        $this->all = false;
        do_action('tqk_cache_purged');
    }

    /* ------------------------------------------------------------------ events */

    public function on_transition_post_status($new_status, $old_status, $post): void {
        if (!($post instanceof \WP_Post)) {
            return;
        }
        // Drafts, pending, private edits and revisions do not change what anonymous visitors see.
        if ($new_status !== 'publish' && $old_status !== 'publish') {
            return;
        }
        $this->on_post_change($post->ID);
    }

    public function on_post_change($post_id): void {
        $post_id = (int)$post_id;
        if (!$post_id || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        $post_type = get_post_type($post_id);
        $type_obj = $post_type ? get_post_type_object($post_type) : null;
        if (!$type_obj || (!$type_obj->public && !$type_obj->publicly_queryable)) {
            return;
        }

        if (in_array($post_type, (array)apply_filters('tqk_cache_template_post_types', self::TEMPLATE_TYPES), true)) {
            $this->queue_all();   // a header / footer / global template is on pages the tags cannot name
            return;
        }

        $this->queue_tag('post-' . $post_id);
        $this->queue_tag('list-' . $post_type);

        foreach (get_object_taxonomies($post_type) as $tax) {
            $terms = wp_get_post_terms($post_id, $tax, ['fields' => 'ids']);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term_id) {
                    $this->queue_term_with_parents((int)$term_id, (string)$tax);
                }
            }
        }

        $author = (int)get_post_field('post_author', $post_id);
        if ($author) {
            $this->queue_tag('author-' . $author);
        }

        if ($this->config->get('purge_frontpage') && !$this->smart()) {
            $this->queue_tag('frontpage');
            $this->queue_url(home_url('/'));
        }

        $url = get_permalink($post_id);
        if ($url) {
            $this->queue_url($url);
        }
    }

    /**
     * First change of a post's meta: refresh the pages that show the post now. Further changes inside the cool-down are collected and
     * refreshed once when it ends, so a post that is liked 100 times a minute costs one rebuild a minute, not 100.
     */
    public function on_meta_change($meta_id, $post_id, $key = ''): void
    {
        $post_id = (int)$post_id;
        if (!$post_id || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || self::meta_ignored((string)$key, $this->config->lines('meta_ignore_keys'))) {
            return;
        }
        if (wp_is_post_revision($post_id) || get_post_status($post_id) !== 'publish') {
            return;
        }
        $this->queue_with_cooldown('post-' . $post_id);
    }

    /**
     * Queue a tag now if it was not queued during the last cool-down; otherwise remember it and let WP-Cron queue it when the cool-down
     * ends. Used for things that change often (likes, rows of a busy table): one rebuild per tag per cool-down, never one per change.
     */
    public function queue_with_cooldown(string $tag): void
    {
        $cool = max(10, min(3600, (int)$this->config->get('meta_refresh_cooldown', 60)));
        $flag = 'tqk_mc_' . md5($tag);
        if (get_transient($flag) === false) {
            set_transient($flag, 1, $cool);
            $this->queue_tag($tag);
            return;
        }
        $pending = get_option(self::META_PENDING, []);
        $pending = is_array($pending) ? $pending : [];
        if (empty($pending[$tag]) && count($pending) < 500) {
            $pending[$tag] = 1;
            update_option(self::META_PENDING, $pending, false);
        }
        if (!wp_next_scheduled(self::META_FLUSH_HOOK)) {
            wp_schedule_single_event(time() + $cool + 2, self::META_FLUSH_HOOK);
        }
    }

    /** WP-Cron: the cool-down ended, refresh what changed meanwhile. */
    public function flush_pending_meta(): void
    {
        $pending = get_option(self::META_PENDING, []);
        delete_option(self::META_PENDING);
        foreach (array_keys(is_array($pending) ? $pending : []) as $tag) {
            $this->queue_tag((string)$tag);
        }
    }

    /** A category archive also lists the products of its sub-categories, so the parents of a term are refreshed with it. */
    private function queue_term_with_parents(int $term_id, string $taxonomy): void
    {
        $this->queue_tag('term-' . $term_id);
        foreach (get_ancestors($term_id, $taxonomy, 'taxonomy') as $parent) {
            $this->queue_tag('term-' . (int)$parent);
        }
    }

    /**
     * The terms of a PUBLISHED post changed (moved from one category to another, quick edit, bulk edit, an import): the archive it left
     * and the archive it joined both change. Needs the old terms, which only this hook knows.
     */
    public function on_object_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids): void
    {
        $object_id = (int)$object_id;
        if (!$object_id || wp_is_post_revision($object_id) || get_post_status($object_id) !== 'publish') {
            return;
        }
        foreach (array_unique(array_merge((array)$tt_ids, (array)$old_tt_ids)) as $tt) {
            $term = get_term_by('term_taxonomy_id', (int)$tt, (string)$taxonomy);
            if ($term && !is_wp_error($term)) {
                $this->queue_term_with_parents((int)$term->term_id, (string)$taxonomy);
            }
        }
    }

    public function on_post_delete($post_id): void {
        // Moving to the trash already refreshed the pages (the post was still published then). Emptying the trash, or deleting a draft, changes nothing visitors see.
        if (get_post_status((int)$post_id) !== 'publish') {
            return;
        }
        $this->on_post_change($post_id);
    }

    public function on_comment_post($comment_id, $approved = 1): void {
        // Unapproved comments are invisible to anonymous visitors.
        if ((string)$approved === '1' || $approved === 1) {
            $this->on_comment_change($comment_id);
        }
    }

    public function on_comment_inserted($comment_id, $comment = null): void {
        if ($comment instanceof \WP_Comment && (string)$comment->comment_approved === '1') {
            $this->purge_post_of_comment($comment->comment_post_ID);
        }
    }

    public function on_comment_transition($new_status, $old_status, $comment): void {
        if ($comment instanceof \WP_Comment) {
            $this->purge_post_of_comment($comment->comment_post_ID);
        }
    }

    public function on_comment_change($comment_id): void {
        $comment = get_comment($comment_id);
        if ($comment instanceof \WP_Comment) {
            $this->purge_post_of_comment($comment->comment_post_ID);
        }
    }

    private function purge_post_of_comment($post_id): void {
        $post_id = (int)$post_id;
        if ($post_id) {
            $this->queue_tag('post-' . $post_id);
            $url = get_permalink($post_id);
            if ($url) {
                $this->queue_url($url);
            }
        }
    }

    public function on_term_change($term_id): void {
        $this->queue_tag('term-' . (int)$term_id);
        if ($this->config->get('purge_frontpage') && !$this->smart()) {
            $this->queue_tag('frontpage');
        }
    }

    public function on_wc_stock($product): void {
        $this->purge_wc_product($product);
    }

    public function on_wc_stock_status($product_id): void {
        $this->purge_wc_product($product_id);
    }

    private function purge_wc_product($product): void {
        $id = is_object($product) && method_exists($product, 'get_id') ? (int)$product->get_id() : (int)$product;
        if (!$id) {
            return;
        }
        $parent = (int)wp_get_post_parent_id($id);
        $this->queue_tag('post-' . ($parent ?: $id));
        $this->queue_tag('list-product');
    }

    public function on_option_updated($option): void {
        $option = (string)$option;
        if (in_array($option, self::GLOBAL_OPTIONS, true) || strpos($option, 'widget_') === 0) {
            $this->queue_all();
        }
    }

    /* ------------------------------------------------------------------ direct API (admin buttons, tests) */

    public function purge_all(): bool {
        $ok = $this->storage->purge_all();
        (new Cloudflare($this->config))->enqueue([], true);
        $this->send_litespeed_purge(['*']);
        return $ok;
    }

    public function purge_url(string $url): bool {
        return $this->storage->purge_url($url);
    }

    private function send_litespeed_purge(array $tags): void {
        if (!$this->is_litespeed() || headers_sent() || !$tags) {
            return;
        }
        @header('X-LiteSpeed-Purge: ' . implode(',', array_slice($tags, 0, 50)));
    }

    private function is_litespeed(): bool {
        return stripos((string)($_SERVER['SERVER_SOFTWARE'] ?? ''), 'LiteSpeed') !== false;
    }
}
