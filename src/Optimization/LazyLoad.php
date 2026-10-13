<?php
namespace TQK_WP_CACHE\Optimization;

defined('ABSPATH') || exit;

/**
 * Adds loading="lazy" to images and iframes that do not have it.
 * The first image is never lazy: it is usually the LCP element, and lazy-loading it makes Largest Contentful Paint worse.
 */
class LazyLoad {
    private bool $lazy_iframes;
    private bool $lcp_hint;

    public function __construct(bool $lazy_iframes = true, bool $lcp_hint = false) {
        $this->lazy_iframes = $lazy_iframes;
        $this->lcp_hint = $lcp_hint;
    }

    public function process(string $html): string {
        $seen = 0;
        // WordPress (6.3+) already marks one content image fetchpriority=high: do not add a second competing one.
        $lcp_done = stripos($html, 'fetchpriority="high"') !== false || stripos($html, "fetchpriority='high'") !== false;
        // Every <img> is looked at, also those that already say loading=..., because the LCP candidate may be one of them.
        $out = preg_replace_callback('/<img\b([^>]*?)>/i', function ($m) use (&$seen, &$lcp_done) {
            $attrs = $m[1];
            $seen++;
            $has_loading = (bool)preg_match('/\bloading\s*=/i', $attrs);

            // The image most likely to be the Largest Contentful Paint: among the first three images, the first that is not tiny
            // (a logo / icon has a small width attribute) gets fetchpriority=high and is never lazy.
            if ($this->lcp_hint && !$lcp_done && $seen <= 3 && stripos($attrs, 'data:image') === false && stripos($attrs, 'fetchpriority') === false && stripos($attrs, 'logo') === false
                && !(preg_match('/\bwidth\s*=\s*["\']?(\d+)/i', $attrs, $w) && (int)$w[1] < 300)) {
                $lcp_done = true;
                $attrs = preg_replace('/\s+loading\s*=\s*(["\']).*?\1/i', '', $attrs);
                return '<img fetchpriority="high"' . (stripos($attrs, 'decoding=') === false ? ' decoding="async"' : '') . $attrs . '>';
            }

            if ($has_loading || $seen === 1 || stripos($attrs, 'data:image') !== false || stripos($attrs, 'skip-lazy') !== false || stripos($attrs, 'fetchpriority="high"') !== false) {
                return $m[0];
            }
            return '<img loading="lazy"' . $attrs . '>';
        }, $html);
        $html = $out ?? $html;

        if ($this->lazy_iframes) {
            $out = preg_replace_callback('/<iframe\b(?![^>]*\bloading\s*=)([^>]*?)>/i', function ($m) {
                if (stripos($m[1], 'skip-lazy') !== false) {
                    return $m[0];
                }
                return '<iframe loading="lazy"' . $m[1] . '>';
            }, $html);
            $html = $out ?? $html;
        }

        return $html;
    }
}
