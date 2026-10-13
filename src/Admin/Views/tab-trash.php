<?php
// Printed by Dashboard::render_view(): tab-trash. Runs inside that method, so $c (settings), $is_pro, $stats, $is_engine_ready,
// $current_lang and $this are available. Markup only; the logic of the screen lives in Dashboard.php and the AJAX classes.
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;
?>
                <!-- Tab: Trash Manager -->
                <div id="tab-trash" class="tqk-tab-pane">
                    <h2 class="tqk-wpseo-section"><?php esc_html_e('Trash Manager & WooCommerce Fast Purge', 'tqk-wp-cache'); ?></h2>
                    
                    <div class="tqk-wpseo-card" style="margin-bottom: 20px;">
                        <h3 style="margin:0 0 6px; font-size:15px; font-weight:700; color:var(--tqk-ink);"><?php esc_html_e('Trash Statistics by Post Type', 'tqk-wp-cache'); ?></h3>
                        <p style="margin:0 0 14px; font-size:13px; color:var(--tqk-muted);"><?php esc_html_e('Select a post type below to inspect trash items and perform accelerated deletion without WooCommerce term recount freezes.', 'tqk-wp-cache'); ?></p>
                        
                        <div class="tqk-trash-types-grid" id="tqk-trash-types-grid">
                            <div style="padding:16px; color:var(--tqk-muted); font-size:13px;"><?php esc_html_e('Loading trash overview…', 'tqk-wp-cache'); ?></div>
                        </div>
                    </div>

                    <div class="tqk-wpseo-card">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                            <div>
                                <h3 style="margin:0 0 4px; font-size:15px; font-weight:700; color:var(--tqk-ink);">
                                    <span id="tqk-trash-active-type-title"><?php esc_html_e('Trash Items', 'tqk-wp-cache'); ?></span>
                                </h3>
                                <p style="margin:0; font-size:13px; color:var(--tqk-muted);"><?php esc_html_e('Accelerated batch processor bypasses heavy count hooks during deletion for instant speed.', 'tqk-wp-cache'); ?></p>
                            </div>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-btn-sm" id="tqk-restore-trash-btn" disabled>
                                    <?php echo View::icon('refresh'); ?>
                                    <span><?php esc_html_e('Restore Selected', 'tqk-wp-cache'); ?></span>
                                </button>
                                <button type="button" class="tqk-btn tqk-btn-outline tqk-btn-sm is-danger" id="tqk-delete-trash-btn" disabled>
                                    <?php echo View::icon('trash'); ?>
                                    <span><?php esc_html_e('Delete Selected Permanently', 'tqk-wp-cache'); ?></span>
                                </button>
                                <button type="button" class="tqk-btn tqk-btn-primary tqk-btn-sm" id="tqk-empty-type-trash-btn">
                                    <?php echo View::icon('trash'); ?>
                                    <span><?php esc_html_e('Empty All Trash for This Type', 'tqk-wp-cache'); ?></span>
                                </button>
                            </div>
                        </div>

                        <div class="tqk-table-responsive">
                            <table class="tqk-data-table" id="tqk-trash-table">
                                <thead>
                                    <tr>
                                        <th style="width:36px;"><input type="checkbox" id="tqk-trash-select-all"></th>
                                        <th style="width:70px;"><?php esc_html_e('ID', 'tqk-wp-cache'); ?></th>
                                        <th><?php esc_html_e('Title', 'tqk-wp-cache'); ?></th>
                                        <th style="width:120px;"><?php esc_html_e('Author', 'tqk-wp-cache'); ?></th>
                                        <th style="width:150px;"><?php esc_html_e('Deleted At', 'tqk-wp-cache'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="5" style="text-align:center; padding:24px; color:var(--tqk-muted);">
                                            <?php esc_html_e('Select a post type above to view items in trash.', 'tqk-wp-cache'); ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
