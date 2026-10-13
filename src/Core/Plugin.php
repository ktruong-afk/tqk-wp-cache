<?php
namespace TQK_WP_CACHE\Core;

use TQK_WP_CACHE\Cache\Engine;
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

class Plugin {
    use Ajax\CacheAjax;
    use Ajax\PreloadAjax;
    use Ajax\DatabaseAjax;
    use Ajax\TrashAjax;
    use Ajax\CloudflareAjax;

    private static ?Plugin $instance = null;
    public Config $config;
    public Engine $engine;
    public Purge $purge;

    public static function instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->config = Config::instance();
        $this->engine = new Engine();
        $this->purge = new Purge();
    }

    public function boot(): void {
        // Init Cache Engine, Purge listeners & OpenBuilder integration
        $this->engine->init();
        $this->purge->init();
        OpenBuilder::instance()->init();
        (new Maintenance())->init();
        (new \TQK_WP_CACHE\Admin\IncidentLog())->init();   // every request: a fatal error in this plugin is caught at shutdown
        add_action(\TQK_WP_CACHE\Integration\Cloudflare::HOOK, [\TQK_WP_CACHE\Integration\Cloudflare::class, 'run']);   // WP-Cron: purge the same pages at Cloudflare
        (new \TQK_WP_CACHE\Optimization\LiveCounters())->init();

        if (is_admin()) {
            add_action('admin_init', [Dropin::class, 'sync']);   // regenerate the drop-in when the plugin was updated or settings changed elsewhere
            add_action('admin_notices', [$this, 'admin_notices']);
        }
        // Admin & Toolbar
        if (is_admin()) {
            (new Dashboard())->init();
            (new \TQK_WP_CACHE\Admin\PerformanceBooster())->init();
            (new \TQK_WP_CACHE\Admin\HttpControl())->init();
            (new \TQK_WP_CACHE\Admin\Diagnostics())->init();
            (new \TQK_WP_CACHE\Admin\Support())->init();
        }
        (new AdminBar())->init();

        // AJAX handlers (SPA & Toolbar operations)
        add_action('wp_ajax_tqk_cache_purge_all', [$this, 'ajax_purge_all']);
        add_action('wp_ajax_tqk_cache_purge_current', [$this, 'ajax_purge_current']);
        add_action('wp_ajax_tqk_cache_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_tqk_cache_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_tqk_cache_preload_start', [$this, 'ajax_preload_start']);
        add_action('wp_ajax_tqk_cache_preload_step', [$this, 'ajax_preload_step']);
        add_action('wp_ajax_tqk_cache_preload_verify', [$this, 'ajax_preload_verify']);
        add_action('wp_ajax_tqk_cache_switch_lang', [$this, 'ajax_switch_lang']);

        // Database & Revisions Analyzer AJAX
        add_action('wp_ajax_tqk_cache_db_get_revisions', [$this, 'ajax_db_get_revisions']);
        add_action('wp_ajax_tqk_cache_db_clean_revisions', [$this, 'ajax_db_clean_revisions']);
        add_action('wp_ajax_tqk_cache_db_get_junk_stats', [$this, 'ajax_db_get_junk_stats']);
        add_action('wp_ajax_tqk_cache_db_clean_junk', [$this, 'ajax_db_clean_junk']);

        // Trash Manager AJAX
        add_action('wp_ajax_tqk_cache_trash_get_summary', [$this, 'ajax_trash_get_summary']);
        add_action('wp_ajax_tqk_cache_trash_get_items', [$this, 'ajax_trash_get_items']);
        add_action('wp_ajax_tqk_cache_trash_restore_items', [$this, 'ajax_trash_restore_items']);
        add_action('wp_ajax_tqk_cache_trash_delete_items', [$this, 'ajax_trash_delete_items']);
        add_action('wp_ajax_tqk_cache_trash_empty_type', [$this, 'ajax_trash_empty_type']);

        // Cloudflare
        add_action('wp_ajax_tqk_cache_cf_test', [$this, 'ajax_cf_test']);

        // Add-ons (TQK WP Cache PRO) hook in here: they register their own filters, actions and AJAX handlers.
        do_action('tqk_cache_loaded', $this);
    }

    /** Shown on admin screens when the static drop-in cannot work. */
    public function admin_notices(): void {
        if (!current_user_can('manage_options') || !$this->config->get('page_cache')) {
            return;
        }
        $status = Dropin::status();
        if ($status === 'foreign') {
            echo '<div class="notice notice-warning"><p><strong>TQK WP Cache:</strong> '
                . esc_html__('Another plugin already owns wp-content/advanced-cache.php, so TQK static caching is inactive. Disable the other page-cache plugin, then save the TQK settings again.', 'tqk-wp-cache')
                . '</p></div>';
        } elseif ($status === 'missing') {
            echo '<div class="notice notice-warning"><p><strong>TQK WP Cache:</strong> '
                . esc_html__('The static drop-in file is missing. Open the TQK WP Cache settings and press Save to reinstall it.', 'tqk-wp-cache')
                . '</p></div>';
        } elseif (!defined('WP_CACHE') || !WP_CACHE) {
            echo '<div class="notice notice-warning"><p><strong>TQK WP Cache:</strong> '
                . esc_html__('WP_CACHE is not enabled in wp-config.php (the file may not be writable), so WordPress will not load the drop-in. Add define( \'WP_CACHE\', true ); to wp-config.php.', 'tqk-wp-cache')
                . '</p></div>';
        }
    }

    /** Only URLs of this very site may be purged / warmed up: the warm-up makes a server-side request, so anything else is an SSRF vector. */
    private function is_own_url(string $url): bool {
        $host = strtolower((string)wp_parse_url($url, PHP_URL_HOST));
        $own = strtolower((string)wp_parse_url(home_url(), PHP_URL_HOST));
        if ($host === '' || $own === '') {
            return false;
        }
        $strip = static fn($h) => strpos($h, 'www.') === 0 ? substr($h, 4) : $h;
        return $strip($host) === $strip($own);
    }

    /* =========================================================================
     * Database & Revisions Analyzer AJAX
     * ========================================================================= */

    /* =========================================================================
     * Trash Manager AJAX (with WooCommerce acceleration)
     * ========================================================================= */

    /* =========================================================================
     * Image Optimization AJAX
     * ========================================================================= */

}
