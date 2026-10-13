<?php
// Printed by Dashboard::render_view() ONLY when the TQK WP Cache PRO add-on is not active: a short description of what it adds.
// The add-on prints its own tabs through the `tqk_cache_panes_pro` action instead. Markup only; no PRO logic lives in this plugin.
namespace TQK_WP_CACHE\Admin;

defined('ABSPATH') || exit;
?>
                <!-- Tab: what the PRO add-on adds -->
                <div id="tab-pro" class="tqk-tab-pane">
                    <h2 class="tqk-wpseo-section"><?php esc_html_e('TQK WP Cache PRO (add-on)', 'tqk-wp-cache'); ?></h2>
                    <div class="tqk-wpseo-card">
                        <p style="margin:0 0 12px;"><?php esc_html_e('PRO is a separate plugin that works on top of this one. Everything in this plugin stays free; PRO adds the following:', 'tqk-wp-cache'); ?></p>
                        <ul style="margin:0 0 16px 18px; list-style:disc; line-height:1.8;">
                            <li><?php esc_html_e('Smart purge and smart refresh: only the pages that really showed the changed content are rebuilt, in the background.', 'tqk-wp-cache'); ?></li>
                            <li><?php esc_html_e('SQL dependency map: see which database tables and queries each cached page depends on.', 'tqk-wp-cache'); ?></li>
                            <li><?php esc_html_e('Query cache and persistent object cache (disk, Redis, Memcached).', 'tqk-wp-cache'); ?></li>
                            <li><?php esc_html_e('Cached pages for logged-in members on the paths you choose.', 'tqk-wp-cache'); ?></li>
                            <li><?php esc_html_e('Image and WebP optimiser with bulk compression and a before / after viewer.', 'tqk-wp-cache'); ?></li>
                        </ul>
                        <button type="button" class="tqk-btn tqk-btn-primary tqk-open-pro-modal-btn">
                            <?php echo View::icon('shield'); ?>
                            <span><?php esc_html_e('How to get PRO', 'tqk-wp-cache'); ?></span>
                        </button>
                    </div>
                </div>
