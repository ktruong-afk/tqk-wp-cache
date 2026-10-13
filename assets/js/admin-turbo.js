/**
 * TQK WP Cache - WP-Admin Benchmark & Turbo Engine
 * 1. Measures and logs page load times across WP-Admin (Normal full reload vs Turbo SPA).
 * 2. Compares speed difference between Traditional reload and Turbo SPA.
 * 3. Shows live stopwatch HUD counter with comparison metrics and session history.
 */
(function($) {
    'use strict';

    function getTurboConfig() {
        return window.tqkAdminTurboConfig || { enabled: true, show_hud: true, exclusions: [] };
    }

    // wp_localize_script() turns PHP true into "1" and PHP false into "" (an EMPTY STRING, not false), so "off" has to include "".
    function isOn(v) {
        return v === true || v === 1 || v === '1' || v === 'true';
    }

    function checkTurboEnabled() {
        var cfg = getTurboConfig();
        return isOn(cfg.enabled);
    }

    function checkHudEnabled() {
        var cfg = getTurboConfig();
        return isOn(cfg.show_hud);
    }

    function getExclusionList() {
        var cfg = getTurboConfig();
        return (cfg.exclusions || []).map(function(item) {
            return item.trim().toLowerCase();
        }).filter(Boolean);
    }

    // Settings saved in another tab: take them over without a reload (the page only knows the value it was rendered with).
    window.addEventListener('storage', function(e) {
        if (e.key !== 'tqk_admin_turbo_cfg' || !e.newValue) return;
        try {
            var c = JSON.parse(e.newValue);
            window.tqkAdminTurboConfig = $.extend(getTurboConfig(), { enabled: c.enabled, show_hud: c.show_hud, exclusions: c.exclusions || [] });
        } catch (err) {}
    });

    var STORAGE_KEY = 'tqk_admin_speed_benchmarks';
    var isNavigating = false;
    var loadedStyleHrefs = {};
    var loadedScriptSrcs = {};
    var timerInterval = null;
    var timerStart = 0;

    // Helper: read and save benchmarks in localStorage
    function getStoredLogs() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : {};
        } catch(e) {
            return {};
        }
    }

    function saveBenchmark(pageKey, timeMs, mode) {
        try {
            var logs = getStoredLogs();
            if (!logs[pageKey]) logs[pageKey] = {};
            logs[pageKey][mode] = timeMs;
            logs[pageKey].lastUpdated = new Date().toLocaleTimeString();
            localStorage.setItem(STORAGE_KEY, JSON.stringify(logs));
        } catch(e) {}
    }

    function getPageKey(url) {
        if (!url) url = window.location.href;
        try {
            var u = new URL(url, window.location.origin);
            return u.pathname.replace(/\/wp-admin\/?/i, '') + u.search;
        } catch(e) {
            return url.split('/wp-admin/')[1] || url;
        }
    }

    function cleanAssetUrl(url) {
        if (!url) return '';
        return url.split('?')[0].toLowerCase();
    }

    function isUrlExcluded(url) {
        if (!url) return true;
        var lowerUrl = url.toLowerCase();

        if (lowerUrl.indexOf('javascript:') === 0 || lowerUrl.indexOf('#') === 0) return true;
        if (lowerUrl.indexOf('/wp-admin/') === -1) return true;
        if (lowerUrl.indexOf('action=logout') !== -1 || lowerUrl.indexOf('action=delete') !== -1) return true;

        var exclusions = getExclusionList();
        for (var i = 0; i < exclusions.length; i++) {
            if (exclusions[i] && lowerUrl.indexOf(exclusions[i]) !== -1) {
                return true;
            }
        }
        return false;
    }

    function getHudElement() {
        var $hud = $('#tqk-turbo-hud');
        if (!$hud.length) {
            $hud = $('<div id="tqk-turbo-hud" style="position:fixed; bottom:24px; right:24px; z-index:9999999; background:#0f172a; color:#fff; padding:12px 18px; border-radius:12px; font-family:-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; font-size:13px; box-shadow:0 12px 30px rgba(0,0,0,0.35); border:1px solid #334155; display:flex; flex-direction:column; gap:6px; min-width:240px; transition:all 0.25s ease;"></div>').appendTo('body');
        }
        return $hud;
    }

    function startLiveCounter(targetPage) {
        if (!checkHudEnabled()) return;
        var $hud = getHudElement();
        timerStart = performance.now();
        $hud.css({ opacity: '1', transform: 'translateY(0)', display: 'flex' });
        $hud.html(
            '<div style="display:flex; align-items:center; gap:8px;">' +
            '  <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:#eab308; box-shadow:0 0 8px #eab308;"></span>' +
            '  <strong data-tqk-hud-title style="color:#f8fafc; font-size:12.5px;"></strong>' +
            '</div>' +
            '<div style="display:flex; justify-content:space-between; align-items:center; margin-top:2px;">' +
            '  <span style="color:#94a3b8; font-size:12px;">Thời gian tải:</span>' +
            '  <strong id="tqk-hud-ms" style="color:#38bdf8; font-family:monospace; font-size:16px;">0 ms</strong>' +
            '</div>'
        );

        // The page key comes from a URL: put it in as TEXT, never as HTML (a crafted ?page=<img onerror=...> link must stay inert).
        $hud.find('[data-tqk-hud-title]').text(targetPage || 'Đang tải…');

        clearInterval(timerInterval);
        timerInterval = setInterval(function() {
            var elapsed = Math.round(performance.now() - timerStart);
            $('#tqk-hud-ms').text(elapsed + ' ms');
        }, 15);
    }

    function renderComparisonHud(pageKey, currentMs, currentMode) {
        try {
            drawComparisonHud(pageKey, currentMs, currentMode);
        } catch (err) {
            if (window.console) { console.error('[TQK Turbo] HUD error:', err); }
        }
    }

    function drawComparisonHud(pageKey, currentMs, currentMode) {
        clearInterval(timerInterval);
        saveBenchmark(pageKey, currentMs, currentMode);
        if (!checkHudEnabled()) return;

        var logs = getStoredLogs();
        var pageLogs = logs[pageKey] || {};

        var normalMs = pageLogs.normal || null;
        var turboMs = pageLogs.turbo || null;

        var compareHtml = '';
        if (normalMs && turboMs) {
            var diff = normalMs - turboMs;
            var percent = Math.round((diff / normalMs) * 100);
            if (percent > 0) {
                compareHtml = '<div style="margin-top:6px; padding-top:6px; border-top:1px dashed #334155; font-size:11.5px; color:#4ade80;">🚀 Turbo nhanh hơn <strong>' + percent + '%</strong> (Tiết kiệm ' + diff + 'ms)</div>';
            } else if (percent < 0) {
                compareHtml = '<div style="margin-top:6px; padding-top:6px; border-top:1px dashed #334155; font-size:11.5px; color:#94a3b8;">Chênh lệch: ' + Math.abs(diff) + 'ms</div>';
            }
        } else if (currentMode === 'turbo' && !normalMs) {
            compareHtml = '<div style="margin-top:6px; padding-top:6px; border-top:1px dashed #334155; font-size:11px; color:#94a3b8;">💡 Tắt Turbo để đo thời gian tải thường và so sánh.</div>';
        } else if (currentMode === 'normal' && !turboMs) {
            compareHtml = '<div style="margin-top:6px; padding-top:6px; border-top:1px dashed #334155; font-size:11px; color:#94a3b8;">💡 Bật Turbo để đo thời gian SPA và so sánh.</div>';
        }

        var modeLabel = (currentMode === 'turbo') 
            ? '<span style="color:#4ade80; font-weight:700;">SPA Turbo</span>' 
            : '<span style="color:#f59e0b; font-weight:700;">Tải thường (No Turbo)</span>';

        var normalText = normalMs ? (normalMs + ' ms') : '--';
        var turboText = turboMs ? (turboMs + ' ms') : '--';

        var $hud = getHudElement();
        $hud.css({ opacity: '1', transform: 'translateY(0)', display: 'flex' });
        $hud.html(
            '<div style="display:flex; justify-content:space-between; align-items:center;">' +
            '  <span style="font-size:12px; color:#94a3b8;">Kết quả: ' + modeLabel + '</span>' +
            '  <span style="font-size:11px; color:#64748b; cursor:pointer;" onclick="jQuery(\'#tqk-turbo-hud\').fadeOut(150);">&times;</span>' +
            '</div>' +
            '<div style="display:flex; justify-content:space-between; align-items:baseline; margin-top:2px;">' +
            '  <span style="font-size:12.5px; color:#f8fafc; font-weight:600;">Lần này:</span>' +
            '  <strong style="color:#38bdf8; font-family:monospace; font-size:18px;">' + currentMs + ' ms</strong>' +
            '</div>' +
            '<div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; margin-top:6px; padding:6px 8px; background:#1e293b; border-radius:6px; font-size:11px;">' +
            '  <div>Thường: <strong style="color:#f59e0b;">' + normalText + '</strong></div>' +
            '  <div>Turbo: <strong style="color:#4ade80;">' + turboText + '</strong></div>' +
            '</div>' +
            compareHtml
        );

        // Auto hide after 4 seconds
        setTimeout(function() {
            $hud.css({ opacity: '0', transform: 'translateY(10px)' });
            setTimeout(function() { $hud.hide(); }, 300);
        }, 4000);
    }

    function showProgressBar() {
        var $bar = $('#tqk-turbo-bar');
        if (!$bar.length) {
            $bar = $('<div id="tqk-turbo-bar" style="position:fixed; top:0; left:0; width:0%; height:3px; background:linear-gradient(90deg, #3b82f6, #10b981); z-index:999999; transition:width 0.2s ease, opacity 0.3s ease;"></div>').appendTo('body');
        }
        $bar.css({ width: '25%', opacity: 1 });
        setTimeout(function() { $bar.css({ width: '65%' }); }, 150);
    }

    function finishProgressBar() {
        var $bar = $('#tqk-turbo-bar');
        if ($bar.length) {
            $bar.css({ width: '100%' });
            setTimeout(function() {
                $bar.css({ opacity: 0 });
                setTimeout(function() { $bar.css({ width: '0%' }); }, 300);
            }, 180);
        }
    }

    function hashText(t) {
        var h = 5381;
        for (var i = 0; i < t.length; i++) { h = ((h << 5) + h + t.charCodeAt(i)) | 0; }
        return h + ':' + t.length;
    }

    // What the screen currently shown consists of. A script that belongs to the NEW screen but not to this one has to run again
    // even if it was loaded earlier (inline-edit-post.js binds Quick Edit to the table of THAT screen when it runs: visit Posts, go
    // elsewhere, come back, and without a second run Quick Edit is dead).
    var screenScripts = {};
    var screenInline = {};

    function snapshotScreen() {
        screenScripts = {};
        screenInline = {};
        $('script[src]').each(function() { var c = cleanAssetUrl($(this).attr('src')); if (c) { screenScripts[c] = true; } });
        $('script:not([src])').each(function() { screenInline[hashText(this.textContent || '')] = true; });
    }

    /**
     * The scripts the NEW screen needs that the CURRENT screen does not have, in document order:
     *  - external scripts (loaded for the first time, or run again when they belong to the new screen only);
     *  - inline scripts (data such as `pagenow`, "-js-extra" localisations...) that are new or whose text differs.
     * Scripts inside #wpbody-content are handled separately (they run last).
     */
    function collectDelta(doc, newBodyContent) {
        var list = [], externals = 0, nextExternal = {}, nextInline = {}, queued = {};
        doc.querySelectorAll('script').forEach(function(sc) {
            if (sc.getAttribute('src')) {
                var clean = cleanAssetUrl(sc.getAttribute('src'));
                if (!clean) { return; }
                nextExternal[clean] = true;
                if (newBodyContent.contains(sc) || screenScripts[clean] || queued[clean]) { return; }
                queued[clean] = true;
                list.push({ src: sc.src, clean: clean });
                externals++;
            } else if ((sc.textContent || '').trim()) {
                var key = hashText(sc.textContent);
                nextInline[key] = true;
                if (newBodyContent.contains(sc) || screenInline[key] || queued[key]) { return; }
                queued[key] = true;
                list.push({ text: sc.textContent });
            }
        });
        return { list: list, externals: externals, nextExternal: nextExternal, nextInline: nextInline };
    }

    /** Run the delta one script at a time (external scripts keep their order); any failure = the caller falls back to a normal load. */
    function replayScripts(list, done, failed) {
        var i = 0;
        (function next() {
            if (i >= list.length) { return done(); }
            var item = list[i++], el = document.createElement('script');
            if (item.src) {
                loadedScriptSrcs[item.clean] = true;
                el.async = false;
                el.onload = next;
                el.onerror = failed;
                el.src = item.src;
                document.body.appendChild(el);
            } else {
                try { el.textContent = item.text; document.body.appendChild(el); } catch (e) { return failed(e); }
                next();
            }
        })();
    }

    var MAX_NEW_SCRIPTS = 12;   // a screen that needs more than this is too different: a normal load is the better choice

    // Pages fetched on hover. A fetched page is reused by the click that follows (within 15 s); otherwise the prefetch would only
    // double the server work.
    var prefetched = {};
    var PREFETCH_MAX = 6;
    var PREFETCH_TTL = 15000;

    function requestPage(url) {
        var hit = prefetched[url];
        if (hit && (Date.now() - hit.t) < PREFETCH_TTL) {
            return hit.xhr;
        }
        return $.ajax({ url: url, type: 'GET', dataType: 'html', headers: { 'X-TQK-Turbo': '1' } });
    }

    function indexExistingAssets() {
        $('link[rel="stylesheet"]').each(function() {
            var href = $(this).attr('href');
            if (href) loadedStyleHrefs[cleanAssetUrl(href)] = true;
        });
        $('script[src]').each(function() {
            var src = $(this).attr('src');
            if (src) loadedScriptSrcs[cleanAssetUrl(src)] = true;
        });
    }

    /**
     * SPA Turbo Navigation
     */
    function navigateTo(url, pushHistory) {
        if (isNavigating) return;
        // Only this site's own admin: another origin (or a malformed address) gets a normal browser navigation.
        try {
            if (new URL(url, window.location.href).origin !== window.location.origin) {
                window.location.href = url;
                return;
            }
        } catch (e) {
            return;
        }
        isNavigating = true;

        var pageKey = getPageKey(url);
        startLiveCounter(pageKey);
        showProgressBar();
        $('#wpbody-content').css({ opacity: '0.45', transition: 'opacity 0.15s ease' });

        var tStart = performance.now();

        var fallback = function() {
            // A normal load. Remember when the click happened so the HUD can still measure it.
            try { sessionStorage.setItem('tqk_pending_click_time', JSON.stringify({ url: url, time: Date.now() })); } catch (e) {}
            window.location.href = url;
        };

        var replaying = false;
        requestPage(url).done(function(html) {
            try {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');

                var newBodyContent = doc.querySelector('#wpbody-content');
                if (!newBodyContent) {
                    return fallback();
                }

                // wp-admin pages bring their JavaScript in the page footer (outside #wpbody-content). Work out what this screen is missing.
                var delta = collectDelta(doc, newBodyContent);
                if (delta.externals > MAX_NEW_SCRIPTS) {
                    return fallback();
                }

                if (doc.title) {
                    document.title = doc.title;
                }

                var newLinks = doc.querySelectorAll('link[rel="stylesheet"]');
                var headEl = document.head || document.getElementsByTagName('head')[0];
                newLinks.forEach(function(link) {
                    var href = link.getAttribute('href');
                    var clean = cleanAssetUrl(href);
                    if (clean && !loadedStyleHrefs[clean]) {
                        loadedStyleHrefs[clean] = true;
                        headEl.appendChild(link.cloneNode(true));
                    }
                });

                // The body classes style the whole screen (".edit-php", ".folded"...) and a few globals say which screen this is.
                if (doc.body && doc.body.className) {
                    document.body.className = doc.body.className.replace(/\bno-js\b/, '') + (document.body.classList.contains('js') ? ' js' : '');
                }
                ['pagenow', 'typenow', 'adminpage'].forEach(function(name) {
                    var m = html.match(new RegExp('\\b' + name + '\\s*=\\s*[\'"]([^\'"]*)[\'"]'));
                    if (m) { window[name] = m[1]; }
                });

                var newMenu = doc.querySelector('#adminmenu');
                if (newMenu) {
                    $('#adminmenu').html(newMenu.innerHTML);
                }

                var wpBodyContent = document.getElementById('wpbody-content');
                wpBodyContent.innerHTML = newBodyContent.innerHTML;

                var finish = function() {
                    screenScripts = delta.nextExternal;
                    screenInline = delta.nextInline;
                    try {
                        // Scripts that sit INSIDE the content area do not run when inserted through innerHTML. They run last, like in a
                        // normal page where the footer libraries are already there when `jQuery(document).ready(...)` fires.
                        newBodyContent.querySelectorAll('script').forEach(function(sc) {
                            if (sc.src || !sc.textContent) { return; }
                            var inline = document.createElement('script');
                            inline.textContent = sc.textContent;
                            document.body.appendChild(inline);
                        });

                        if (pushHistory) {
                            history.pushState({ tqk_turbo: true, url: url }, '', url);
                        }
                        window.scrollTo(0, 0);
                        $(document).trigger('wp-window-resized');
                        $(document).trigger('tqk-turbo-loaded', [url]);
                    } catch (err) {
                        if (window.console) { console.error('[TQK Turbo] Error finishing SPA transition:', err); }
                        return fallback();
                    }
                    finishProgressBar();
                    renderComparisonHud(pageKey, Math.round(performance.now() - tStart), 'turbo');
                    $('#wpbody-content').css({ opacity: '1' });
                    isNavigating = false;
                };

                // isNavigating stays true until the replay is over (the .always() below must not reopen it too early).
                replaying = true;
                var guard = setTimeout(fallback, 12000);   // a script that never answers must not freeze the screen
                replayScripts(delta.list, function() { clearTimeout(guard); finish(); }, function() {
                    clearTimeout(guard);
                    if (window.console) { console.error('[TQK Turbo] A script of the next screen failed to load, doing a normal load.'); }
                    fallback();
                });
            } catch (err) {
                if (window.console) { console.error('[TQK Turbo] Error processing SPA transition:', err); }
                return fallback();
            }
        }).fail(function() {
            fallback();
        }).always(function() {
            if (!replaying) {   // normal end of a failed / fallback request; the replay path finishes in finish()
                $('#wpbody-content').css({ opacity: '1' });
                isNavigating = false;
            }
        });
    }

    $(document).ready(function() {
        indexExistingAssets();
        snapshotScreen();

        // 1. Check if we just arrived via Traditional Full Page Reload
        var pendingClick = null;
        try {
            var rawPending = sessionStorage.getItem('tqk_pending_click_time');
            if (rawPending) {
                pendingClick = JSON.parse(rawPending);
                sessionStorage.removeItem('tqk_pending_click_time');
            }
        } catch(e) {}

        // Only a click that really led HERE counts (a stale marker from a click that went nowhere would invent a bogus time).
        if (pendingClick && pendingClick.url && getPageKey(pendingClick.url) !== getPageKey(window.location.href)) {
            pendingClick = null;
        }

        if (pendingClick && pendingClick.time) {
            var elapsedMs = Math.round(performance.now() + (Date.now() - pendingClick.time));
            // Or calculate via navigation timing API
            if (window.performance && window.performance.timing) {
                var t = window.performance.timing;
                if (t.loadEventEnd && t.navigationStart) {
                    elapsedMs = t.loadEventEnd - t.navigationStart;
                }
            }
            if (elapsedMs > 0 && elapsedMs < 20000) {
                var curPageKey = getPageKey(window.location.href);
                renderComparisonHud(curPageKey, elapsedMs, 'normal');
            }
        }

        // Prefetch on hover (Instant Navigation when Turbo is active): only after the pointer RESTS on a link for 150 ms, at most
        // PREFETCH_MAX pages, and the answer is kept so the click can use it.
        var hoverTimer = null;
        var prefetchCount = 0;
        $(document).on('mouseenter', '#adminmenu a', function() {
            if (!checkTurboEnabled() || prefetchCount >= PREFETCH_MAX) return;
            var href = this.href;   // the property is the ABSOLUTE url; the attribute is relative ("tools.php") and never contains /wp-admin/
            if (!href || isUrlExcluded(href) || prefetched[href]) return;
            clearTimeout(hoverTimer);
            hoverTimer = setTimeout(function() {
                if (prefetched[href] || isNavigating) return;
                prefetchCount++;
                prefetched[href] = { t: Date.now(), xhr: $.ajax({ url: href, type: 'GET', dataType: 'html', headers: { 'X-TQK-Turbo': '1' } }) };
            }, 150);
        });
        $(document).on('mouseleave', '#adminmenu a', function() { clearTimeout(hoverTimer); });

        // Intercept clicks on admin menu and admin content links
        $(document).on('click', '#adminmenu a, #wpadminbar a, .wrap a.tqk-turbo-link', function(e) {
            if (e.which > 1 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

            var $link = $(this);
            var target = $link.attr('target');
            if (target && target !== '_self') return;

            var rawHref = $link.attr('href');
            if (!rawHref || rawHref === '#' || rawHref.indexOf('javascript:') === 0) return;
            // Menu links are RELATIVE ("tools.php"). Judging the raw attribute made isUrlExcluded() reject every single click (no "/wp-admin/"
            // in it), so Turbo never ran and every page was measured as a normal load. Use the absolute url.
            var href = this.href;

            var turboActive = checkTurboEnabled();

            // If Turbo is DISABLED or URL excluded, record click timestamp so the next page knows how long it took to reload!
            if (!turboActive || isUrlExcluded(href)) {
                try {
                    sessionStorage.setItem('tqk_pending_click_time', JSON.stringify({
                        url: href,
                        time: Date.now()
                    }));
                } catch(err) {}
                return; // Let native full browser reload happen
            }

            // Turbo is ENABLED: intercept and handle via SPA
            e.preventDefault();
            navigateTo(href, true);
        });

        // Handle Browser Back / Forward buttons
        window.addEventListener('popstate', function(e) {
            if (!checkTurboEnabled()) return;
            if (e.state && e.state.tqk_turbo) {
                navigateTo(window.location.href, false);
            } else if (window.location.href.indexOf('/wp-admin/') !== -1 && !isUrlExcluded(window.location.href)) {
                navigateTo(window.location.href, false);
            }
        });
    });

    // Test hook: a test page sets window.__TQK_TURBO_TEST__ = true before loading this file and gets the pure helpers back. Nothing changes otherwise.
    if (window.__TQK_TURBO_TEST__) {
        window.__TQK_TURBO_TEST__ = { isOn: isOn, checkTurboEnabled: checkTurboEnabled, checkHudEnabled: checkHudEnabled, isUrlExcluded: isUrlExcluded, getPageKey: getPageKey, cleanAssetUrl: cleanAssetUrl, hashText: hashText };
    }
})(jQuery);
