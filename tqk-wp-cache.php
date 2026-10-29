<?php
/**
 * Plugin Name: TQK WP Cache
 * Plugin URI: https://www.facebook.com/Unk.khai/
 * Description: High-Performance Page Caching & Core Web Vitals Optimization Engine. Combines lightweight server-level speed with smart optimizations (Delay JS, Instant Preload, Tag-based Purge, Dynamic Fragment Bypass).
 * Version: 1.0.0
 * Author: Trương Quang Khải
 * Author URI: https://www.facebook.com/Unk.khai/
 * Text Domain: tqk-wp-cache
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

define('TQK_WP_CACHE_VERSION', '1.0.0');
define('TQK_WP_CACHE_FILE', __FILE__);
define('TQK_WP_CACHE_DIR', plugin_dir_path(__FILE__));
define('TQK_WP_CACHE_URL', plugin_dir_url(__FILE__));
define('TQK_WP_CACHE_BASENAME', plugin_basename(__FILE__));
define('TQK_WP_CACHE_STORAGE_DIR', WP_CONTENT_DIR . '/cache/tqk-cache');
define('TQK_WP_CACHE_OPTION', 'tqk_wp_cache_settings');
// Version of the hooks add-ons (TQK WP Cache PRO) can rely on. Raised when a filter / action changes in a way that breaks them.
define('TQK_WP_CACHE_API', 2);

// PSR-4 style autoloader for TQK_WP_CACHE namespace
spl_autoload_register(function ($class) {
    $prefix = 'TQK_WP_CACHE\\';
    $base_dir = TQK_WP_CACHE_DIR . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * The language the user picked with the VI | EN switch of this plugin: 'vi', 'en_US', or null when nothing was picked (then WordPress decides).
 * Opening the screen with ?lang=vi|en also stores the choice. Decided once per request.
 */
function tqk_wp_cache_ui_locale(): ?string {
    static $done = false, $locale = null;
    if ($done) {
        return $locale;
    }
    if (!function_exists('get_current_user_id')) {
        return null;   // too early in the request to know the user; do not remember this answer
    }
    $done = true;

    if (isset($_GET['page']) && $_GET['page'] === 'tqk-wp-cache' && isset($_GET['lang']) && in_array($_GET['lang'], ['vi', 'en'], true)) {
        $lang = sanitize_key($_GET['lang']);
        if (is_user_logged_in() && current_user_can('manage_options')) {
            update_user_meta(get_current_user_id(), 'tqk_cache_ui_lang', $lang);
            update_option('tqk_cache_ui_lang', $lang);
        }
        return $locale = ($lang === 'vi' ? 'vi' : 'en_US');
    }

    $user_id = get_current_user_id();
    $user_lang = $user_id ? get_user_meta($user_id, 'tqk_cache_ui_lang', true) : '';
    if (!$user_lang) {
        $user_lang = get_option('tqk_cache_ui_lang', '');
    }
    if ($user_lang === 'vi') {
        return $locale = 'vi';
    }
    if ($user_lang === 'en') {
        return $locale = 'en_US';
    }
    return null;
}

// Locale override for TQK WP Cache (Allows instant VI | EN switching). The add-on (TQK WP Cache PRO) asks the same question for its own domain.
add_filter('plugin_locale', function ($locale, $domain) {
    return $domain === 'tqk-wp-cache' ? (tqk_wp_cache_ui_locale() ?: $locale) : $locale;
}, 10, 2);

// Since WordPress 6.5 a translation file is only used when its language is the CURRENT locale, so choosing the file (plugin_locale) is not
// enough: on this plugin's own screen and its AJAX calls the locale itself is the one the user picked.
add_filter('determine_locale', function ($locale) {
    $own_screen = isset($_GET['page']) && $_GET['page'] === 'tqk-wp-cache';
    $own_ajax   = function_exists('wp_doing_ajax') && wp_doing_ajax() && isset($_REQUEST['action']) && strpos((string)$_REQUEST['action'], 'tqk_cache_') === 0;
    return ($own_screen || $own_ajax) ? (tqk_wp_cache_ui_locale() ?: $locale) : $locale;
});

// Load textdomain
function tqk_wp_cache_load_textdomain() {
    load_plugin_textdomain('tqk-wp-cache', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'tqk_wp_cache_load_textdomain');

// Lifecycle Hooks
register_activation_hook(__FILE__, ['\\TQK_WP_CACHE\\Core\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['\\TQK_WP_CACHE\\Core\\Deactivator', 'deactivate']);

// Boot plugin
add_action('plugins_loaded', function () {
    \TQK_WP_CACHE\Core\Plugin::instance()->boot();
}, 1);
