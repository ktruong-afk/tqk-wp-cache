<?php
// Printed by Dashboard::render_view(): tab-database. Runs inside that method, so $c (settings), $is_pro, $stats, $is_engine_ready,
// $current_lang and $this are available. Markup only; the logic of the screen lives in Dashboard.php and the AJAX classes.
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;
?>
                <!-- Tab: Database & Revisions Analyzer -->
                <div id="tab-database" class="tqk-tab-pane">
                    <h2 class="tqk-wpseo-section"><?php esc_html_e('Database & Revisions Analyzer', 'tqk-wp-cache'); ?></h2>
                    
                    <!-- 1-Click System Junk Cleaner -->
                    <div class="tqk-wpseo-card" style="margin-bottom: 20px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
                            <div>
                                <h3 style="margin:0 0 4px; font-size:15px; font-weight:700; color:var(--tqk-ink);"><?php esc_html_e('1-Click System Junk Cleanup', 'tqk-wp-cache'); ?></h3>
                                <p style="margin:0; font-size:13px; color:var(--tqk-muted);"><?php esc_html_e('Instantly clear expired transients, abandoned auto-drafts, and orphan post metadata without touching published content.', 'tqk-wp-cache'); ?></p>
                            </div>
                            <button type="button" class="tqk-btn tqk-btn-primary" id="tqk-clean-junk-btn">
                                <?php echo View::icon('trash'); ?>
                                <span><?php esc_html_e('Clean All Junk Now', 'tqk-wp-cache'); ?></span>
                            </button>
                        </div>

                        <div class="tqk-junk-stats-grid" id="tqk-junk-stats-grid">
                            <div class="tqk-junk-stat-box">
                                <strong id="tqk-stat-transients">--</strong>
                                <span><?php esc_html_e('Expired Transients', 'tqk-wp-cache'); ?></span>
                            </div>
                            <div class="tqk-junk-stat-box">
                                <strong id="tqk-stat-autodrafts">--</strong>
                                <span><?php esc_html_e('Auto-Drafts', 'tqk-wp-cache'); ?></span>
                            </div>
                            <div class="tqk-junk-stat-box">
                                <strong id="tqk-stat-orphanmeta">--</strong>
                                <span><?php esc_html_e('Orphan Postmeta', 'tqk-wp-cache'); ?></span>
                            </div>
                            <div class="tqk-junk-stat-box">
                                <strong id="tqk-stat-totalrevisions">--</strong>
                                <span><?php esc_html_e('Total Revisions in DB', 'tqk-wp-cache'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Revisions Analyzer Card -->
                    <div class="tqk-wpseo-card">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
                            <div>
                                <h3 style="margin:0 0 4px; font-size:15px; font-weight:700; color:var(--tqk-ink);"><?php esc_html_e('Post Revisions Analyzer & Selective Pruning', 'tqk-wp-cache'); ?></h3>
                                <p style="margin:0; font-size:13px; color:var(--tqk-muted);"><?php esc_html_e('Frequent post edits accumulate dozens of revisions, slowing down WP queries. Selectively keep the recent versions for maximum safety.', 'tqk-wp-cache'); ?></p>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-btn-sm" id="tqk-refresh-revisions-btn">
                                    <?php echo View::icon('refresh'); ?>
                                    <span><?php esc_html_e('Scan Revisions', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                        </div>

                        <!-- Action Toolbar -->
                        <div class="tqk-revisions-toolbar">
                            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <label for="tqk-revisions-keep" style="font-size:13px; font-weight:600; color:var(--tqk-ink);"><?php esc_html_e('Quick Preset:', 'tqk-wp-cache'); ?></label>
                                <select id="tqk-revisions-keep" style="font-size:13px; padding:5px 10px; border-radius:6px; border:1px solid var(--tqk-border);">
                                    <option value="3" selected><?php esc_html_e('Keep last 3 revisions (Default)', 'tqk-wp-cache'); ?></option>
                                    <option value="5"><?php esc_html_e('Keep last 5 revisions', 'tqk-wp-cache'); ?></option>
                                    <option value="10"><?php esc_html_e('Keep last 10 revisions', 'tqk-wp-cache'); ?></option>
                                    <option value="0"><?php esc_html_e('Delete all revisions (0 kept)', 'tqk-wp-cache'); ?></option>
                                </select>
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-btn-sm" id="tqk-apply-all-keep-btn">
                                    <?php esc_html_e('Apply to Checked', 'tqk-wp-cache'); ?>
                                </button>
                            </div>
                            <button type="button" class="tqk-btn tqk-btn-primary tqk-btn-sm" id="tqk-clean-revisions-btn" disabled>
                                <?php echo View::icon('trash'); ?>
                                <span><?php esc_html_e('Prune Selected Posts', 'tqk-wp-cache'); ?></span>
                            </button>
                        </div>

                        <!-- Table -->
                        <div class="tqk-table-responsive" style="margin-top:12px;">
                            <table class="tqk-data-table" id="tqk-revisions-table">
                                <thead>
                                    <tr>
                                        <th style="width:36px;"><input type="checkbox" id="tqk-revisions-select-all"></th>
                                        <th><?php esc_html_e('Post Title', 'tqk-wp-cache'); ?></th>
                                        <th style="width:100px;"><?php esc_html_e('Post Type', 'tqk-wp-cache'); ?></th>
                                        <th style="width:100px; text-align:center;"><?php esc_html_e('Revisions', 'tqk-wp-cache'); ?></th>
                                        <th style="width:130px; text-align:center;"><?php esc_html_e('Keep Newest', 'tqk-wp-cache'); ?></th>
                                        <th style="width:100px; text-align:right;"><?php esc_html_e('Est. Size', 'tqk-wp-cache'); ?></th>
                                        <th style="width:130px;"><?php esc_html_e('Last Modified', 'tqk-wp-cache'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="7" style="text-align:center; padding:24px; color:var(--tqk-muted);">
                                            <?php esc_html_e('Click "Scan Revisions" to inspect posts with high revision overhead.', 'tqk-wp-cache'); ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
