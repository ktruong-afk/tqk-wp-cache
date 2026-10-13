<?php
// Printed by Dashboard::render_view(): tab-combine. Runs inside that method, so $c (settings), $is_pro, $stats, $is_engine_ready,
// $current_lang and $this are available. Markup only; the logic of the screen lives in Dashboard.php and the AJAX classes.
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;
?>
                <!-- Tab: Combine CSS & JS -->
                <div id="tab-combine" class="tqk-tab-pane">
                    <h2 class="tqk-wpseo-section"><?php esc_html_e('Combine CSS & JS (Post-Type Bundling)', 'tqk-wp-cache'); ?></h2>
                    <div class="tqk-wpseo-card">
                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="combine_css" id="field_combine_css" value="1" <?php checked(1, $c['combine_css'] ?? 1); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Combine Header CSS per Post Type', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Automatically combines CSS files in the <head> tag into a single file per page context / post type. Rewrites relative url() for images and fonts to prevent layout breakages.', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="combine_js" id="field_combine_js" value="1" <?php checked(1, $c['combine_js'] ?? 1); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Combine JavaScript Header & Footer Separately', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Combines JS files in the Header and Footer separately into distinct bundles per post type to preserve execution order.', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row">
                            <label for="field_combine_exclusions"><strong><?php esc_html_e('CSS/JS Combination Exclusions (One per line):', 'tqk-wp-cache'); ?></strong></label>
                            <textarea name="combine_exclusions" id="field_combine_exclusions" rows="2" style="width: 100%; font-family: monospace; font-size: 13px; margin-top: 6px;"><?php echo esc_textarea($c['combine_exclusions'] ?? "admin-bar\nwp-tinymce"); ?></textarea>
                            <div style="margin-top: 6px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_combine_exclusions">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save Exclusions', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <h2 class="tqk-wpseo-section" style="margin-top:24px;"><?php esc_html_e('Custom CSS & JavaScript (OpenBuilder Integration & Universal Fallback)', 'tqk-wp-cache'); ?></h2>
                    <div class="tqk-wpseo-card">
                        <?php
                        $ob_active = \TQK_WP_CACHE\Integration\OpenBuilder::instance()->is_active();
                        if ($ob_active):
                        ?>
                            <div class="tqk-builder-alert is-connected">
                                <span class="tqk-builder-alert-icon"><?php echo View::icon('check'); ?></span>
                                <div>
                                    <strong><?php esc_html_e('Connected to OpenBuilder', 'tqk-wp-cache'); ?></strong>
                                    <p><?php esc_html_e('System is automatically synchronizing Global CSS, Responsive CSS and Page CSS from OpenBuilder into static HTML cache. You can append additional code below.', 'tqk-wp-cache'); ?></p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="tqk-builder-alert is-universal">
                                <span class="tqk-builder-alert-icon"><?php echo View::icon('info'); ?></span>
                                <div>
                                    <strong><?php esc_html_e('Universal Custom Code Mode (Compatible with all Themes)', 'tqk-wp-cache'); ?></strong>
                                    <p><?php esc_html_e('OpenBuilder is not detected. TQK WP Cache automatically activates universal mode: custom CSS and JS entered below will be compressed and injected into static cache across the entire website.', 'tqk-wp-cache'); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="tqk-form-row">
                            <label for="field_custom_css"><strong><?php esc_html_e('Global Custom CSS:', 'tqk-wp-cache'); ?></strong></label>
                            <p class="tqk-field-desc"><?php esc_html_e('Automatically compressed and injected into the <head> of cached static pages. Supports CSS variables, custom fonts, or theme overrides.', 'tqk-wp-cache'); ?></p>
                            <textarea name="custom_css" id="field_custom_css" rows="6" placeholder="/* e.g. body { font-family: sans-serif; } */" style="width: 100%; font-family: monospace; font-size: 13px; margin-top: 6px;"><?php echo esc_textarea($c['custom_css'] ?? ''); ?></textarea>
                            <div style="margin-top: 6px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_custom_css">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save Custom CSS', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                        </div>

                        <div class="tqk-form-row">
                            <label for="field_custom_js"><strong><?php esc_html_e('Global Custom JS:', 'tqk-wp-cache'); ?></strong></label>
                            <p class="tqk-field-desc"><?php esc_html_e('Automatically injected before the </body> tag in cached static pages. Suitable for analytics, tag managers, or custom optimization scripts.', 'tqk-wp-cache'); ?></p>
                            <textarea name="custom_js" id="field_custom_js" rows="6" placeholder="/* e.g. console.log('TQK WP Cache loaded'); */" style="width: 100%; font-family: monospace; font-size: 13px; margin-top: 6px;"><?php echo esc_textarea($c['custom_js'] ?? ''); ?></textarea>
                            <div style="margin-top: 6px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_custom_js">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save Custom JS', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
