<?php
// Printed by Dashboard::render_view(): modals. Runs inside that method, so $c (settings), $is_pro, $stats, $is_engine_ready,
// $current_lang and $this are available. Markup only; the logic of the screen lives in Dashboard.php and the AJAX classes.
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;
?>
            <?php do_action('tqk_cache_modals', $this); ?>

            <!-- Pro License Upgrade Modal -->
            <div id="tqk-pro-modal" class="tqk-modal-overlay is-hidden" role="dialog" aria-modal="true" aria-labelledby="tqk-pro-modal-title">
                <div class="tqk-modal-box" style="max-width:560px;">
                    <div class="tqk-modal-header" style="background: linear-gradient(135deg, #1e1b4b, #312e81); color:#fff;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span class="tqk-pro-badge" style="margin:0;">PRO EDITION</span>
                            <h3 id="tqk-pro-modal-title" style="margin:0; font-size:16px; font-weight:700; color:#fff;">
                                <?php esc_html_e('Upgrade to TQK WP Cache PRO', 'tqk-wp-cache'); ?>
                            </h3>
                        </div>
                        <button type="button" class="tqk-modal-close" id="tqk-pro-modal-close-btn" style="color:#c7d2fe;" aria-label="<?php esc_attr_e('Close modal', 'tqk-wp-cache'); ?>">&times;</button>
                    </div>

                    <div class="tqk-modal-body" style="background:#fff; padding:24px; color:#1e293b; display:block;">
                        <p style="margin:0 0 16px; font-size:14px; line-height:1.6; color:#475569;">
                            <?php esc_html_e('Unlock background bulk media compression, automatic image optimization on upload, and high-performance WebP delivery across your entire website.', 'tqk-wp-cache'); ?>
                        </p>

                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:16px;">
                            <h4 style="margin:0 0 10px; font-size:13px; font-weight:700; text-transform:uppercase; color:#0f172a; letter-spacing:0.04em;">
                                <?php esc_html_e('Direct Author Contact & Support', 'tqk-wp-cache'); ?>
                            </h4>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:13px;">
                                <div>
                                    <span style="color:#64748b; display:block; font-size:11.5px;"><?php esc_html_e('Author:', 'tqk-wp-cache'); ?></span>
                                    <strong>Trương Quang Khải</strong>
                                </div>
                                <div>
                                    <span style="color:#64748b; display:block; font-size:11.5px;"><?php esc_html_e('Hotline / Zalo:', 'tqk-wp-cache'); ?></span>
                                    <a href="tel:0364999916" style="color:#3b82f6; font-weight:700;">0364 999 916</a>
                                </div>
                                <div>
                                    <span style="color:#64748b; display:block; font-size:11.5px;"><?php esc_html_e('Vietcombank:', 'tqk-wp-cache'); ?></span>
                                    <strong style="font-family:monospace; font-size:14px;">0501 000 151853</strong>
                                </div>
                                <div>
                                    <span style="color:#64748b; display:block; font-size:11.5px;"><?php esc_html_e('Email:', 'tqk-wp-cache'); ?></span>
                                    <span>roo.adm@gmail.com</span>
                                </div>
                            </div>
                        </div>

                        <?php if (has_action('tqk_cache_pro_modal_body')) : ?>
                            <?php do_action('tqk_cache_pro_modal_body', $this); ?>
                        <?php else : ?>
                            <p style="margin:0; font-size:13px; line-height:1.6; color:#475569;">
                                <?php esc_html_e('PRO is a separate add-on plugin, "TQK WP Cache PRO". After purchase you receive it together with a licence key. Install and activate it, then enter the key here.', 'tqk-wp-cache'); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="tqk-modal-footer">
                        <a href="https://www.facebook.com/Unk.khai/" target="_blank" rel="noopener" class="tqk-btn tqk-btn-outline" style="font-size:12px;">
                            <?php echo View::icon('external'); ?>
                            <span>Facebook Author</span>
                        </a>
                        <button type="button" class="tqk-btn tqk-btn-outline" id="tqk-pro-modal-done-btn"><?php esc_html_e('Close', 'tqk-wp-cache'); ?></button>
                    </div>
                </div>
            </div>
