<?php
// Printed by Dashboard::render_view(): footer. Runs inside that method, so $c (settings), $is_pro, $stats, $is_engine_ready,
// $current_lang and $this are available. Markup only; the logic of the screen lives in Dashboard.php and the AJAX classes.
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;
?>
                <!-- Sticky Submit Action Bar (AJAX without reload) -->
                <div class="tqk-sticky-save">
                    <div class="tqk-save-meta">
                        <span id="tqk-save-status" class="tqk-save-status"></span>
                    </div>
                    <button type="submit" id="tqk-save-btn" class="tqk-btn tqk-btn-primary">
                        <?php echo View::icon('save'); ?>
                        <span><?php esc_html_e('Save Settings (No Reload)', 'tqk-wp-cache'); ?></span>
                    </button>
                </div>
                    </form>
                </main>
            </div>

            <!-- Hidden Iframe for Zero-Overhead Client-Side Crawler Warmup -->
            <iframe id="tqk-crawler-frame" src="about:blank" style="position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;border:none;"></iframe>
