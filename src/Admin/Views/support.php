<?php
// Printed by Dashboard::render_view() OUTSIDE the tabs and the settings form, so it is always visible and its fields are never saved as settings:
// author contact, server information, the incident folder and the "report a problem" form. Markup only.
namespace TQK_WP_CACHE\Admin;

defined('ABSPATH') || exit;

$latest = IncidentLog::dir() . '/latest.json';
$latest_time = is_file($latest) ? (int)filemtime($latest) : 0;
?>
            <section class="tqk-support" id="tqk-support">
                <div class="tqk-support-grid">
                    <div class="tqk-wpseo-card">
                        <h3 class="tqk-support-title"><?php esc_html_e('Author Information & Technical Support', 'tqk-wp-cache'); ?></h3>
                        <?php echo View::author_card(); ?>
                    </div>

                    <div class="tqk-wpseo-card">
                        <h3 class="tqk-support-title"><?php esc_html_e('Server Environment Information:', 'tqk-wp-cache'); ?></h3>
                        <ul class="tqk-support-list">
                            <li><strong><?php esc_html_e('Web Server:', 'tqk-wp-cache'); ?></strong> <code><?php echo esc_html($_SERVER['SERVER_SOFTWARE'] ?? 'N/A'); ?></code></li>
                            <li><strong><?php esc_html_e('PHP Version:', 'tqk-wp-cache'); ?></strong> <code><?php echo esc_html(PHP_VERSION); ?></code></li>
                            <li><strong><?php esc_html_e('Cache Directory:', 'tqk-wp-cache'); ?></strong> <code><?php echo esc_html(TQK_WP_CACHE_STORAGE_DIR); ?></code></li>
                            <li><strong><?php esc_html_e('Gzip Support:', 'tqk-wp-cache'); ?></strong> <code><?php echo function_exists('gzencode') ? esc_html__('Yes (Ready)', 'tqk-wp-cache') : esc_html__('No', 'tqk-wp-cache'); ?></code></li>
                        </ul>
                    </div>

                    <div class="tqk-wpseo-card">
                        <h3 class="tqk-support-title"><?php esc_html_e('Incident log', 'tqk-wp-cache'); ?></h3>
                        <p class="tqk-field-desc"><?php esc_html_e('When something goes wrong (a fatal error in the plugin, a drop-in that cannot be written, a read-only cache folder, Cloudflare refusing a purge), a report is saved by itself in this folder. Give support the link.', 'tqk-wp-cache'); ?></p>
                        <ul class="tqk-support-list">
                            <li><strong><?php esc_html_e('Folder:', 'tqk-wp-cache'); ?></strong> <code><?php echo esc_html(IncidentLog::dir()); ?></code></li>
                            <li><strong><?php esc_html_e('Link to the newest incident:', 'tqk-wp-cache'); ?></strong>
                                <a href="<?php echo esc_url(IncidentLog::url()); ?>" target="_blank" rel="noopener"><code><?php echo esc_html(IncidentLog::url()); ?></code></a>
                                <button type="button" class="button button-small tqk-copy" data-copy="<?php echo esc_attr(IncidentLog::url()); ?>"><?php esc_html_e('Copy', 'tqk-wp-cache'); ?></button></li>
                            <li><strong><?php esc_html_e('Last incident:', 'tqk-wp-cache'); ?></strong>
                                <?php echo $latest_time ? esc_html(wp_date('Y-m-d H:i', $latest_time)) : esc_html__('none recorded', 'tqk-wp-cache'); ?></li>
                        </ul>
                        <button type="button" class="tqk-btn tqk-btn-outline" id="tqk-diag-btn">
                            <?php echo View::icon('save'); ?>
                            <span><?php esc_html_e('Download support report', 'tqk-wp-cache'); ?></span>
                        </button>
                        <span id="tqk-diag-msg" class="tqk-support-msg"></span>
                    </div>

                    <div class="tqk-wpseo-card">
                        <h3 class="tqk-support-title"><?php esc_html_e('Report a problem', 'tqk-wp-cache'); ?></h3>
                        <div class="tqk-support-form" id="tqk-support-form">
                            <div class="tqk-support-row">
                                <input type="text" id="tqk-sup-name" maxlength="80" placeholder="<?php esc_attr_e('Your name', 'tqk-wp-cache'); ?>">
                                <input type="email" id="tqk-sup-email" maxlength="120" placeholder="<?php esc_attr_e('Your e-mail (so support can answer)', 'tqk-wp-cache'); ?>">
                            </div>
                            <textarea id="tqk-sup-message" rows="5" maxlength="5000" placeholder="<?php esc_attr_e('What happened? What did you expect? Which page?', 'tqk-wp-cache'); ?>"></textarea>
                            <label class="tqk-support-check">
                                <input type="checkbox" id="tqk-sup-attach" checked>
                                <span><?php esc_html_e('Attach the support report (versions, theme, settings, last log lines; passwords, tokens, keys and e-mail addresses are removed)', 'tqk-wp-cache'); ?></span>
                            </label>
                            <p class="tqk-field-desc"><?php
                                /* translators: %s: support e-mail address */
                                printf(esc_html__('Nothing is sent until you press the button. The message goes to %s.', 'tqk-wp-cache'), '<strong>' . esc_html(Support::to()) . '</strong>'); ?></p>
                            <button type="button" class="tqk-btn tqk-btn-primary" id="tqk-sup-send">
                                <?php echo View::icon('save'); ?>
                                <span><?php esc_html_e('Send to support', 'tqk-wp-cache'); ?></span>
                            </button>
                            <span id="tqk-sup-msg" class="tqk-support-msg"></span>
                        </div>
                    </div>
                </div>
            </section>
