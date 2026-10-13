<?php
namespace TQK_WP_CACHE\Integration;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;

defined('ABSPATH') || exit;

/**
 * Universal Custom Code & OpenBuilder Integration Engine.
 * Supports native synchronization with OpenBuilder when present,
 * and seamlessly falls back to TQK WP Cache's own Custom CSS/JS options
 * for standalone WordPress installations.
 */
class OpenBuilder {
    private static ?OpenBuilder $instance = null;

    public static function instance(): OpenBuilder {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        if ($this->is_active()) {
            // Listen for OpenBuilder updates
            add_action('update_option_op_global_css', [$this, 'on_global_css_updated'], 10, 2);
            add_action('update_option_op_settings', [$this, 'on_settings_updated'], 10, 2);
        }
    }

    public function is_active(): bool {
        return defined('OP_BUILDER_VERSION') ||
               defined('DDB_VERSION') ||
               function_exists('op_ajax_save') ||
               file_exists(WP_PLUGIN_DIR . '/openbuilder/drag-drop-builder.php');
    }

    public function get_global_custom_css(): string {
        $css = '';
        $config = Config::instance();

        // 1. TQK WP Cache own Global Custom CSS (Always active for any user)
        $tqk_custom = trim((string)$config->get('custom_css', ''));
        if (!empty($tqk_custom)) {
            $css .= "/* TQK WP Cache Custom CSS */\n" . $tqk_custom . "\n";
        }

        // 2. OpenBuilder Integration (if OpenBuilder is installed)
        if ($this->is_active()) {
            $global_opt = (string)get_option('op_global_css', '');
            if (!empty($global_opt)) {
                $css .= "\n/* OpenBuilder Global Custom CSS */\n" . trim($global_opt) . "\n";
            }

            // OpenBuilder site-wide responsive CSS from op_settings
            $settings = (array)get_option('op_settings', []);
            $d = trim((string)($settings['site_css_desktop'] ?? ''));
            $t = trim((string)($settings['site_css_tablet'] ?? ''));
            $m = trim((string)($settings['site_css_mobile'] ?? ''));

            if (!empty($d)) $css .= "\n@media (min-width: 1025px) {\n" . $d . "\n}\n";
            if (!empty($t)) $css .= "\n@media (max-width: 1024px) {\n" . $t . "\n}\n";
            if (!empty($m)) $css .= "\n@media (max-width: 767px) {\n" . $m . "\n}\n";

            // OpenBuilder assets/global.css
            $plugin_global_file = WP_PLUGIN_DIR . '/openbuilder/assets/global.css';
            if (file_exists($plugin_global_file) && is_readable($plugin_global_file)) {
                $file_css = @file_get_contents($plugin_global_file);
                if ($file_css && stripos($css, trim($file_css)) === false) {
                    $css .= "\n/* OpenBuilder global.css */\n" . trim($file_css) . "\n";
                }
            }
        }

        return trim($css);
    }

    public function get_page_custom_css(int $post_id): string {
        if ($post_id <= 0 || !$this->is_active()) {
            return '';
        }

        $css = '';

        // Page compiled CSS file
        $page_css_file = WP_PLUGIN_DIR . "/openbuilder/assets/page-css/page-{$post_id}.css";
        if (file_exists($page_css_file) && is_readable($page_css_file)) {
            $compiled = @file_get_contents($page_css_file);
            if ($compiled) {
                $css .= "/* OpenBuilder Page #{$post_id} CSS */\n" . trim($compiled) . "\n";
            }
        }

        // Responsive post meta
        $d = trim((string)get_post_meta($post_id, '_op_css_desktop', true));
        $t = trim((string)get_post_meta($post_id, '_op_css_tablet', true));
        $m = trim((string)get_post_meta($post_id, '_op_css_mobile', true));

        if (!empty($d)) $css .= "\n@media (min-width: 1025px) {\n" . $d . "\n}\n";
        if (!empty($t)) $css .= "\n@media (max-width: 1024px) {\n" . $t . "\n}\n";
        if (!empty($m)) $css .= "\n@media (max-width: 767px) {\n" . $m . "\n}\n";

        return trim($css);
    }

    public function get_global_custom_js(): string {
        $config = Config::instance();
        return trim((string)$config->get('custom_js', ''));
    }

    public function inject_custom_assets(string $html, ?int $post_id = null): string {
        if ($post_id === null && is_singular()) {
            $post_id = get_the_ID();
        }

        $global_css = $this->get_global_custom_css();
        $page_css   = $post_id ? $this->get_page_custom_css($post_id) : '';
        $custom_js  = $this->get_global_custom_js();

        $all_custom_css = trim($global_css . "\n" . $page_css);

        // Inject CSS inside <head>
        if (!empty($all_custom_css) && strpos($html, 'id="tqk-baked-custom-css"') === false) {
            $style_block = sprintf(
                "\n<style id=\"tqk-baked-custom-css\" type=\"text/css\">\n%s\n</style>\n",
                preg_replace('#</style#i', '<\/style', $all_custom_css) // custom code can never close the tag early
            );

            $pos = stripos($html, '</head>');
            if ($pos !== false) {
                $html = substr($html, 0, $pos) . $style_block . substr($html, $pos); // first </head> only (str_ireplace hit every occurrence)
            } else {
                $html .= $style_block;
            }
        }

        // Inject JS before </body>
        if (!empty($custom_js) && strpos($html, 'id="tqk-baked-custom-js"') === false) {
            $script_block = sprintf(
                "\n<script id=\"tqk-baked-custom-js\" type=\"text/javascript\">\n%s\n</script>\n",
                preg_replace('#</script#i', '<\/script', $custom_js)
            );

            $pos = strripos($html, '</body>');
            if ($pos !== false) {
                $html = substr($html, 0, $pos) . $script_block . substr($html, $pos); // the real closing tag is the LAST one
            } else {
                $html .= $script_block;
            }
        }

        return $html;
    }

    public function on_global_css_updated($old_value, $new_value): void {
        $storage = new DiskStorage();
        $storage->purge_all();
    }

    public function on_settings_updated($old_value, $new_value): void {
        $storage = new DiskStorage();
        $storage->purge_all();
    }
}
