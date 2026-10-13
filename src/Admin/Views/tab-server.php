<?php
// Printed by Dashboard::render_view(): tab-server. Runs inside that method, so $c (settings), $is_pro, $stats, $is_engine_ready,
// $current_lang and $this are available. Markup only; the logic of the screen lives in Dashboard.php and the AJAX classes.
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;
?>
                <!-- Tab 6: Server & LiteSpeed -->
                <div id="tab-server" class="tqk-tab-pane">
                    <h2 class="tqk-wpseo-section"><?php esc_html_e('Server Compatibility & LiteSpeed Web Server', 'tqk-wp-cache'); ?></h2>
                    <div class="tqk-wpseo-card">
                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="litespeed_headers" id="field_litespeed_headers" value="1" <?php checked(1, $c['litespeed_headers']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Emit LiteSpeed Cache HTTP Headers (X-LiteSpeed-Cache-Control & Tag Purge)', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Communicates directly with LiteSpeed Web Server / OpenLiteSpeed if running on supported hosting.', 'tqk-wp-cache'); ?></p>
                        </div>

                    </div>
                                    <div class="tqk-wpseo-card" style="margin-top:20px;">
                        <h3 style="margin:0 0 4px; font-size:15px; font-weight:700; color:var(--tqk-ink);"><?php esc_html_e('Cloudflare', 'tqk-wp-cache'); ?></h3>
                        <p style="margin:0 0 14px; font-size:13px; color:var(--tqk-muted);"><?php esc_html_e('When TQK refreshes a page (or everything), the same pages are purged at Cloudflare a few seconds later, so the edge never keeps serving old HTML.', 'tqk-wp-cache'); ?></p>
                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="cf_enabled" id="field_cf_enabled" value="1" <?php checked(1, $c['cf_enabled']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Purge Cloudflare together with the local cache', 'tqk-wp-cache'); ?></strong>
                            </label>
                        </div>
                        <div class="tqk-form-row" style="margin-top:12px;">
                            <label for="field_cf_zone"><strong><?php esc_html_e('Zone ID:', 'tqk-wp-cache'); ?></strong></label>
                            <input type="text" name="cf_zone" id="field_cf_zone" value="<?php echo esc_attr($c['cf_zone']); ?>" placeholder="0123456789abcdef0123456789abcdef" style="width:100%; max-width:420px; font-family:monospace;" autocomplete="off">
                        </div>
                        <div class="tqk-form-row" style="margin-top:12px;">
                            <label for="field_cf_token"><strong><?php esc_html_e('API token (permission "Zone > Cache Purge" only):', 'tqk-wp-cache'); ?></strong></label>
                            <input type="password" name="cf_token" id="field_cf_token" value="" placeholder="<?php echo $c['cf_token'] !== '' ? esc_attr__('saved - type a new one to replace it', 'tqk-wp-cache') : ''; ?>" style="width:100%; max-width:420px; font-family:monospace;" autocomplete="new-password">
                            <p class="tqk-field-desc"><?php esc_html_e('Create it in Cloudflare: My Profile > API Tokens > Create Token > "Cache Purge" template. The token is kept on the server and is never shown again.', 'tqk-wp-cache'); ?></p>
                        </div>
                        <div style="margin-top:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                            <button type="button" class="tqk-btn tqk-btn-outline" id="tqk-cf-test-btn"><?php echo View::icon('shield'); ?> <span><?php esc_html_e('Test connection', 'tqk-wp-cache'); ?></span></button>
                            <span id="tqk-cf-test-msg" class="tqk-field-desc" style="margin:0;" aria-live="polite"></span>
                        </div>
                        <?php $cf_last = get_option('tqk_cache_cf_last', []); if (is_array($cf_last) && !empty($cf_last['time'])): ?>
                            <p class="tqk-field-desc" style="margin-top:8px;"><?php echo esc_html(sprintf(__('Last purge sent to Cloudflare: %1$s - %2$s', 'tqk-wp-cache'), wp_date('Y-m-d H:i:s', (int)$cf_last['time']), $cf_last['ok'] ? __('OK', 'tqk-wp-cache') : (string)$cf_last['message'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
