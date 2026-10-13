<?php
namespace TQK_WP_CACHE\Admin;

defined('ABSPATH') || exit;

/**
 * Shared admin view components for TQK WP Cache.
 * Follows TQK WP SEO design system: pure Tailwind-modern styling,
 * zero emoji (inline SVGs only), clean cards, and scoped CSS.
 */
class View {

    public static function mark(): string {
        return '<span class="tqk-wpseo-mark" aria-hidden="true">'
            . '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>'
            . '</svg>'
            . '</span>';
    }

    public static function icon(string $name): string {
        $icons = [
            'bolt' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>',
            'dashboard' => '<rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect>',
            'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>',
            'code' => '<polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline>',
            'image' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>',
            'server' => '<rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line>',
            'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
            'trash' => '<polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>',
            'refresh' => '<polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>',
            'check' => '<polyline points="20 6 9 17 4 12"></polyline>',
            'save' => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline>',
            'zap' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>',
            'info' => '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line>',
        ];

        $inner = $icons[$name] ?? $icons['bolt'];
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
    }

    public static function stat_card(string $label, string $value, string $note = '', string $icon = 'database', string $tone = ''): string {
        $tone_class = $tone !== '' ? ' is-' . esc_attr($tone) : '';
        return sprintf(
            '<div class="tqk-wpseo-stat-card">'
            . '<span class="tqk-wpseo-stat-ico%s">%s</span>'
            . '<div>'
            . '<div class="tqk-wpseo-stat-value">%s</div>'
            . '<div class="tqk-wpseo-stat-label">%s</div>'
            . ($note !== '' ? '<div class="tqk-wpseo-stat-note">' . esc_html($note) . '</div>' : '')
            . '</div>'
            . '</div>',
            $tone_class,
            self::icon($icon),
            $value,
            esc_html($label)
        );
    }

    public static function author_card(): string {
        $row = static fn(string $k, string $v, string $copy = '') => '<div class="tqk-author-row"><span>' . esc_html($k) . '</span><strong>' . esc_html($v) . '</strong>'
            . ($copy !== '' ? '<button type="button" class="button button-small tqk-copy" data-copy="' . esc_attr($copy) . '">' . esc_html__('Copy', 'tqk-wp-cache') . '</button>' : '') . '</div>';

        return '<div class="tqk-author">'
            . '<div class="tqk-author-col">'
            . '<div class="tqk-author-title">' . esc_html__('Contact', 'tqk-wp-cache') . '</div>'
            . '<div class="tqk-author-name">' . esc_html__('TQK WP CACHE - Developed by', 'tqk-wp-cache') . ' <strong>Trương Quang Khải</strong></div>'
            . '<div class="tqk-author-links">'
            . '<a href="tel:0364999916">0364 999 916</a>'
            . '<a href="mailto:roo.adm@gmail.com">roo.adm@gmail.com</a>'
            . '<a href="https://www.facebook.com/Unk.khai/" target="_blank" rel="noopener">facebook.com/Unk.khai</a>'
            . '<a href="https://conggame.online" target="_blank" rel="noopener">conggame.online</a>'
            . '</div>'
            . '</div>'
            . '<div class="tqk-author-col tqk-donate">'
            . '<div class="tqk-author-title">' . esc_html__('Support / Donate', 'tqk-wp-cache') . '</div>'
            . $row(__('Bank', 'tqk-wp-cache'), 'Vietcombank')
            . $row(__('Account Number', 'tqk-wp-cache'), '0501 000 151853', '0501000151853')
            . $row(__('Account Name', 'tqk-wp-cache'), 'Truong Quang Khai')
            . $row(__('Branch', 'tqk-wp-cache'), 'Củ Chi')
            . $row('SWIFT/BIC', 'BFTVVNVX', 'BFTVVNVX')
            . '</div>'
            . '</div>';
    }
}
