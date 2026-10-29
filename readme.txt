=== TQK WP Cache ===
Contributors: tqkktruong
Tags: cache, caching, performance, speed, delay-js, page-cache
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

High-performance static page caching, safe JavaScript delay, hover-based instant preload, asset optimization, and database cleanup for WordPress.

== Description ==

TQK WP Cache is a lightweight caching and performance optimization engine for WordPress. It saves every page as a static HTML file and serves it directly via `advanced-cache.php` before WordPress even starts booting, significantly reducing Time to First Byte (TTFB) and server CPU load.

= Key Features =

* **Static Page Cache**: Delivers cached HTML instantly via `advanced-cache.php` with gzip compression, ETag / 304 Not Modified support, separate mobile cache, and SSL distinction.
* **Safe JavaScript Delaying**: Delays non-critical JavaScript until user interaction (keyboard/scroll/touch) to maximize FID and INP performance while preserving inline scripts.
* **Instant Page Preload**: Hover-based preloading for instant page transitions without side-effect triggers (ignores `add-to-cart`, `logout`, `_wpnonce`).
* **Smart Tag-Based Cache Purge**: Automatically invalidates only relevant pages when content or products update, avoiding unnecessary full-site purges.
* **Asset Optimization**: Safe sequential CSS and JS concatenation and HTML minification.
* **Database Optimization**: Clean up post revisions, expired transients, auto-drafts, and orphaned postmeta.
* **Cloudflare Integration**: Synchronizes cache purges with Cloudflare Edge Cache.
* **Bilingual Interface**: Native support for English and Vietnamese (Tiếng Việt).

== 3rd Party Services ==

This plugin can optionally connect to external third-party services:

* Cloudflare API (https://api.cloudflare.com/)
  - Purpose: Synchronizes cache purges with Cloudflare Edge Cache when content is modified on your site.
  - Data sent: Purged URLs or zone purge requests, along with your Cloudflare Zone ID and API token.
  - Terms of Service: https://www.cloudflare.com/terms/
  - Privacy Policy: https://www.cloudflare.com/privacypolicy/

== Installation ==

1. Upload the `tqk-wp-cache` directory to the `/wp-content/plugins/` directory, or upload the zip directly in **Plugins → Add New**.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure `wp-content/` is writable so the plugin can create the cache directory and `advanced-cache.php` drop-in.
4. Go to **TQK WP Cache** in your admin menu to configure settings.

== Frequently Asked Questions ==

= Does this plugin work with WooCommerce? =
Yes. Cart, checkout, and account pages are never cached, and customer sessions remain dynamic.

= Does it automatically purge cache on post update? =
Yes. It uses smart tag-based purging to clear only the updated post, its category/tag archives, and the homepage.

= Do I need a Cloudflare account? =
No. Cloudflare integration is entirely optional.

= Is the uninstallation clean? =
Yes. Deleting the plugin automatically removes cache files, drop-in files, database options, and scheduled cron events.

== Screenshots ==

1. Overview dashboard with cache status and performance controls.
2. Page caching settings and cache rules.
3. Speed optimizations (Delay JS, Instant Page, Minify).
4. Database optimization and cleanup tools.
5. Cloudflare edge cache synchronization.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Static page caching with advanced-cache.php drop-in.
* Safe JavaScript delay and Instant Page hover preloading.
* Tag-based smart cache purge and queue system.
* Database maintenance and trash manager.
* Optional Cloudflare edge cache integration.
* Full Vietnamese and English language support.
