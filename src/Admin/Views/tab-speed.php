<?php
// Printed by Dashboard::render_view(): tab-speed. Runs inside that method, so $c (settings), $is_pro, $stats, $is_engine_ready,
// $current_lang and $this are available. Markup only; the logic of the screen lives in Dashboard.php and the AJAX classes.
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;
?>
                <!-- Tab 3: Delay JS & Speed Optimization -->
                <div id="tab-speed" class="tqk-tab-pane">
                    <h2 class="tqk-wpseo-section"><?php esc_html_e('Delay JS & Core Web Vitals Optimization', 'tqk-wp-cache'); ?></h2>
                    <div class="tqk-wpseo-card">
                        <div class="tqk-form-row tqk-highlight-block">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="delay_js" id="field_delay_js" value="1" <?php checked(1, $c['delay_js']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Delay JavaScript Execution (Delay JS)', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Delays execution of non-critical JS scripts until first user interaction (touch or scroll). Critical weapon to achieve 95–100 Google Mobile score!', 'tqk-wp-cache'); ?></p>

                            <div style="margin-top: 14px;">
                                <label for="field_delay_js_timeout"><strong><?php esc_html_e('Delay JS Auto-Execution Timeout (Seconds):', 'tqk-wp-cache'); ?></strong></label>
                                <div class="tqk-inline-save-wrap">
                                    <input type="number" name="delay_js_timeout" id="field_delay_js_timeout" value="<?php echo esc_attr($c['delay_js_timeout']); ?>" min="0" max="10" style="width: 100px;">
                                    <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_delay_js_timeout">
                                        <?php echo View::icon('save'); ?>
                                        <span><?php esc_html_e('Save', 'tqk-wp-cache'); ?></span>
                                    </button>
                                    <span class="tqk-field-desc" style="display:inline; margin-left: 4px;"><?php esc_html_e('seconds (Set to 0 to only execute on actual user interaction).', 'tqk-wp-cache'); ?></span>
                                </div>
                            </div>

                            <div style="margin-top: 14px;">
                                <label for="field_delay_js_exclusions"><strong><?php esc_html_e('Delay JS Exclusions (Files excluded from delay, one per line):', 'tqk-wp-cache'); ?></strong></label>
                                <textarea name="delay_js_exclusions" id="field_delay_js_exclusions" rows="3" style="width: 100%; font-family: monospace; font-size: 13px; margin-top: 6px;"><?php echo esc_textarea($c['delay_js_exclusions']); ?></textarea>
                                <div style="margin-top: 6px;">
                                    <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_delay_js_exclusions">
                                        <?php echo View::icon('save'); ?>
                                        <span><?php esc_html_e('Save Exclusions', 'tqk-wp-cache'); ?></span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="tqk-form-row" style="margin-top: 16px;">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="instant_page" id="field_instant_page" value="1" <?php checked(1, $c['instant_page']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Instant Preload (Preload on link hover / touchstart)', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Preloads the page in background when the user hovers over a link, enabling instantaneous navigation (< 50ms).', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="html_minify" id="field_html_minify" value="1" <?php checked(1, $c['html_minify']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Minify HTML Code', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Strips unnecessary whitespace and comments from static HTML output.', 'tqk-wp-cache'); ?></p>
                        </div>
                    </div>
                                    <div class="tqk-wpseo-card" style="margin-top:20px;">
                        <h3 style="margin:0 0 4px; font-size:15px; font-weight:700; color:var(--tqk-ink);"><?php esc_html_e('Core Web Vitals helpers & CDN', 'tqk-wp-cache'); ?></h3>
                        <p style="margin:0 0 14px; font-size:13px; color:var(--tqk-muted);"><?php esc_html_e('Small, safe changes applied to the stored page.', 'tqk-wp-cache'); ?></p>

                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="fonts_swap" id="field_fonts_swap" value="1" <?php checked(1, $c['fonts_swap']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Google Fonts: show text at once (display=swap) and preconnect to the font files', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Removes the invisible-text delay while a Google font loads (the "Ensure text remains visible" audit).', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row" style="margin-top:14px;">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="lcp_hint" id="field_lcp_hint" value="1" <?php checked(1, $c['lcp_hint']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Load the main image first (fetchpriority=high on the likely LCP image)', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Among the first three images the first one that is not a small logo is fetched with high priority and never lazy-loaded. Works together with Lazy Load.', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row" style="margin-top:14px;">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="cdn_enabled" id="field_cdn_enabled" value="1" <?php checked(1, $c['cdn_enabled']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Serve images, CSS, JS and fonts from a CDN', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <div class="tqk-inline-save-wrap" style="margin-top:6px;">
                                <label for="field_cdn_url"><?php esc_html_e('CDN address:', 'tqk-wp-cache'); ?></label>
                                <input type="text" name="cdn_url" id="field_cdn_url" value="<?php echo esc_attr($c['cdn_url']); ?>" placeholder="https://cdn.example.com" style="width:280px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_cdn_url">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                            <p class="tqk-field-desc"><?php esc_html_e('The CDN must serve the same /wp-content and /wp-includes paths (a "pull zone" pointing at this site). Only file addresses inside src, href, srcset and poster attributes change; scripts, JSON and links to pages are left alone.', 'tqk-wp-cache'); ?></p>
                            <label for="field_cdn_exclusions" style="display:block; margin-top:8px;"><strong><?php esc_html_e('Keep these on the site (a part of the address, one per line):', 'tqk-wp-cache'); ?></strong></label>
                            <textarea name="cdn_exclusions" id="field_cdn_exclusions" rows="2" style="width:100%; font-family:monospace; font-size:13px; margin-top:6px;"><?php echo esc_textarea($c['cdn_exclusions']); ?></textarea>
                            <div style="margin-top:6px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_cdn_exclusions">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
