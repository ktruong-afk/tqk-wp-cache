<?php
// Printed by Dashboard::render_view(): tab-media. Runs inside that method, so $c (settings), $is_pro, $stats, $is_engine_ready,
// $current_lang and $this are available. Markup only; the logic of the screen lives in Dashboard.php and the AJAX classes.
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;
?>
                <!-- Tab 4: Media & LazyLoad -->
                <div id="tab-media" class="tqk-tab-pane">
                    <h2 class="tqk-wpseo-section"><?php esc_html_e('Media & LazyLoad Optimization', 'tqk-wp-cache'); ?></h2>
                    <div class="tqk-wpseo-card">
                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="lazy_load" id="field_lazy_load" value="1" <?php checked(1, $c['lazy_load']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Enable Lazy Load for All Images', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Automatically adds native browser loading="lazy" to all images.', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="lazy_load_iframes" id="field_lazy_load_iframes" value="1" <?php checked(1, $c['lazy_load_iframes']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Enable Lazy Load for iFrames & Videos (YouTube, Vimeo, Google Maps)', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Saves megabytes of bandwidth on pages with embedded videos or maps.', 'tqk-wp-cache'); ?></p>
                        </div>
                    </div>
                </div>
