<?php
namespace TQK_WP_CACHE\Core;

defined('ABSPATH') || exit;

class Config {
    private static ?Config $instance = null;
    private array $settings = [];

    /** Settings that hold raw code (CSS/JS). They must never go through text sanitizers: `i<10 && x>5` would be cut to `i5`. */
    public const RAW_KEYS = ['custom_css', 'custom_js'];

    public static function instance(): Config {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load();
    }

    public static function defaults(): array {
        $defaults = [
            // Page Cache Engine
            'page_cache'           => 1,
            'cache_lifespan'       => 10, // hours
            'cache_logged_in'      => 0,  // DEPRECATED: logged-in pages are never cached (kept so old option rows stay valid)
            'cache_mobile'         => 0,  // separate cache for mobile devices
            'gzip_compression'     => 1,
            'browser_cache'        => 1,

            // Core Web Vitals & Assets
            'combine_css'          => 1,
            'combine_js'           => 1,
            'combine_exclusions'   => "admin-bar\nwp-tinymce",
            'delay_js'             => 1,
            'delay_js_timeout'     => 0, // 0 = wait for user interaction
            'delay_js_exclusions'  => "jquery.min.js\njquery.js\ntqk-wp-cache",
            'lazy_load'            => 1,
            'lazy_load_iframes'    => 1,
            'instant_page'         => 1, // Preload on hover / touchstart
            'fonts_swap'           => 1, // Google Fonts: display=swap + preconnect
            'lcp_hint'             => 1, // fetchpriority=high on the likely LCP image
            'cdn_enabled'          => 0,
            'cdn_url'              => '', // https://cdn.example.com (a CDN that mirrors this site, or a pull zone)
            'cdn_exclusions'       => '', // URLs containing one of these lines stay on the site
            'cf_enabled'           => 0,  // Cloudflare: purge the same pages at the edge
            'cf_zone'              => '', // Zone ID (32 hex)
            'cf_token'             => '', // API token with "Zone > Cache Purge" only; never printed back into a page
            'html_minify'          => 1,

            // Presets & Active State
            'active_preset'        => 'balanced',

            // Custom Code (Fallback when OpenBuilder not active, or merged together)
            'custom_css'           => '',
            'custom_js'            => '',

            // Exclusion & Compatibility
            'excluded_urls'        => "/cart\n/checkout\n/my-account\n/wp-admin\n/wp-login.php",
            'excluded_cookies'     => '',
            // Query-string keys that are allowed to create their own cache entry. Any other key bypasses the cache
            // (so `?junk=1..N` cannot fill the disk). Marketing parameters (utm_*, fbclid...) are ignored for the cache key.
            'cache_query_allow'    => "p\npage_id\ncat\ntag\nm\npaged\npage\ncpage\npost_type\nattachment_id\nlang",
            'purge_on_post_update' => 1,
            'purge_frontpage'      => 1,
            // A category / tag / attribute archive depends on its own term (and its parents), not on "every product": publishing a product
            // refreshes only the archives of ITS terms. A page that ALSO shows another list of that type (a sidebar "latest products", a block)
            // is still tagged with it, because the scan sees that list: such a page refreshes on any change of that type.
            'precise_term_purge'   => 1,
            // How "Run Purge & Cache Warmup" rebuilds the cache: 'essential' (home + key pages, re-created), 'missing' (every public page
            // that is not cached yet, up to warmup_limit) or 'lazy' (purge only, pages are rebuilt by the visitors).
            'rebuild_mode'         => 'essential',
            'warmup_limit'         => 200,
            'litespeed_headers'    => 1, // Emit X-LiteSpeed headers if server supports it

            // WP-Admin Turbo Engine (SPA PushState & Smart Asset Ingestion)
            'admin_turbo'            => 1,
            'admin_turbo_exclusions' => "post.php\npost-new.php\nupdate.php\nupdate-core.php\nplugin-install.php\ntheme-install.php\ncustomize.php\nwidgets.php\nnav-menus.php",
            'admin_benchmark_hud'    => 1, // Show Floating Speed Stopwatch & Comparison HUD in WP-Admin

            // WP-Admin Resource Optimization (Heartbeat & Auto Update Checks)
            'heartbeat_control'      => 'reduce', // 'default', 'reduce', 'disable'
            'heartbeat_interval'     => 60,       // 60s or 120s (default WP is 15-60s)
            // Skips the update checks WordPress runs on every admin page view. Real checks still run through WP-Cron twice a day,
            // so this never hides available (security) updates. OFF by default.
            'disable_auto_updates'   => 0,
            // Outgoing requests made while building an admin page (see Admin/HttpControl.php). All OFF by default.
            'admin_http_block_telemetry' => 0,
            'admin_http_cache'           => 0,
            'admin_http_cache_ttl'       => 12, // hours
            'admin_http_skip_cron'       => 0,
            'admin_http_extra_block'     => '',
            'admin_http_extra_cache'     => '',
            // Likes / votes: a post meta change refreshes the pages that show the post, at most once per cool-down (seconds).
            'meta_refresh_cooldown'      => 60,
            'meta_ignore_keys'           => '', // extra meta keys that never refresh a page (one per line), e.g. a view counter
            'live_meta_keys'             => '', // meta keys [tqk_live source="meta"] may show (one per line)
        ];
        // An add-on (TQK WP Cache PRO) adds its own settings here.
        return function_exists('apply_filters') ? (array)apply_filters('tqk_cache_defaults', $defaults) : $defaults;
    }

    public static function get_cache_file_path(): string {
        // Tests point this at a temp file so running them can never touch the live site's settings.
        return defined('TQK_WP_CACHE_CONFIG_FILE') ? (string)TQK_WP_CACHE_CONFIG_FILE : dirname(__DIR__, 2) . '/data/config-cache.php';
    }

    public function load(): void {
        $cache_file = self::get_cache_file_path();
        $stored = null;

        // Ultra-fast PHP opcode cache read without database overhead
        if (file_exists($cache_file)) {
            try {
                $stored = include $cache_file;
            } catch (\Throwable $e) {
                $stored = null;
            }
        }

        // Fallback to database wp_options if file doesn't exist or corrupted
        if (!is_array($stored) || empty($stored)) {
            $stored = get_option(TQK_WP_CACHE_OPTION, []);
            if (!is_array($stored)) {
                $stored = [];
            }
            // Self-heal: dump into config cache file if option existed in DB
            if (!empty($stored)) {
                $this->write_cache_file($stored);
            }
        }

        $this->settings = wp_parse_args($stored, self::defaults());
    }

    public function get(string $key, $default = null) {
        return $this->settings[$key] ?? $default;
    }

    public function set(string $key, $value): void {
        $this->settings[$key] = $value;
    }

    public function all(): array {
        return $this->settings;
    }

    /**
     * Save settings. Keys that are NOT in $new_settings keep their current value (the old code reset them to 0 / '',
     * which silently switched PRO off every time the settings form was saved).
     */
    public function save(array $new_settings): bool {
        $defaults = self::defaults();
        $clean = [];

        foreach ($defaults as $key => $default_val) {
            $current = $this->settings[$key] ?? $default_val;

            if (!array_key_exists($key, $new_settings)) {
                $clean[$key] = $current;
                continue;
            }

            $value = $new_settings[$key];

            if (in_array($key, self::RAW_KEYS, true)) {
                $clean[$key] = is_string($value) ? $value : '';
            } elseif (is_int($default_val)) {
                $clean[$key] = (int)$value;
            } elseif (is_string($default_val)) {
                $clean[$key] = is_scalar($value) ? sanitize_textarea_field((string)$value) : $default_val;
            } else {
                $clean[$key] = $value;
            }
        }

        // Settings of an add-on that is switched off right now are not in $defaults, but must survive a save.
        foreach ($this->settings as $key => $value) {
            if (!array_key_exists($key, $clean)) {
                $clean[$key] = $value;
            }
        }

        $clean['cache_logged_in'] = 0; // never cache personalised pages

        $this->settings = $clean;

        // 1. Atomic write to native PHP config cache file
        $this->write_cache_file($clean);

        // 2. Persist to WP database options
        return update_option(TQK_WP_CACHE_OPTION, $clean);
    }

    private function write_cache_file(array $data): bool {
        $file = self::get_cache_file_path();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $content = "<?php\n// Auto-generated by TQK WP Cache - Do not edit directly\ndefined('ABSPATH') || exit;\nreturn " . var_export($data, true) . ";\n";
        $tmp = $file . '.tmp.' . uniqid('', true);

        if (@file_put_contents($tmp, $content, LOCK_EX) !== false) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions
            $success = @rename($tmp, $file);
            if (!$success) {
                @wp_delete_file($tmp);
                return (bool)@file_put_contents($file, $content, LOCK_EX);
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
            return true;
        }

        return false;
    }

    /** True when an add-on (TQK WP Cache PRO) says this site is licensed. The free plugin has no licence code of its own. */
    public function is_pro(): bool {
        return function_exists('apply_filters') && (bool)apply_filters('tqk_cache_is_pro', false);
    }

    /** Lines of a textarea setting as a clean array. */
    public function lines(string $key): array {
        $raw = str_replace("\r", '', (string)$this->get($key, ''));
        return array_values(array_filter(array_map('trim', explode("\n", $raw)), static fn($l) => $l !== ''));
    }
}