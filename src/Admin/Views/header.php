<?php
// Printed by Dashboard::render_view(): header. Runs inside that method, so $c (settings), $is_pro, $stats, $is_engine_ready,
// $current_lang and $this are available. Markup only; the logic of the screen lives in Dashboard.php and the AJAX classes.
namespace TQK_WP_CACHE\Admin;

use TQK_WP_CACHE\Core\Config;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Dropin;

defined('ABSPATH') || exit;
?>
            <!-- Toast notification container -->
            <div id="tqk-wpseo-toasts"></div>

            <!-- Header shell -->
            <div class="tqk-wpseo-head">
                <div class="tqk-wpseo-brandrow">
                    <?php echo View::mark(); ?>
                    <div>
                        <p class="tqk-wpseo-brand" style="margin:0">
                            <?php esc_html_e('TQK WP CACHE', 'tqk-wp-cache'); ?>
                            <span class="tqk-wpseo-ver"><?php echo esc_html(TQK_WP_CACHE_VERSION); ?></span>
                            <?php if (has_action('tqk_cache_header_badge')) : ?>
                                <?php do_action('tqk_cache_header_badge', $this); ?>
                            <?php else : ?>
                                <span class="tqk-nav-pro-badge tqk-open-pro-modal-btn" style="cursor:pointer;" title="<?php esc_attr_e('Click to upgrade to PRO', 'tqk-wp-cache'); ?>">FREE (UPGRADE PRO)</span>
                            <?php endif; ?>
                        </p>
                        <h1><?php esc_html_e('Cache & Core Web Vitals Optimization', 'tqk-wp-cache'); ?></h1>
                    </div>
                    <!-- Instant Bilingual Language Switcher -->
                    <div class="tqk-lang-switcher" title="<?php esc_attr_e('Switch Language / Chọn ngôn ngữ', 'tqk-wp-cache'); ?>">
                        <button type="button" class="tqk-lang-btn <?php echo $current_lang === 'vi' ? 'is-active' : ''; ?>" data-lang="vi">VI</button>
                        <span class="tqk-lang-sep">/</span>
                        <button type="button" class="tqk-lang-btn <?php echo $current_lang === 'en' ? 'is-active' : ''; ?>" data-lang="en">EN</button>
                    </div>
                </div>
                <div class="tqk-wpseo-actions">
                    <button type="button" class="tqk-btn tqk-btn-outline tqk-refresh-stats-btn">
                        <?php echo View::icon('refresh'); ?>
                        <span><?php esc_html_e('Refresh Stats', 'tqk-wp-cache'); ?></span>
                    </button>
                    <button type="button" class="tqk-btn tqk-btn-outline tqk-purge-all-btn">
                        <?php echo View::icon('trash'); ?>
                        <span><?php esc_html_e('Clear Cache', 'tqk-wp-cache'); ?></span>
                    </button>
                    <button type="button" class="tqk-btn tqk-btn-primary tqk-start-warmup-btn">
                        <?php echo View::icon('zap'); ?>
                        <span><?php esc_html_e('Purge & Warmup Cache', 'tqk-wp-cache'); ?></span>
                    </button>
                </div>
            </div>

            <!-- Hero Section -->
            <div class="tqk-wpseo-hero">
                <div class="tqk-wpseo-hero-meta">
                    <div class="tqk-hero-kicker"><?php esc_html_e('Hybrid Server-Level & Front-End Booster Architecture', 'tqk-wp-cache'); ?></div>
                    <h2><?php esc_html_e('Faster Pages With a Static HTML Cache & Smart JavaScript Delay', 'tqk-wp-cache'); ?></h2>
                    <p class="tqk-hero-desc">
                        <?php esc_html_e('Cached pages are served straight from disk before WordPress loads, and heavy scripts wait for the first visitor interaction.', 'tqk-wp-cache'); ?>
                    </p>
                    <p class="tqk-hero-support">
                        <span><?php esc_html_e('Technical Support:', 'tqk-wp-cache'); ?></span>
                        <a href="tel:0364999916">0364 999 916</a>
                        <span>·</span>
                        <a href="https://www.facebook.com/Unk.khai/" target="_blank" rel="noopener">Trương Quang Khải</a>
                    </p>
                </div>
                <div class="tqk-wpseo-hero-stats">
                    <div class="tqk-wpseo-hero-chip">
                        <strong id="stat-cache-size"><?php echo esc_html($stats['size_formatted']); ?></strong>
                        <span><?php esc_html_e('Cache Size', 'tqk-wp-cache'); ?></span>
                    </div>
                    <div class="tqk-wpseo-hero-chip">
                        <strong id="stat-cache-files"><?php echo esc_html(number_format_i18n($stats['files'])); ?></strong>
                        <span><?php esc_html_e('Cached Files', 'tqk-wp-cache'); ?></span>
                    </div>
                    <div class="tqk-wpseo-hero-chip <?php echo $is_engine_ready ? '' : 'is-bad'; ?>">
                        <strong id="stat-dropin-status"><?php echo $is_engine_ready ? esc_html__('Active', 'tqk-wp-cache') : esc_html__('Off', 'tqk-wp-cache'); ?></strong>
                        <span><?php esc_html_e('Drop-in Engine', 'tqk-wp-cache'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Vertical Layout Grid: Sidebar Navigation on the Left + Content on the Right -->
            <div class="tqk-vertical-layout">
                <aside class="tqk-vertical-sidebar">
                    <nav class="tqk-vertical-nav" role="tablist">
                        <div class="tqk-nav-group-title"><?php esc_html_e('General Settings', 'tqk-wp-cache'); ?></div>
                        <button type="button" class="tqk-tab-nav active" data-tab="tab-dashboard">
                            <span class="tqk-nav-ico"><?php echo View::icon('dashboard'); ?></span>
                            <span class="tqk-nav-text"><?php esc_html_e('Overview & Presets', 'tqk-wp-cache'); ?></span>
                        </button>
                        <button type="button" class="tqk-tab-nav" data-tab="tab-pagecache">
                            <span class="tqk-nav-ico"><?php echo View::icon('database'); ?></span>
                            <span class="tqk-nav-text"><?php esc_html_e('Page Cache', 'tqk-wp-cache'); ?></span>
                        </button>

                        <div class="tqk-nav-group-title"><?php esc_html_e('Speed & CWV', 'tqk-wp-cache'); ?></div>
                        <button type="button" class="tqk-tab-nav" data-tab="tab-combine">
                            <span class="tqk-nav-ico"><?php echo View::icon('code'); ?></span>
                            <span class="tqk-nav-text"><?php esc_html_e('Combine CSS & JS', 'tqk-wp-cache'); ?></span>
                        </button>
                        <button type="button" class="tqk-tab-nav" data-tab="tab-speed">
                            <span class="tqk-nav-ico"><?php echo View::icon('zap'); ?></span>
                            <span class="tqk-nav-text"><?php esc_html_e('Delay JS & Optimization', 'tqk-wp-cache'); ?></span>
                        </button>
                        <button type="button" class="tqk-tab-nav" data-tab="tab-media">
                            <span class="tqk-nav-ico"><?php echo View::icon('image'); ?></span>
                            <span class="tqk-nav-text"><?php esc_html_e('Media & LazyLoad', 'tqk-wp-cache'); ?></span>
                        </button>

                        <div class="tqk-nav-group-title"><?php esc_html_e('Database & Cleanup', 'tqk-wp-cache'); ?></div>
                        <button type="button" class="tqk-tab-nav" data-tab="tab-database">
                            <span class="tqk-nav-ico"><?php echo View::icon('database'); ?></span>
                            <span class="tqk-nav-text"><?php esc_html_e('Database & Revisions', 'tqk-wp-cache'); ?></span>
                        </button>
                        <button type="button" class="tqk-tab-nav" data-tab="tab-trash">
                            <span class="tqk-nav-ico"><?php echo View::icon('trash'); ?></span>
                            <span class="tqk-nav-text"><?php esc_html_e('Trash Manager', 'tqk-wp-cache'); ?></span>
                        </button>

                        <div class="tqk-nav-group-title"><?php esc_html_e('Next-Gen Media & DB (Pro)', 'tqk-wp-cache'); ?></div>
                        <?php if (has_action('tqk_cache_nav_pro')) : ?>
                            <?php do_action('tqk_cache_nav_pro', $this); ?>
                        <?php else : ?>
                            <button type="button" class="tqk-tab-nav" data-tab="tab-pro">
                                <span class="tqk-nav-ico"><?php echo View::icon('shield'); ?></span>
                                <span class="tqk-nav-text"><?php esc_html_e('What PRO adds', 'tqk-wp-cache'); ?> <span class="tqk-nav-pro-badge">PRO</span></span>
                            </button>
                        <?php endif; ?>

                        <div class="tqk-nav-group-title"><?php esc_html_e('Advanced & System', 'tqk-wp-cache'); ?></div>
                        <button type="button" class="tqk-tab-nav" data-tab="tab-rules">
                            <span class="tqk-nav-ico"><?php echo View::icon('shield'); ?></span>
                            <span class="tqk-nav-text"><?php esc_html_e('Rules & Smart Purge', 'tqk-wp-cache'); ?></span>
                        </button>
                        <button type="button" class="tqk-tab-nav" data-tab="tab-server">
                            <span class="tqk-nav-ico"><?php echo View::icon('server'); ?></span>
                            <span class="tqk-nav-text"><?php esc_html_e('Server & LiteSpeed', 'tqk-wp-cache'); ?></span>
                        </button>
                    </nav>
                </aside>

                <main class="tqk-vertical-content">
                    <form id="tqk-ajax-form" method="post" action="">
                        <input type="hidden" name="active_preset" id="field_active_preset" value="<?php echo esc_attr($c['active_preset'] ?? 'balanced'); ?>">
