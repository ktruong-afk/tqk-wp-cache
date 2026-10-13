# Changelog

## 1.1.0 — support tools

- **Incident log**: when something goes wrong (a fatal error in this plugin or its add-on, the drop-in cannot be installed, the cache folder is
  read-only, Cloudflare refuses a purge, the home page is not answered by the cache, found by a daily check) a redacted support report is saved
  by itself in one fixed folder, `wp-content/tqk-logs/<site token>/` (`latest.json` + `incident-<date>-<type>.json`). One report per type per 6 hours,
  the newest 40 are kept, 30 days at most. The folder has an index file and a deny rule for scripts.
- **Support report** (JSON, secrets and e-mail addresses removed): versions, theme + page builders, other cache plugins, drop-in and folder state, a
  probe of the home page, cron, last log lines, settings. The add-on appends its part through `tqk_cache_diagnostics`.
- **Report a problem** form: message (+ optional report attached) is e-mailed to support; nothing is sent without a click. Filter `tqk_cache_support_email`.
- The "Author & Support" tab is gone: contact details, server information, the incident folder link and the form now sit under the tabs, always visible.

## 1.1.0 — PRO is now a separate add-on plugin

Why: a licence check inside the same PHP files as the features can always be edited out by whoever holds the files. The paid features now
live in **TQK WP Cache PRO**, a second plugin that is handed out only to buyers; the free plugin contains none of their code.

- **Free plugin** keeps everything it had: page cache, purge by tag, rebuild modes, combine / delay JS, lazy load, CDN + Cloudflare, admin
  Turbo and HTTP control, live counters, database / trash tools. Nothing is switched off for existing users.
- **Moved to the add-on**: smart purge + smart refresh, SQL dependency map (Smart Map), table watching / write report, query cache, TQK
  object cache (disk / Redis / Memcached), member-aware page cache (`[tqk_member]` / `[tqk_guest]`), image + WebP optimiser, licence keys.
- **Extension points** (hook API level `TQK_WP_CACHE_API` = 2) the add-on uses; other add-ons may use them too:
  filters `tqk_cache_is_pro`, `tqk_cache_defaults`, `tqk_cache_flag_keys`, `tqk_cache_text_keys`, `tqk_cache_int_rules`, `tqk_cache_save_fields`,
  `tqk_cache_smart_purge`, `tqk_cache_member_paths`, `tqk_cache_page_tags`; actions `tqk_cache_loaded`, `tqk_cache_settings_saved`,
  `tqk_cache_pages_purged`, `tqk_cache_page_stored`, `tqk_cache_daily`, `tqk_cache_deactivating`, and the admin screens
  `tqk_cache_header_badge`, `tqk_cache_nav_pro`, `tqk_cache_panes_pro`, `tqk_cache_modals`, `tqk_cache_pro_modal_body`.
- Without the add-on the PRO area shows a short "what PRO adds" tab; with it the same place holds the image and object-cache tabs.
- Settings of the add-on are kept in the free plugin's settings and survive a save made while the add-on is switched off.
- `window.tqkCache` (showToast, getLogTime, switchTab, saveSettings) and the `tqk:tab` event let an add-on script reuse the admin screen.
- Upgrading from 1.0: the licence key is moved automatically to the add-on the first time it runs. If you use the TQK object cache,
  activate the add-on right after updating (until then WordPress simply uses its normal in-request cache); the add-on starts the disk
  store empty because anything left there may be older than the database.
- Tests: `tests/run.php` is 101 checks without PRO; the PRO tests moved to the add-on. `python tools/package.py` builds both zips and
  fails if any PRO marker is found in the free one.

## 1.1.0 (unreleased) — correctness & honesty release

Every item below was reproduced on a real WordPress site first (Elementor + WooCommerce + MasterStudy + Revolution Slider), fixed, and
is now covered by `tests/run.php` (82 unit checks) or `tests/integration.sh` (21 checks against a live site).

### Rebuild modes, wp-admin Turbo, object cache
- **Rebuild modes** (Dashboard > warm-up card, free): *key pages* (home, pages, one sample per type; their old copy is dropped first),
  *not cached yet* (every public page without a fresh cache, up to a limit of 10-5,000; after a tag purge that is exactly what the purge
  removed) and *clear only* (everything is purged, visitors rebuild it, no server work now). The choice is remembered.
- Admin Turbo: `wp_localize_script` sends PHP `false` as `""`, which the script treated as ON, so a disabled Turbo kept running and was
  recorded as Turbo. Fixed; the script is no longer loaded when both Turbo and the HUD are off; saving the setting applies at once to
  open tabs; a stale click marker can no longer be recorded as a load time of another page.
- **Admin outgoing requests** (Speed tab, free, all OFF by default): block tracking pixels (pixel.wp.com, stats.wp.com), remember answers of
  slow harmless endpoints (envato.github.io, api.wordpress.org translations / events, plus your own list) for 1-72 h, and skip the WP-Cron
  loop-back on admin views. Core / plugin / theme update checks are never touched. On the demo site an admin page went from ~3.7 s to ~1.25 s.
- **Smart purge** (PRO): publishing or editing no longer refreshes the homepage by default; only pages whose recorded dependencies (post ids,
  post types, terms) match are purged. Tests: edit a product -> homepage kept; edit a post -> homepage purged.
- **Smart refresh** (PRO, needs smart purge): after a purge, the pages that were purged are re-rendered by WP-Cron right away (homepage first, at
  most N per change, one at a time with pauses), so the first visitor does not pay for the rebuild. The purge still happens first; pages beyond
  the limit stay lazy. Works with any theme or builder because it re-renders the real page. Demo: edit a post -> home and /blog/ queued, rebuilt
  in 3.6 s, next visitor gets HIT.
- **Live numbers** (free): `[tqk_live source="comments_total|comments_post|users_total|posts_count|meta"]` / `tqk_live()` keep their render-time value in the
  cached HTML and a ~700-byte inline script replaces it with the current value from `/wp-json/tqk/v1/live` (cacheable 30 s; only keys listed under
  "Live meta keys" are readable). Verified in Chrome: cached HTML said 2, the visitor saw 7.
- **Likes / votes**: a post-meta change refreshes the pages that show the post, at most once per cool-down (default 60 s), the rest collected and
  refreshed when it ends. WordPress internals, builder caches and the usual view counters are ignored; the site owner can add keys.
- **Builder templates**: saving an Elementor / Divi / Bricks / Avada / Beaver template, a block-theme template, template part, global styles,
  navigation or reusable block purges the whole cache (pages cannot name these templates in their tags). Filter: `tqk_cache_template_post_types`.
- Comments inserted without `comment_post` (imports, WP-CLI, REST) now purge the post too.
- **Counted tables** (PRO, with smart purge): the SQL a page runs while it is built is read. An aggregate (COUNT / SUM / AVG / MIN / MAX) over users,
  comments or any plugin table makes the page depend on `tbl-<table>`; a COUNT of a post type depends on `list-<type>`; extra tables to watch can be
  listed. Any later INSERT / UPDATE / DELETE on that table (registration, comment, plugin record) refreshes those pages with the same cool-down as
  post meta. Demo: "Members: 2" page was purged when a user was created.
- **Pages that write while shown** (PRO): the Smart Map card lists pages whose build wrote to the database (view counters, visit logs) with a warning that a
  cached copy under-counts.
- **Member pages** (PRO): paths listed in Smart Map are served from the shared cache to plain members (cookie `tqk_member=1`, set at login only for
  users who cannot edit; staff and non-listed paths stay live). `[tqk_guest]` is hidden for members, `[tqk_member]` is fetched from admin-ajax with the
  real login cookie and never stored in the HTML (`name="x"` + filter `tqk_member_html_x` for PHP-built blocks). Verified in Chrome: guest HTML has no
  member text; member gets HIT + the block, admin-bar users bypass.
- `[tqk_live]` passes the post id (attribute `post`, default the current post) to sources added with `tqk_live_sources`, so TQK WP Counter's `counter_views|clicks|carts|likes` work in the shortcode.
- **AJAX / "load more" / infinite scroll on a cached URL:** a request with `X-Requested-With` (jQuery XHR) is now answered by WordPress, never from the page cache
  (a theme may answer it with a fragment), and a script's request (`Sec-Fetch-Dest` other than document / iframe / frame, e.g. fetch or XHR) is never STORED as the
  page. Navigations, link prefetch (`Sec-Purpose: prefetch`), the admin iframe warm-up and server-side warm-ups still store. admin-ajax.php, REST, `?wc-ajax=`
  and any unknown query string were already bypassed.
- **Precise category purge** (default ON, Rules & Purge): publishing, editing, moving or unpublishing a product refreshes only the archives of ITS terms and their parent
  terms (category, tag, attribute, brand...), plus pages that list the whole post type (shop, a home product block). Category archives no longer carry the blanket
  `list-product` tag, and `set_object_terms` queues both the terms a post left and the ones it joined (quick edit, bulk edit, imports). A side list of the same type on
  another archive (a "latest products" widget) refreshes when that page expires; switch the option off to purge every product listing again. Verified on demo-wp:
  new product in "clothing" -> only clothing + shop; child category -> parent refreshed; move clothing -> music -> both; draft edit -> nothing.
- Emptying the trash or deleting a draft no longer refreshes any page (moving to the trash already did; those posts are not public).
- **Sidebars and blocks are part of the scan:** in precise mode only the MAIN query of a category archive is replaced by its term tag. Any other list of the same type on the page
  (a "latest products" / "recent posts" widget, a block) keeps the page tagged `list-<type>`, so such a page is refreshed whenever that type changes. Verified: with a
  latest-products list on every archive, a new product refreshes every archive; with none, only its own category. On the demo site the recent-posts widget now makes the blog
  category pages refresh when a post is published (they were stale before).
- **Browser tests for the Turbo script:** `JQUERY=/path/jquery.min.js node tests/js/run.js` (26 checks in real headless Chrome against a fake wp-admin: ON/OFF parsing incl. the
  empty string that `wp_localize_script` prints for false, click take-over, no reload, pushState, Back, script replay, excluded / foreign links, HUD injection, another tab
  switching Turbo off). Putting the old `!== false` logic back makes 4 of them fail.
- Page tags now also record the post types of queries that skip `the_posts` (get_posts, `fields=ids`, WooCommerce product lists).
- Fixed: `purge_url("/?p=5")` removed every page in that folder, i.e. also the homepage on plain-permalink sites. Fixed: a stale tag-index line
  purged pages that no longer had the tag (the page's own meta.json is now checked).
- Disk object cache: files are `*.php` starting with `<?php exit; ?>` (+ `.htaccess`, `index.html`); `wp_cache_add()` is atomic.

### PRO licence, code structure, front-end (08 Oct 2026)
- **Real licence keys.** PRO is now a signed key (`TQK1.<payload>.<signature>`, Ed25519) checked offline: it names the customer, the site(s) (`example.com`, `*.agency.com`, `*`) and
  an optional expiry; editing any of it breaks the signature, a key cannot be invented without the author's secret key (kept on the author's computer, not in the plugin), and the old
  hard-coded `TQK-VIP-PRO` / format-only keys are refused. The stored `is_pro` flag is ignored; `Config::is_pro()` verifies the key on every request. Issue keys with `Desktop	qk-license\issue.php`.
  Like any PHP plugin it cannot stop somebody who edits the plugin's own code, only forged or re-used keys. 13 unit tests (tampered, wrong site, expired, wildcard, garbage).
- **Cloudflare** (Server tab): after TQK purges pages (or everything) the same URLs are purged at Cloudflare by WP-Cron, 30 per request, with a "Test connection" button. API token needs
  "Zone > Cache Purge" only; it is stored server-side and never printed back.
- **CDN rewrite** (Speed tab): images, CSS, JS, fonts under /wp-content and /wp-includes point to the CDN host inside src / href / srcset / poster attributes only (not inline scripts, JSON or
  links to pages), with an exclusion list.
- **Core Web Vitals helpers:** Google Fonts links get `display=swap` + a preconnect to fonts.gstatic.com; the likely LCP image (first of the first three that is not a small / "logo"
  image, and only if WordPress has not already marked one) gets `fetchpriority="high"` and is never lazy-loaded.
- **Code structure:** `Dashboard.php` (1,540 lines) is now 160 lines plus one view file per tab in `src/Admin/Views/` (the rendered page is byte-identical after normalising nonces and
  timestamps); the 31 AJAX handlers of `Plugin.php` moved into one trait per feature in `src/Core/Ajax/` (same method names, same hooks). `Plugin.php` is 175 lines.

### Security audit (08 Oct 2026)
- **CRITICAL (fixed):** version 1.0 saved `wp-config.php.tqk-backup` next to wp-config.php when it added WP_CACHE. A web server serves that as plain text: anybody could download
  the database password and the salts (reproduced on the demo site: HTTP 200 with DB_PASSWORD). The backup is now `wp-config.tqk-backup.php`, mode 0600, whose first statement is
  `exit` (an empty page over HTTP); an old `.tqk-backup` is converted and deleted automatically (every admin page). **After updating, change the database password and the salts of any
  site that ran 1.0**, because the file may already have been fetched.
- Trash manager: "delete selected / empty trash" only deletes posts whose status is `trash` (and of the requested type); an id of a published post in a forged request is ignored.
- Image optimiser: the file of an attachment is used only when it really lies inside the uploads folder (a tampered `_wp_attached_file` cannot point it at another file); TinyPNG
  results are downloaded only from https://*.tinify.com.
- Turbo HUD: the page key (from a URL) is inserted as text, never as HTML (DOM XSS through a crafted wp-admin link); Turbo only navigates within the site's own origin.
- Reviewed and found sound: all 31 AJAX actions check a nonce and `manage_options` (PRO ones also the licence); every query is prepared or uses fixed text; settings are sanitised by type;
  `is_own_url()` guards every server-side request; the generated drop-ins use `var_export` and carry no user text.

### Privacy / security
- Password-protected posts, commenter name+e-mail pre-filled in the comment form, WooCommerce carts, `?unapproved=` previews and any
  response with `Set-Cookie` / `no-store` could be cached and shown to other visitors. Fixed in both the writer (Engine) and the reader (drop-in).
- Logged-in pages are never cached (the `cache_logged_in` option is gone).
- Cache keys: http/https are separate, variants are files (no folder collisions), unsafe paths and unknown query strings are not cached,
  the Host header must belong to the site, warm-up / purge only accept URLs of this site, PRO endpoints are enforced server-side.
- `wp-config.php` edits are atomic and backed up (`wp-config.php.tqk-backup`); an existing `advanced-cache.php` of another plugin is no longer overwritten.

### Breakage fixed
- HTML minify + Delay JS together produced a JavaScript syntax error, so delayed scripts never ran. Minify no longer touches script/style/pre/textarea.
- Delay JS no longer converts `type="module"`, JSON, importmap, speculationrules or `<noscript>` content, and replays DOMContentLoaded/load.
- Combine CSS/JS only merges neighbouring tags, keeps `media`, skips `@import` / `"use strict"`, rewrites `url()` safely; a regex bug that produced EMPTY bundles is fixed (with a size sanity check).
- Instant Page no longer prefetches add-to-cart / logout / nonce links (touch included).
- "Save settings" no longer switches PRO off, unchecked boxes really turn options off, custom JS/CSS is stored untouched.
- Comment approval, term changes, WooCommerce stock changes, options/widgets and theme changes now purge; saving a draft does not.

### Performance
- **Cache stampede protection**: after a purge only ONE request per page (max 4 site wide) rebuilds it; concurrent visitors get the stale copy. Purge-all is a single marker file (O(1)). Measured: 20 concurrent requests after a purge went from 5 full renders to 1.
- Warm-up crawler paces itself by the cost of the previous page and the server load; cache statistics are cached for a minute instead of walking the directory on every admin request.
- Tag index instead of scanning every `meta.json` (purge cost no longer grows with the number of cached pages); purge queue flushed once per request.
- ETag / 304, real cache lifespan, daily cleanup of expired pages / old bundles, atomic writes.

### Features completed (were advertised but missing)
- Mobile cache, bulk image optimisation + upload optimisation + restore originals + WebP serving (Apache/LiteSpeed), real SQL object cache with measured statistics.

### TQK Object Cache (new, PRO)
- Built-in persistent object cache with APCu / Redis / Memcached / disk back-ends and a survey that MEASURES each one (plus the database's own query latency) before recommending. Install / remove from the Object Cache tab; never overwrites another plugin's drop-in.
- Namespace by database + table prefix (not by file path) so WP-CLI, cron and the web server share one cache - found by testing: with a path-based namespace they served each other stale options.
- Honest result: on a local-MySQL box it removes ~76% of the queries per page (400 -> 97) but does not make pages faster; the survey says so.

### Admin screen
- `admin.js` was loaded twice with two competing `tqkCacheData` objects (handlers bound twice, translated texts lost). Now one loader (`Admin\Assets`).

### Removed / corrected claims
- "< 2 ms TTFB", "100 mobile score", fake SQL statistics (random numbers), fake Redis/WooCommerce toggles, dead buttons, unused SQLite file, fake "no updates" reply.
