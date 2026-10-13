<?php
// Printed by Dashboard::render_view(): tab-pagecache. Runs inside that method, so $c (settings), $is_pro, $stats, $is_engine_ready,
// $current_lang and $this are available. Markup only; the logic of the screen lives in Dashboard.php and the AJAX classes.
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;
?>
                <!-- Tab 2: Page Cache -->
                <div id="tab-pagecache" class="tqk-tab-pane">
                    <h2 class="tqk-wpseo-section"><?php esc_html_e('Static Page Cache Configuration', 'tqk-wp-cache'); ?></h2>
                    <div class="tqk-wpseo-card">
                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="page_cache" id="field_page_cache" value="1" <?php checked(1, $c['page_cache']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Enable Page Cache (Drop-in Engine)', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Generates advanced-cache.php in wp-content/ to serve static HTML directly without heavy PHP/MySQL processing.', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row">
                            <label for="field_cache_lifespan"><strong><?php esc_html_e('Cache Lifespan (Hours):', 'tqk-wp-cache'); ?></strong></label>
                            <div class="tqk-inline-save-wrap">
                                <input type="number" name="cache_lifespan" id="field_cache_lifespan" value="<?php echo esc_attr($c['cache_lifespan']); ?>" min="1" max="720" style="width: 100px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_cache_lifespan">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save', 'tqk-wp-cache'); ?></span>
                                </button>
                                <span class="tqk-field-desc" style="display:inline; margin-left: 4px;"><?php esc_html_e('hours (Default: 10 hours).', 'tqk-wp-cache'); ?></span>
                            </div>
                        </div>

                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="gzip_compression" id="field_gzip_compression" value="1" <?php checked(1, $c['gzip_compression']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Automatic Gzip Compression (.html.gz)', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Reduces network bandwidth transfer by up to 75% per request.', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row">
                            <strong><?php esc_html_e('Logged-In Visitors', 'tqk-wp-cache'); ?></strong>
                            <p class="tqk-field-desc"><?php esc_html_e('Pages for logged-in users, commenters, password-protected posts and shopping carts are never cached, so personal data can never reach other visitors.', 'tqk-wp-cache'); ?></p>
                        </div>
                    </div>
                </div>
