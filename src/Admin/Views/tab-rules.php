<?php
// Printed by Dashboard::render_view(): tab-rules. Runs inside that method, so $c (settings), $is_pro, $stats, $is_engine_ready,
// $current_lang and $this are available. Markup only; the logic of the screen lives in Dashboard.php and the AJAX classes.
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;
?>
                <!-- Tab 5: Rules & Purge -->
                <div id="tab-rules" class="tqk-tab-pane">
                    <h2 class="tqk-wpseo-section"><?php esc_html_e('Smart Invalidation Rules & Cache Purge', 'tqk-wp-cache'); ?></h2>
                    <div class="tqk-wpseo-card">
                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="purge_on_post_update" id="field_purge_on_post_update" value="1" <?php checked(1, $c['purge_on_post_update']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Automatically purge related cache when publishing or editing posts/comments', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Tag-based invalidation: only purges the specific post and its associated taxonomy archives without clearing the entire website.', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="precise_term_purge" id="field_precise_term_purge" value="1" <?php checked(1, $c['precise_term_purge']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Precise category purge: a new or edited product refreshes only the archives of its own categories', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('The category (and its parent categories), tag and attribute archives of the product are refreshed; archives of other categories are left alone. The scan also reads sidebars and blocks: a page that shows its own "latest products" list is still refreshed whenever a product changes.', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="purge_frontpage" id="field_purge_frontpage" value="1" <?php checked(1, $c['purge_frontpage']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Automatically refresh Homepage Cache on post updates', 'tqk-wp-cache'); ?></strong>
                            </label>
                        </div>

                        <div class="tqk-form-row" style="margin-top: 16px;">
                            <label for="field_excluded_urls"><strong><?php esc_html_e('Never Cache URL Exclusions (One per line):', 'tqk-wp-cache'); ?></strong></label>
                            <p class="tqk-field-desc"><?php esc_html_e('Default built-in exclusions: /cart, /checkout, /my-account, /wp-admin, /wp-login.php.', 'tqk-wp-cache'); ?></p>
                            <textarea name="excluded_urls" id="field_excluded_urls" rows="4" style="width: 100%; font-family: monospace; font-size: 13px; margin-top: 6px;"><?php echo esc_textarea($c['excluded_urls']); ?></textarea>
                            <div style="margin-top: 6px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_excluded_urls">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save URL Exclusions', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                        </div>

                        <div class="tqk-form-row" style="margin-top: 16px;">
                            <label for="field_excluded_cookies"><strong><?php esc_html_e('Never Cache When A Cookie Starts With (One per line):', 'tqk-wp-cache'); ?></strong></label>
                            <p class="tqk-field-desc"><?php esc_html_e('Login, comment, password-protected post and WooCommerce cart cookies are always excluded. Add your own, e.g. a membership or currency-switcher cookie.', 'tqk-wp-cache'); ?></p>
                            <textarea name="excluded_cookies" id="field_excluded_cookies" rows="3" style="width: 100%; font-family: monospace; font-size: 13px; margin-top: 6px;"><?php echo esc_textarea($c['excluded_cookies']); ?></textarea>
                            <div style="margin-top: 6px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_excluded_cookies">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save Cookie Exclusions', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                        </div>

                        <div class="tqk-form-row" style="margin-top: 16px;">
                            <label for="field_cache_query_allow"><strong><?php esc_html_e('Query Parameters That Get Their Own Cache (One per line):', 'tqk-wp-cache'); ?></strong></label>
                            <p class="tqk-field-desc"><?php esc_html_e('URLs with any other query parameter are not cached (this stops ?random=1,2,3... from filling the disk). Tracking parameters such as utm_*, fbclid and gclid are ignored.', 'tqk-wp-cache'); ?></p>
                            <textarea name="cache_query_allow" id="field_cache_query_allow" rows="3" style="width: 100%; font-family: monospace; font-size: 13px; margin-top: 6px;"><?php echo esc_textarea($c['cache_query_allow']); ?></textarea>
                            <div style="margin-top: 6px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_cache_query_allow">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save Query Parameters', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                        </div>

                        <div class="tqk-form-row" style="margin-top: 16px;">
                            <label for="field_meta_refresh_cooldown"><strong><?php esc_html_e('Likes, votes and other post meta changes refresh the pages that show the post, at most once every:', 'tqk-wp-cache'); ?></strong></label>
                            <div class="tqk-inline-save-wrap" style="margin-top:6px;">
                                <input type="number" name="meta_refresh_cooldown" id="field_meta_refresh_cooldown" value="<?php echo esc_attr((int)$c['meta_refresh_cooldown']); ?>" min="10" max="3600" style="width: 90px;">
                                <?php esc_html_e('seconds', 'tqk-wp-cache'); ?>
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_meta_refresh_cooldown">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                            <p class="tqk-field-desc"><?php esc_html_e('WordPress internals, builder caches and the usual view counters are ignored. A counter that changes on every visit would rebuild the page all the time: list its meta key below.', 'tqk-wp-cache'); ?></p>
                            <label for="field_meta_ignore_keys" style="display:block; margin-top:8px;"><strong><?php esc_html_e('Meta keys that never refresh a page (one per line):', 'tqk-wp-cache'); ?></strong></label>
                            <textarea name="meta_ignore_keys" id="field_meta_ignore_keys" rows="3" style="width: 100%; font-family: monospace; font-size: 13px; margin-top: 6px;"><?php echo esc_textarea($c['meta_ignore_keys']); ?></textarea>
                            <div style="margin-top: 6px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_meta_ignore_keys">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                        </div>

                        <div class="tqk-form-row" style="margin-top: 16px;">
                            <strong><?php esc_html_e('Live numbers on cached pages', 'tqk-wp-cache'); ?></strong>
                            <p class="tqk-field-desc"><?php esc_html_e('A cached page keeps the numbers it had when it was built. Wrap a number that must stay current and it is refreshed in the visitor\'s browser from a tiny cached endpoint:', 'tqk-wp-cache'); ?></p>
                            <pre style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; font-size:12.5px; overflow:auto;">[tqk_live source="comments_total"]
[tqk_live source="comments_post"]
[tqk_live source="users_total"]
[tqk_live source="posts_count" type="product"]
[tqk_live source="meta" post="123" key="likes"]
&lt;?php tqk_live('comments_post'); ?&gt;</pre>
                            <label for="field_live_meta_keys" style="display:block; margin-top:8px;"><strong><?php esc_html_e('Meta keys [tqk_live source="meta"] may show (one per line):', 'tqk-wp-cache'); ?></strong></label>
                            <textarea name="live_meta_keys" id="field_live_meta_keys" rows="2" style="width: 100%; font-family: monospace; font-size: 13px; margin-top: 6px;"><?php echo esc_textarea($c['live_meta_keys']); ?></textarea>
                            <div style="margin-top: 6px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_live_meta_keys">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                            <p class="tqk-field-desc"><?php esc_html_e('Only keys listed here are readable, so private meta is never exposed.', 'tqk-wp-cache'); ?></p>
                        </div>
                    </div>

                    <!-- WP-Admin Turbo Engine (Instant SPA Transition & Smart Asset Ingestion) Card -->
                    <div class="tqk-wpseo-card" style="margin-top: 20px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                            <div>
                                <h3 style="margin:0 0 4px; font-size:15px; font-weight:700; color:var(--tqk-ink);">
                                    <?php esc_html_e('WP-Admin Instant Turbo (SPA PushState Engine)', 'tqk-wp-cache'); ?>
                                    <span class="tqk-nav-pro-badge" style="background:#10b981;">TURBO SPA</span>
                                </h3>
                                <p style="margin:0; font-size:13px; color:var(--tqk-muted);"><?php esc_html_e('Navigates across WP-Admin without full page reload. Retains admin menu and top bar, diffs and ingests only newly required CSS and JS dynamically.', 'tqk-wp-cache'); ?></p>
                            </div>
                        </div>

                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="admin_turbo" id="field_admin_turbo" value="1" <?php checked(1, $c['admin_turbo']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Enable WP-Admin Instant Turbo SPA Navigation', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Renders smooth, zero-white-flash page switches when clicking sidebar menus and admin links.', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row" style="margin-top: 14px;">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="admin_benchmark_hud" id="field_admin_benchmark_hud" value="1" <?php checked(1, $c['admin_benchmark_hud'] ?? 1); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Show Floating Stopwatch HUD & Speed Comparison Counter', 'tqk-wp-cache'); ?></strong>
                                <span class="tqk-badge-count" style="background:#eff6ff; color:#1d4ed8; font-size:11px; margin-left:8px;">A/B BENCHMARK</span>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Displays a live stopwatch at the bottom right corner when navigating WP-Admin. Automatically compares load times between Normal reload and Turbo SPA.', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row" style="margin-top: 14px;">
                            <label for="field_admin_turbo_exclusions"><strong><?php esc_html_e('WP-Admin SPA URL Exclusions (Bypass, load full page, one per line):', 'tqk-wp-cache'); ?></strong></label>
                            <p class="tqk-field-desc"><?php esc_html_e('Complex builders or standalone editors that require traditional full browser reloading (Gutenberg, customizers, update tools).', 'tqk-wp-cache'); ?></p>
                            <textarea name="admin_turbo_exclusions" id="field_admin_turbo_exclusions" rows="5" style="width: 100%; font-family: monospace; font-size: 13px; margin-top: 6px;"><?php echo esc_textarea($c['admin_turbo_exclusions']); ?></textarea>
                            <div style="margin-top: 6px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_admin_turbo_exclusions">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save SPA Exclusions', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="tqk-wpseo-card" style="margin-top:20px;">
                        <h3 style="margin:0 0 4px; font-size:15px; font-weight:700; color:var(--tqk-ink);"><?php esc_html_e('Outgoing requests while building admin pages', 'tqk-wp-cache'); ?></h3>
                        <p style="margin:0 0 14px; font-size:13px; color:var(--tqk-muted);"><?php esc_html_e('Admin pages often wait for web requests one after another (update checks of other plugins, tracking pixels, the WP-Cron loop-back). On a slow admin this is usually more time than the whole database. Core, plugin and theme update checks are never touched.', 'tqk-wp-cache'); ?></p>

                        <div class="tqk-form-row">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="admin_http_block_telemetry" id="field_admin_http_block_telemetry" value="1" <?php checked(1, $c['admin_http_block_telemetry']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Block tracking pixels in WP-Admin (pixel.wp.com, stats.wp.com)', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Usage statistics sent by WooCommerce, Jetpack and similar plugins. Nothing breaks: they ignore the refusal.', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row" style="margin-top:14px;">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="admin_http_cache" id="field_admin_http_cache" value="1" <?php checked(1, $c['admin_http_cache']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Remember answers of slow, rarely changing endpoints', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <div class="tqk-inline-save-wrap" style="margin-top:6px;">
                                <label for="field_admin_http_cache_ttl"><?php esc_html_e('Keep for', 'tqk-wp-cache'); ?></label>
                                <input type="number" name="admin_http_cache_ttl" id="field_admin_http_cache_ttl" value="<?php echo esc_attr((int)$c['admin_http_cache_ttl']); ?>" min="1" max="72" style="width:80px;">
                                <?php esc_html_e('hours', 'tqk-wp-cache'); ?>
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_admin_http_cache_ttl">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                            <p class="tqk-field-desc"><?php esc_html_e('Built in: envato.github.io (theme update list), api.wordpress.org translations and events. Only plain GET requests without credentials are kept.', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row" style="margin-top:14px;">
                            <label class="tqk-switch-label">
                                <input type="checkbox" name="admin_http_skip_cron" id="field_admin_http_skip_cron" value="1" <?php checked(1, $c['admin_http_skip_cron']); ?>>
                                <span class="tqk-switch-slider"></span>
                                <strong><?php esc_html_e('Do not start WP-Cron from admin page views', 'tqk-wp-cache'); ?></strong>
                            </label>
                            <p class="tqk-field-desc"><?php esc_html_e('Visitors on the front end (or a real server cron calling wp-cron.php) still run scheduled tasks. Turn this on only if your site has regular visitors or a server cron, otherwise scheduled posts and backups may run late.', 'tqk-wp-cache'); ?></p>
                        </div>

                        <div class="tqk-form-row" style="margin-top:14px;">
                            <label for="field_admin_http_extra_block"><strong><?php esc_html_e('Also block these hosts (one per line, e.g. tracking.example.com):', 'tqk-wp-cache'); ?></strong></label>
                            <textarea name="admin_http_extra_block" id="field_admin_http_extra_block" rows="3" style="width:100%; font-family:monospace; font-size:13px; margin-top:6px;"><?php echo esc_textarea($c['admin_http_extra_block']); ?></textarea>
                            <label for="field_admin_http_extra_cache" style="display:block; margin-top:10px;"><strong><?php esc_html_e('Also remember these (host or host/path, one per line):', 'tqk-wp-cache'); ?></strong></label>
                            <textarea name="admin_http_extra_cache" id="field_admin_http_extra_cache" rows="3" style="width:100%; font-family:monospace; font-size:13px; margin-top:6px;"><?php echo esc_textarea($c['admin_http_extra_cache']); ?></textarea>
                            <div style="margin-top:6px; display:flex; gap:8px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_admin_http_extra_block">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save block list', 'tqk-wp-cache'); ?></span>
                                </button>
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-inline-save-btn" data-target="field_admin_http_extra_cache">
                                    <?php echo View::icon('save'); ?>
                                    <span><?php esc_html_e('Save remember list', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
