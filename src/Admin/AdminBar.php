<?php
namespace TQK_WP_CACHE\Admin;

defined('ABSPATH') || exit;

class AdminBar {
    public function init(): void {
        add_action('admin_bar_menu', [$this, 'add_toolbar_items'], 100);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_admin_bar_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_bar_scripts']);
    }

    public function add_toolbar_items(\WP_Admin_Bar $admin_bar): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $admin_bar->add_node([
            'id'    => 'tqk-cache-menu',
            'title' => '<span class="ab-icon dashicons dashicons-performance" style="top:2px;"></span> <span class="ab-label">' . esc_html__('TQK Cache', 'tqk-wp-cache') . '</span>',
            'href'  => admin_url('admin.php?page=tqk-wp-cache'),
        ]);

        $admin_bar->add_node([
            'id'     => 'tqk-cache-purge-all',
            'parent' => 'tqk-cache-menu',
            'title'  => esc_html__('Purge All Cache', 'tqk-wp-cache'),
            'href'   => '#',
            'meta'   => ['class' => 'tqk-purge-all-btn'],
        ]);

        if (!is_admin()) {
            $admin_bar->add_node([
                'id'     => 'tqk-cache-purge-this',
                'parent' => 'tqk-cache-menu',
                'title'  => esc_html__('Purge This Page', 'tqk-wp-cache'),
                'href'   => '#',
                'meta'   => ['class' => 'tqk-purge-current-btn'],
            ]);
        }

        $admin_bar->add_node([
            'id'     => 'tqk-cache-settings',
            'parent' => 'tqk-cache-menu',
            'title'  => esc_html__('TQK Cache Settings', 'tqk-wp-cache'),
            'href'   => admin_url('admin.php?page=tqk-wp-cache'),
        ]);
    }

    public function enqueue_admin_bar_scripts(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        \TQK_WP_CACHE\Admin\Assets::enqueue([
            'current_url'  => !is_admin() ? (isset($_SERVER['REQUEST_URI']) ? home_url($_SERVER['REQUEST_URI']) : home_url('/')) : '',
            'clearing_msg' => __('Clearing cache…', 'tqk-wp-cache'),
        ]);
    }
}
