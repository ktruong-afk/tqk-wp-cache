<?php
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;

defined('ABSPATH') || exit;

/**
 * Optimizes server CPU and eliminates admin slowness caused by:
 * 1. WordPress Heartbeat API (continuous ajax ping every 15s).
 * 2. Background Auto-Update checks (blocking HTTP requests to wordpress.org for plugins, themes, core).
 */
class PerformanceBooster {

    private Config $config;

    public function __construct() {
        $this->config = Config::instance();
    }

    public function init(): void {
        $this->setup_heartbeat();
        $this->setup_auto_updates();
    }

    /**
     * Heartbeat Control: reduces frequency or disables heartbeat where not needed.
     */
    private function setup_heartbeat(): void {
        $mode = $this->config->get('heartbeat_control', 'reduce');
        if ($mode === 'default') {
            return;
        }

        if ($mode === 'disable') {
            // Completely disable heartbeat on frontend and admin dashboard (except post edit)
            add_action('init', function() {
                global $pagenow;
                if ($pagenow !== 'post.php' && $pagenow !== 'post-new.php') {
                    wp_deregister_script('heartbeat');
                }
            }, 1);
            return;
        }

        // Mode: 'reduce' - increase interval from 15s to 60s or 120s
        $interval = (int)$this->config->get('heartbeat_interval', 60);
        if ($interval < 15) $interval = 60;

        add_filter('heartbeat_settings', function($settings) use ($interval) {
            $settings['interval'] = $interval;
            return $settings;
        });

        // Disable heartbeat on frontend completely
        add_action('wp_enqueue_scripts', function() {
            wp_deregister_script('heartbeat');
        }, 1);
    }

    /**
     * Stops WordPress from running its update checks on EVERY admin page view (they block the page for 1-3 seconds while WordPress
     * waits for api.wordpress.org). The checks still run through WP-Cron twice a day and when you open Dashboard > Updates, so
     * available (security) updates are never hidden. The previous version answered those requests with a fake "no updates" reply,
     * which made WordPress believe everything was up to date for 12 hours.
     */
    private function setup_auto_updates(): void {
        if ((int)$this->config->get('disable_auto_updates', 0) !== 1) {
            return;
        }

        global $pagenow;
        if (in_array($pagenow, ['update-core.php', 'update.php', 'plugins.php', 'themes.php'], true)) {
            return; // screens where the admin expects fresh update data
        }

        remove_action('admin_init', '_maybe_update_core');
        remove_action('admin_init', '_maybe_update_plugins');
        remove_action('admin_init', '_maybe_update_themes');
    }
}
