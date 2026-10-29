<?php
namespace TQK_WP_CACHE\Optimization;

defined('ABSPATH') || exit;

/**
 * Small, safe front-end wins applied to the HTML of a page just before it is stored:
 *
 *  - Google Fonts links get `display=swap` (text is shown at once in a fallback font instead of staying invisible while the font loads)
 *    and the page preconnects to fonts.gstatic.com.
 *  - The image most likely to be the LCP element gets `fetchpriority="high"` and is never lazy-loaded (see LazyLoad).
 *  - CDN: URLs of static files (uploads, themes, plugins, wp-includes) in src / href / srcset / poster / data-* attributes point to the
 *    CDN host. Only attributes are rewritten: inline scripts and JSON keep the real address (a script that compares URLs must not break).
 *
 * Pure functions of the HTML (plus settings) so they are unit tested without WordPress.
 */
class FrontEnd {
    /** Add display=swap to every Google Fonts stylesheet URL that does not choose a display yet; preconnect to the font files host. */
    public static function fonts_swap(string $html): string
    {
        $found = false;
        $out = preg_replace_callback('~(<link\b[^>]*\bhref\s*=\s*)(["\'])(https?:)?(//fonts\.googleapis\.com/css2?\?[^"\']*)\2~i', static function ($m) use (&$found) {
            $found = true;
            $url = $m[4];
            if (!preg_match('/(?:[?&;]|&amp;)display=/i', $url)) {
                $url .= (substr($url, -1) === '?' ? '' : '&amp;') . 'display=swap';
            }
            return $m[1] . $m[2] . $m[3] . $url . $m[2];
        }, $html);
        $html = $out ?? $html;
        if ($found && stripos($html, 'rel="preconnect" href="https://fonts.gstatic.com"') === false && stripos($html, "href='https://fonts.gstatic.com'") === false) {
            $hint = '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
            $html = preg_replace('~<head\b[^>]*>~i', '$0' . $hint, $html, 1) ?? $html;
        }
        return $html;
    }

    /**
     * Rewrite static-file URLs to the CDN. $site = "https://example.com" (no slash), $cdn = "https://cdn.example.net" (no slash).
     * $exclude = substrings; a URL containing one of them stays on the site.
     */
    public static function cdn(string $html, string $site, string $cdn, array $exclude = []): string
    {
        $site = rtrim($site, '/');
        $cdn = rtrim($cdn, '/');
        if ($site === '' || $cdn === '' || !preg_match('~^https?://[^/\s"\'<>]+$~i', $cdn) || strcasecmp($site, $cdn) === 0) {
            return $html;
        }
        $host = preg_quote((string)wp_parse_url($site, PHP_URL_HOST), '~');
        // Absolute (http / https / protocol-relative) addresses of this site, or root-relative paths, under the static folders.
        // The lookbehind keeps a path that belongs to ANOTHER host (https://other.test/wp-content/...) from being treated as a root-relative one.
        $rx = '~(?<![A-Za-z0-9._:\-/])(?:(?:https?:)?//' . $host . ')?(/(?:wp-content/(?:uploads|themes|plugins)|wp-includes)/[^\s"\'<>(),]+\.(?:jpe?g|png|gif|webp|avif|svg|ico|css|js|woff2?|ttf|otf|eot|mp4|webm|mp3|pdf)(?:\?[^\s"\'<>(),]*)?)~i';
        $replace = static function (string $value) use ($rx, $cdn, $exclude): string {
            return preg_replace_callback($rx, static function ($m) use ($cdn, $exclude) {
                foreach ($exclude as $needle) {
                    if ($needle !== '' && stripos($m[0], $needle) !== false) {
                        return $m[0];
                    }
                }
                return $cdn . $m[1];
            }, $value) ?? $value;
        };

        // Only inside tags, only the attributes that hold a URL.
        return preg_replace_callback('~<(img|source|video|audio|link|script|iframe|a|input|track)\b([^>]*)>~i', static function ($m) use ($replace) {
            $tag = strtolower($m[1]);
            $attrs = preg_replace_callback('~\b(src|href|srcset|data-src|data-srcset|data-lazy-src|poster|data-bg)\s*=\s*(["\'])(.*?)\2~is', static function ($a) use ($replace, $tag) {
                $name = strtolower($a[1]);
                if ($name === 'href' && $tag === 'a') {
                    return $a[0];   // links to pages / downloads stay as they are; only stylesheets and the like (<link href>) move
                }
                return $a[1] . '=' . $a[2] . $replace($a[3]) . $a[2];
            }, $m[2]);
            return '<' . $m[1] . ($attrs ?? $m[2]) . '>';
        }, $html) ?? $html;
    }
}
