<?php
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;

class Dashboard {
    private Config $config;
    private DiskStorage $storage;

    public function __construct() {
        $this->config = Config::instance();
        $this->storage = new DiskStorage();
    }

    public function init(): void {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'suppress_hello_dolly'], 1);
    }

    public function suppress_hello_dolly(): void {
        remove_action('admin_notices', 'hello_dolly');
    }

    public function add_menu_page(): void {
        add_menu_page(
            __('TQK Cache', 'tqk-wp-cache'),
            __('TQK Cache', 'tqk-wp-cache'),
            'manage_options',
            'tqk-wp-cache',
            [$this, 'render_view'],
            'dashicons-performance',
            91 // Sits gracefully right below TQK SEO (90) without overriding core menus
        );
    }

    public function get_current_lang(): string {
        $user_id = get_current_user_id();
        $user_lang = $user_id ? get_user_meta($user_id, 'tqk_cache_ui_lang', true) : '';
        if (!$user_lang) {
            $user_lang = get_option('tqk_cache_ui_lang', '');
        }
        if (in_array($user_lang, ['vi', 'en'], true)) {
            return $user_lang;
        }
        $locale = get_user_locale();
        return str_starts_with($locale, 'vi') ? 'vi' : 'en';
    }

    public function enqueue_assets(string $hook): void {
        // Enqueue WP-Admin Benchmark & Turbo Engine across all admin pages
        $turbo_enabled = (int)$this->config->get('admin_turbo', 1) === 1;
        $hud_enabled   = (int)$this->config->get('admin_benchmark_hud', 1) === 1;

        // The script is only needed when Turbo is on, or when the HUD is on (it also times normal loads, for the comparison).
        // With both off nothing is loaded at all.
        if ($turbo_enabled || $hud_enabled) {
            wp_enqueue_script(
                'tqk-cache-admin-turbo',
                TQK_WP_CACHE_URL . 'assets/js/admin-turbo.js',
                ['jquery'],
                TQK_WP_CACHE_VERSION,
                true
            );

            $exclusions_raw = (string)$this->config->get('admin_turbo_exclusions', '');
            $exclusions = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $exclusions_raw))));

            wp_localize_script('tqk-cache-admin-turbo', 'tqkAdminTurboConfig', [
                'enabled'    => $turbo_enabled,
                'show_hud'   => $hud_enabled,
                'exclusions' => array_values($exclusions),
            ]);
        }

        if ($hook !== 'toplevel_page_tqk-wp-cache') {
            return;
        }

        wp_enqueue_style(
            'tqk-cache-admin-css',
            TQK_WP_CACHE_URL . 'assets/css/admin.css',
            [],
            TQK_WP_CACHE_VERSION
        );

        \TQK_WP_CACHE\Admin\Assets::enqueue([
            'clearing_msg' => __('Clearing cache…', 'tqk-wp-cache'),
            'saving_msg'   => __('Saving settings…', 'tqk-wp-cache'),
            'saved_msg'    => __('Settings saved successfully!', 'tqk-wp-cache'),
            'lang'         => $this->get_current_lang(),
            'active_msg'   => __('Active', 'tqk-wp-cache'),
            'off_msg'      => __('Off', 'tqk-wp-cache'),
            'sup_sending'     => __('Sending…', 'tqk-wp-cache'),
            'sup_failed'      => __('The message could not be sent. Reload the page and try again.', 'tqk-wp-cache'),
            'diag_working'    => __('Collecting the report…', 'tqk-wp-cache'),
            'diag_done'       => __('Report downloaded. Send the file to support.', 'tqk-wp-cache'),
            'diag_failed'     => __('The report could not be created. Reload the page and try again.', 'tqk-wp-cache'),
            'rb_lazy_done'    => __('Cache cleared. Pages are rebuilt when visitors open them, so the server does no extra work now.', 'tqk-wp-cache'),
            'rb_missing_none' => __('Nothing to rebuild: every public page (up to the limit) already has a fresh cache.', 'tqk-wp-cache'),
            'rb_queue'        => __('%s pages queued for rebuild.', 'tqk-wp-cache'),
        ]);
    }

    public function render_view(): void {
        $stats = $this->storage->get_stats();
        $is_dropin_active = Dropin::is_active();
        $wp_cache_defined = defined('WP_CACHE') && WP_CACHE;
        $is_engine_ready = $is_dropin_active && $wp_cache_defined;
        $c = $this->config->all();
        $is_pro = $this->config->is_pro();
        $current_lang = $this->get_current_lang();
        ?>
        <div class="wrap tqk-wpseo">
            <?php include __DIR__ . '/Views/header.php'; ?>
            <?php include __DIR__ . '/Views/tab-overview.php'; ?>
            <?php include __DIR__ . '/Views/tab-pagecache.php'; ?>
            <?php include __DIR__ . '/Views/tab-combine.php'; ?>
            <?php include __DIR__ . '/Views/tab-speed.php'; ?>
            <?php include __DIR__ . '/Views/tab-media.php'; ?>
            <?php include __DIR__ . '/Views/tab-database.php'; ?>
            <?php include __DIR__ . '/Views/tab-trash.php'; ?>
            <?php if (has_action('tqk_cache_panes_pro')) : ?>
                <?php do_action('tqk_cache_panes_pro', $this, $c); ?>
            <?php else : ?>
                <?php include __DIR__ . '/Views/tab-pro.php'; ?>
            <?php endif; ?>
            <?php include __DIR__ . '/Views/tab-rules.php'; ?>
            <?php include __DIR__ . '/Views/tab-server.php'; ?>
            <?php include __DIR__ . '/Views/footer.php'; ?>
            <?php include __DIR__ . '/Views/support.php'; ?>
            <?php include __DIR__ . '/Views/modals.php'; ?>
        </div>
        <?php
    }
}
