<?php
// Printed by Dashboard::render_view(): tab-overview. Runs inside that method, so $c (settings), $is_pro, $stats, $is_engine_ready,
// $current_lang and $this are available. Markup only; the logic of the screen lives in Dashboard.php and the AJAX classes.
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;
?>
                <!-- Tab 1: Overview & Presets -->
                <div id="tab-dashboard" class="tqk-tab-pane active">
                    <h2 class="tqk-wpseo-section"><?php esc_html_e('One-Click Configuration Presets', 'tqk-wp-cache'); ?></h2>
                    <?php
                    $active_preset = $c['active_preset'] ?? 'balanced';
                    $is_safe = ($active_preset === 'safe');
                    $is_balanced = ($active_preset === 'balanced');
                    $is_ludicrous = ($active_preset === 'ludicrous');
                    $is_custom = ($active_preset === 'custom');
                    ?>
                    <div class="tqk-preset-custom-notice <?php echo $is_custom ? '' : 'is-hidden'; ?>" id="tqk-preset-custom-notice">
                        <?php echo View::icon('shield'); ?>
                        <span><?php esc_html_e('You are using Custom Settings. Advanced options have been customized manually.', 'tqk-wp-cache'); ?></span>
                    </div>

                    <div class="tqk-guide">
                        <div class="tqk-guide-card tqk-preset-card <?php echo $is_safe ? 'is-active' : ''; ?>" data-preset="safe">
                            <div class="tqk-guide-head">
                                <span class="dashicons"><?php echo View::icon('shield'); ?></span>
                                <strong><?php esc_html_e('Safe Mode 100%', 'tqk-wp-cache'); ?></strong>
                                <span class="tqk-preset-badge <?php echo $is_safe ? '' : 'is-hidden'; ?>"><?php esc_html_e('Active', 'tqk-wp-cache'); ?></span>
                            </div>
                            <div class="tqk-guide-what"><?php esc_html_e('Static Page Cache + Gzip Compression + Image LazyLoad.', 'tqk-wp-cache'); ?></div>
                            <div class="tqk-guide-how"><?php esc_html_e('Guaranteed 100% safe, no impact on any layout or functionality.', 'tqk-wp-cache'); ?></div>
                            <div class="tqk-guide-go">
                                <button type="button" class="tqk-btn <?php echo $is_safe ? 'tqk-btn-primary is-current' : 'tqk-btn-outline'; ?> tqk-btn-sm apply-preset-btn">
                                    <?php echo $is_safe ? esc_html__('Active', 'tqk-wp-cache') : esc_html__('Apply Now', 'tqk-wp-cache'); ?>
                                </button>
                            </div>
                        </div>

                        <div class="tqk-guide-card tqk-preset-card featured <?php echo $is_balanced ? 'is-active' : ''; ?>" data-preset="balanced">
                            <div class="tqk-guide-head">
                                <span class="dashicons"><?php echo View::icon('bolt'); ?></span>
                                <strong><?php esc_html_e('Balanced Mode (Recommended)', 'tqk-wp-cache'); ?></strong>
                                <span class="tqk-preset-badge <?php echo $is_balanced ? '' : 'is-hidden'; ?>"><?php esc_html_e('Active', 'tqk-wp-cache'); ?></span>
                            </div>
                            <div class="tqk-guide-what"><?php esc_html_e('Page Cache + Gzip + Instant Preload + iFrame LazyLoad + Minify HTML.', 'tqk-wp-cache'); ?></div>
                            <div class="tqk-guide-how"><?php esc_html_e('Instant browsing experience (< 50ms) and silky smooth responsiveness.', 'tqk-wp-cache'); ?></div>
                            <div class="tqk-guide-go">
                                <button type="button" class="tqk-btn <?php echo $is_balanced ? 'tqk-btn-primary is-current' : 'tqk-btn-outline'; ?> tqk-btn-sm apply-preset-btn">
                                    <?php echo $is_balanced ? esc_html__('Active', 'tqk-wp-cache') : esc_html__('Apply Now', 'tqk-wp-cache'); ?>
                                </button>
                            </div>
                        </div>

                        <div class="tqk-guide-card tqk-preset-card <?php echo $is_ludicrous ? 'is-active' : ''; ?>" data-preset="ludicrous">
                            <div class="tqk-guide-head">
                                <span class="dashicons"><?php echo View::icon('zap'); ?></span>
                                <strong><?php esc_html_e('Breakthrough 100 Mobile Score', 'tqk-wp-cache'); ?></strong>
                                <span class="tqk-preset-badge <?php echo $is_ludicrous ? '' : 'is-hidden'; ?>"><?php esc_html_e('Active', 'tqk-wp-cache'); ?></span>
                            </div>
                            <div class="tqk-guide-what"><?php esc_html_e('Enable Delay JS + Instant Preload + Tag Purge + Gzip + Minify HTML.', 'tqk-wp-cache'); ?></div>
                            <div class="tqk-guide-how"><?php esc_html_e('Eliminates Total Blocking Time (TBT), maximizes Google PageSpeed scores.', 'tqk-wp-cache'); ?></div>
                            <div class="tqk-guide-go">
                                <button type="button" class="tqk-btn <?php echo $is_ludicrous ? 'tqk-btn-primary is-current' : 'tqk-btn-outline'; ?> tqk-btn-sm apply-preset-btn">
                                    <?php echo $is_ludicrous ? esc_html__('Active', 'tqk-wp-cache') : esc_html__('Apply Now', 'tqk-wp-cache'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <h2 class="tqk-wpseo-section"><?php esc_html_e('Website Performance Overview', 'tqk-wp-cache'); ?></h2>
                    <div class="tqk-wpseo-stats">
                        <?php
                        echo View::stat_card(__('Cache Size', 'tqk-wp-cache'), $stats['size_formatted'], __('Total static data on disk', 'tqk-wp-cache'), 'database');
                        echo View::stat_card(__('Cached Pages Count', 'tqk-wp-cache'), (string)$stats['files'], __('Total HTML/Gzip static files', 'tqk-wp-cache'), 'code');
                        echo View::stat_card(__('Drop-in Engine', 'tqk-wp-cache'), $is_engine_ready ? esc_html__('Active', 'tqk-wp-cache') : esc_html__('Disabled', 'tqk-wp-cache'), __('Serves cached pages before WordPress loads', 'tqk-wp-cache'), 'bolt', $is_engine_ready ? '' : 'bad');
                        ?>
                    </div>

                    <h2 class="tqk-wpseo-section"><?php esc_html_e('Live Cache Warmup & OpenBuilder Sync (Activity Tracker)', 'tqk-wp-cache'); ?></h2>
                    <div class="tqk-wpseo-card tqk-warmup-card">
                        <div class="tqk-form-row" style="margin-bottom:14px;">
                            <label for="tqk-rebuild-mode"><strong><?php esc_html_e('How to rebuild the cache:', 'tqk-wp-cache'); ?></strong></label>
                            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:6px;">
                                <select id="tqk-rebuild-mode" style="min-width:280px;">
                                    <option value="essential" <?php selected($c['rebuild_mode'], 'essential'); ?>><?php esc_html_e('Key pages only (homepage, pages, one sample per type)', 'tqk-wp-cache'); ?></option>
                                    <option value="missing" <?php selected($c['rebuild_mode'], 'missing'); ?>><?php esc_html_e('Every page that is not cached yet (up to the limit)', 'tqk-wp-cache'); ?></option>
                                    <option value="lazy" <?php selected($c['rebuild_mode'], 'lazy'); ?>><?php esc_html_e('Clear only, visitors rebuild it (big sites)', 'tqk-wp-cache'); ?></option>
                                </select>
                                <span id="tqk-rebuild-limit-wrap" <?php echo $c['rebuild_mode'] === 'missing' ? '' : 'style="display:none;"'; ?>>
                                    <label for="tqk-rebuild-limit"><?php esc_html_e('Limit:', 'tqk-wp-cache'); ?></label>
                                    <input type="number" id="tqk-rebuild-limit" value="<?php echo esc_attr((int)$c['warmup_limit']); ?>" min="10" max="5000" style="width:90px;">
                                    <?php esc_html_e('pages', 'tqk-wp-cache'); ?>
                                </span>
                            </div>
                            <p class="tqk-field-desc"><?php esc_html_e('Small site: rebuild everything. Hundreds of pages or thousands of products: choose "clear only" or a limit, so the server is not asked to render the whole site at once. After a product changes, only the pages that depended on it are missing, so "not cached yet" rebuilds just those.', 'tqk-wp-cache'); ?></p>
                        </div>
                        <div class="tqk-warmup-header">
                            <div>
                                <h3 style="margin:0 0 4px; font-size:16px; font-weight:700; color:var(--tqk-ink);"><?php esc_html_e('Real-Time Purge & Warmup Progress Tracker', 'tqk-wp-cache'); ?></h3>
                                <p style="margin:0; font-size:13px; color:var(--tqk-muted);"><?php esc_html_e('Automatically purges stale cache, scans OpenBuilder options (Global CSS + Responsive), and generates fresh static HTML ready for visitors.', 'tqk-wp-cache'); ?></p>
                            </div>
                            <div style="display:flex; gap:8px;">
                                <button type="button" class="tqk-btn tqk-btn-primary tqk-start-warmup-btn" id="tqk-main-warmup-btn">
                                    <?php echo View::icon('zap'); ?>
                                    <span><?php esc_html_e('Run Purge & Cache Warmup', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                        </div>

                        <!-- Processing Bar -->
                        <div class="tqk-progress-wrapper" id="tqk-progress-wrapper">
                            <div class="tqk-progress-info">
                                <span class="tqk-progress-status" id="tqk-progress-status"><?php esc_html_e('Ready to execute...', 'tqk-wp-cache'); ?></span>
                                <span class="tqk-progress-stats"><strong id="tqk-progress-count">0</strong> / <strong id="tqk-progress-total">0</strong> <?php esc_html_e('pages', 'tqk-wp-cache'); ?> (<strong id="tqk-progress-percent">0%</strong>)</span>
                            </div>
                            <div class="tqk-progress-track">
                                <div id="tqk-progress-bar" class="tqk-progress-bar" style="width: 0%;"></div>
                            </div>
                        </div>

                        <!-- Real-time Console Log / Activity Tracker -->
                        <div class="tqk-console-wrap">
                            <div class="tqk-console-head">
                                <span class="tqk-console-title"><?php echo View::icon('code'); ?> <?php esc_html_e('Real-time Activity Feed', 'tqk-wp-cache'); ?></span>
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-btn-sm tqk-console-clear-btn"><?php esc_html_e('Clear Log', 'tqk-wp-cache'); ?></button>
                            </div>
                            <div id="tqk-console-body" class="tqk-console-body">
                                <div class="tqk-log-line is-info"><span class="log-time">[<?php echo date('H:i:s'); ?>]</span> <?php esc_html_e('System ready. Click "Run Purge & Cache Warmup" to begin full site cache regeneration.', 'tqk-wp-cache'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
