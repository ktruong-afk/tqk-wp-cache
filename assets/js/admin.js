(function($) {
    'use strict';

    $(document).ready(function() {
        // --- 1. Toast Notification Helper (matches TQK WP SEO toasts) ---
        var lastToastMsg = '';
        var lastToastTime = 0;

        function showToast(message, isError) {
            if (!message) return;
            var now = Date.now();
            if (message === lastToastMsg && (now - lastToastTime) < 1200) {
                return; // Suppress duplicate identical toast
            }
            lastToastMsg = message;
            lastToastTime = now;

            var $container = $('#tqk-wpseo-toasts');
            if (!$container.length) {
                $container = $('<div id="tqk-wpseo-toasts"></div>').appendTo('body');
            }

            // Remove existing toasts of the same text or clear queue
            $container.find('.tqk-wpseo-toast').remove();

            var $toast = $('<div class="tqk-wpseo-toast' + (isError ? ' is-error' : '') + '">' + message + '</div>');
            $container.append($toast);

            setTimeout(function() {
                $toast.addClass('is-out');
                setTimeout(function() {
                    $toast.remove();
                }, 400);
            }, 3000);
        }

        // --- 2. Copy Helper (for donate card) ---
        $(document).on('click', '.tqk-copy', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var text = $btn.data('copy') || '';
            if (!text) return;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    var orig = $btn.text();
                    $btn.text('Đã copy!');
                    setTimeout(function() { $btn.text(orig); }, 1500);
                });
            } else {
                var $tmp = $('<input>').val(text).appendTo('body').select();
                document.execCommand('copy');
                $tmp.remove();
                var orig = $btn.text();
                $btn.text('Đã copy!');
                setTimeout(function() { $btn.text(orig); }, 1500);
            }
        });

        // --- Language Switcher (Instant VI | EN Toggle) ---
        $(document).on('click', '.tqk-lang-btn:not(.is-active)', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var lang = $btn.data('lang');
            $('.tqk-lang-btn').removeClass('is-active');
            $btn.addClass('is-active');

            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: {
                    action: 'tqk_cache_switch_lang',
                    lang: lang,
                    nonce: tqkCacheData.nonce
                },
                success: function(res) {
                    if (res.success) {
                        showToast(res.data.message || 'Language updated', false);
                        setTimeout(function() {
                            window.location.reload();
                        }, 400);
                    } else {
                        window.location.reload();
                    }
                },
                error: function() {
                    window.location.reload();
                }
            });
        });

        // Shared with add-ons (TQK WP Cache PRO loads its own script and reuses these)
        window.tqkCache = { showToast: showToast, getLogTime: getLogTime, switchTab: switchTab, saveSettings: saveSettings };

        // --- 3. SPA Tab Switching (No Page Reload) ---
        function switchTab(tabId) {
            if (!tabId || !$('#' + tabId).length) return;

            $('.tqk-tab-nav').removeClass('active');
            $('.tqk-tab-nav[data-tab="' + tabId + '"]').addClass('active');

            $('.tqk-tab-pane').removeClass('active');
            $('#' + tabId).addClass('active');

            if (history.pushState) {
                history.pushState(null, null, '#' + tabId);
            } else {
                location.hash = '#' + tabId;
            }

            // On-demand data loading for switched tab
            if (tabId === 'tab-database') {
                loadRevisionsList();
                loadJunkStats();
            } else if (tabId === 'tab-trash') {
                loadTrashSummary();
            }
            // Tabs of the PRO add-on load their data on this event (see the add-on's pro.js)
            $(document).trigger('tqk:tab', [tabId]);

            // Focus & smoothly scroll to top of content container if scrolled down
            var $content = $('.tqk-vertical-content');
            if ($content.length) {
                var contentTop = $content.offset().top - 32;
                if ($(window).scrollTop() > contentTop) {
                    $('html, body').animate({ scrollTop: contentTop }, 200);
                }
            }
        }

        $(document).on('click', '.tqk-tab-nav', function(e) {
            e.preventDefault();
            var tabId = $(this).data('tab');
            switchTab(tabId);
        });

        // Restore tab from URL hash on load
        var currentHash = window.location.hash ? window.location.hash.substring(1) : '';
        if (currentHash && $('#' + currentHash).length) {
            switchTab(currentHash);
        }

        // --- 4. AJAX Save Settings (No Page Reload) ---
        var isApplyingPreset = false;
        var autoSaveDebounceTimer = null;

        $('#tqk-ajax-form').on('submit', function(e) {
            e.preventDefault();
            saveSettings();
        });

        function saveSettings(callback, silentToast) {
            var $btn = $('#tqk-save-btn');
            var originalHtml = $btn.html();
            var $status = $('#tqk-save-status');

            $btn.prop('disabled', true).find('span').text(tqkCacheData.saving_msg || 'Đang lưu…');
            $status.text('').removeClass('is-error');

            var formData = $('#tqk-ajax-form').serializeArray();
            var dataObj = {
                action: 'tqk_cache_save_settings',
                nonce: tqkCacheData.nonce
            };

            $.each(formData, function(i, field) {
                dataObj[field.name] = field.value;
            });

            // Handle unchecked checkboxes
            $('#tqk-ajax-form input[type="checkbox"]').each(function() {
                var name = $(this).attr('name');
                if (name && !$(this).is(':checked')) {
                    dataObj[name] = 0;
                }
            });

            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: dataObj,
                success: function(res) {
                    if (res.success) {
                        if (!silentToast) {
                            showToast(res.data.message || tqkCacheData.saved_msg, false);
                        }
                        $status.text('Đã lưu lúc ' + new Date().toLocaleTimeString()).removeClass('is-error');

                        // The Turbo / HUD switches apply at once: this page and every other open wp-admin tab (they only read the
                        // setting when they were loaded, so without this the old value keeps working until the next full reload).
                        var $turbo = $('#field_admin_turbo');
                        if ($turbo.length) {
                            var liveCfg = {
                                enabled: $turbo.is(':checked'),
                                show_hud: $('#field_admin_benchmark_hud').is(':checked'),
                                exclusions: ($('#field_admin_turbo_exclusions').val() || '').split(/\r?\n/).map(function(s) { return s.trim(); }).filter(Boolean)
                            };
                            window.tqkAdminTurboConfig = $.extend(window.tqkAdminTurboConfig || {}, liveCfg);
                            try { localStorage.setItem('tqk_admin_turbo_cfg', JSON.stringify($.extend({ t: Date.now() }, liveCfg))); } catch (err) {}
                        }

                        // Update drop-in indicator
                        if (res.data.dropin_active !== undefined) {
                            var $statusEl = $('#stat-dropin-status');
                            var $chip = $statusEl.closest('.tqk-wpseo-hero-chip');
                            if (res.data.dropin_active) {
                                $chip.removeClass('is-bad');
                                $statusEl.text(tqkCacheData.active_msg || 'Active');
                            } else {
                                $chip.addClass('is-bad');
                                $statusEl.text(tqkCacheData.off_msg || 'Off');
                            }
                        }

                        if (typeof callback === 'function') {
                            callback(true);
                        }
                    } else {
                        showToast(res.data.message || 'Lỗi khi lưu cài đặt', true);
                        $status.text(res.data.message || 'Lỗi lưu').addClass('is-error');
                        if (typeof callback === 'function') {
                            callback(false);
                        }
                    }
                },
                error: function() {
                    showToast('Không thể kết nối đến máy chủ.', true);
                    if (typeof callback === 'function') {
                        callback(false);
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        }

        // Auto-save immediately when clicking any toggle switch or checkbox
        $(document).on('change', '#tqk-ajax-form input[type="checkbox"]', function(e) {
            // Ignore select-all or non-form checkboxes
            var id = $(this).attr('id') || '';
            if (id.indexOf('select-all') !== -1 || isApplyingPreset) {
                return;
            }

            // Custom mode applies if user toggles a switch directly
            setActivePresetUI('custom');

            // Quick debounced save with discrete status
            clearTimeout(autoSaveDebounceTimer);
            autoSaveDebounceTimer = setTimeout(function() {
                saveSettings(function() {
                    showToast('Đã lưu cấu hình tự động!', false);
                }, true);
            }, 300);
        });

        // Dedicated inline Save button for inputs / textareas
        $(document).on('click', '.tqk-inline-save-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var origHtml = $btn.html();
            $btn.prop('disabled', true).html('<span>Đang lưu...</span>');

            saveSettings(function(success) {
                $btn.prop('disabled', false);
                if (success) {
                    $btn.addClass('is-saved').html('<span>✓ Đã lưu</span>');
                    setTimeout(function() {
                        $btn.removeClass('is-saved').html(origHtml);
                    }, 2000);
                } else {
                    $btn.html(origHtml);
                }
            });
        });

        // --- 5. One-Click Quick Presets ---
        function setActivePresetUI(preset) {
            $('#field_active_preset').val(preset);

            if (preset === 'custom') {
                $('.tqk-preset-card').removeClass('is-active');
                $('.tqk-preset-badge').addClass('is-hidden');
                $('.apply-preset-btn').removeClass('tqk-btn-primary is-current').addClass('tqk-btn-outline').text('Áp dụng ngay');
                $('#tqk-preset-custom-notice').removeClass('is-hidden');
            } else {
                $('#tqk-preset-custom-notice').addClass('is-hidden');
                $('.tqk-preset-card').each(function() {
                    var $c = $(this);
                    var p = $c.data('preset');
                    var $badge = $c.find('.tqk-preset-badge');
                    var $btn = $c.find('.apply-preset-btn');

                    if (p === preset) {
                        $c.addClass('is-active');
                        $badge.removeClass('is-hidden');
                        $btn.removeClass('tqk-btn-outline').addClass('tqk-btn-primary is-current').text('✓ Đang áp dụng');
                    } else {
                        $c.removeClass('is-active');
                        $badge.addClass('is-hidden');
                        $btn.removeClass('tqk-btn-primary is-current').addClass('tqk-btn-outline').text('Áp dụng ngay');
                    }
                });
            }
        }

        $(document).on('click', '.apply-preset-btn', function(e) {
            e.preventDefault();
            var $card = $(this).closest('.tqk-preset-card');
            var preset = $card.data('preset');
            var presetTitle = $card.find('strong').text();

            isApplyingPreset = true;

            if (preset === 'safe') {
                $('#field_page_cache').prop('checked', true);
                $('#field_gzip_compression').prop('checked', true);
                $('#field_combine_css').prop('checked', false);
                $('#field_combine_js').prop('checked', false);
                $('#field_lazy_load').prop('checked', true);
                $('#field_lazy_load_iframes').prop('checked', false);
                $('#field_delay_js').prop('checked', false);
                $('#field_instant_page').prop('checked', false);
                $('#field_html_minify').prop('checked', false);
            } else if (preset === 'balanced') {
                $('#field_page_cache').prop('checked', true);
                $('#field_gzip_compression').prop('checked', true);
                $('#field_combine_css').prop('checked', true);
                $('#field_combine_js').prop('checked', true);
                $('#field_lazy_load').prop('checked', true);
                $('#field_lazy_load_iframes').prop('checked', true);
                $('#field_instant_page').prop('checked', true);
                $('#field_html_minify').prop('checked', true);
                $('#field_delay_js').prop('checked', false);
            } else if (preset === 'ludicrous') {
                $('#field_page_cache').prop('checked', true);
                $('#field_gzip_compression').prop('checked', true);
                $('#field_combine_css').prop('checked', true);
                $('#field_combine_js').prop('checked', true);
                $('#field_lazy_load').prop('checked', true);
                $('#field_lazy_load_iframes').prop('checked', true);
                $('#field_instant_page').prop('checked', true);
                $('#field_html_minify').prop('checked', true);
                $('#field_delay_js').prop('checked', true);
                $('#field_purge_on_post_update').prop('checked', true);
                $('#field_purge_frontpage').prop('checked', true);
            }

            setActivePresetUI(preset);

            saveSettings(function(success) {
                isApplyingPreset = false;
                if (success) {
                    showToast('Đã kích hoạt ' + presetTitle, false);
                }
            }, true);
        });

        // Detect manual alterations to text inputs or textareas
        $('#tqk-ajax-form').on('input change', 'input[type="text"], input[type="number"], textarea', function() {
            if (!isApplyingPreset && $('#field_active_preset').val() !== 'custom') {
                setActivePresetUI('custom');
            }
        });

        // --- 6. Purge All Cache via AJAX (Real-time update) ---
        $(document).on('click', '.tqk-purge-all-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var originalHtml = $btn.html();

            $btn.prop('disabled', true);

            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: {
                    action: 'tqk_cache_purge_all',
                    nonce: tqkCacheData.nonce
                },
                success: function(res) {
                    if (res.success) {
                        showToast(res.data.message || 'Đã dọn sạch cache!', false);
                        if (res.data.stats) {
                            $('#stat-cache-size').text(res.data.stats.size_formatted || '0 B');
                            $('#stat-cache-files').text(res.data.stats.files || 0);
                        }
                    } else {
                        showToast(res.data.message || 'Lỗi khi xóa cache', true);
                    }
                },
                error: function() {
                    showToast('Lỗi kết nối máy chủ khi xóa cache.', true);
                },
                complete: function() {
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        });

        // --- 7. Refresh Live Stats via AJAX ---
        $(document).on('click', '.tqk-refresh-stats-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            $btn.prop('disabled', true);

            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: {
                    action: 'tqk_cache_get_stats',
                    nonce: tqkCacheData.nonce
                },
                success: function(res) {
                    if (res.success && res.data.stats) {
                        $('#stat-cache-size').text(res.data.stats.size_formatted);
                        $('#stat-cache-files').text(res.data.stats.files);
                        showToast('Đã làm mới số liệu thống kê!', false);
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        });

        // --- 8. Purge Current Page from Admin Bar ---
        $(document).on('click', '.tqk-purge-current-btn', function(e) {
            e.preventDefault();

            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: {
                    action: 'tqk_cache_purge_current',
                    url: tqkCacheData.current_url || window.location.href,
                    nonce: tqkCacheData.nonce
                },
                success: function(res) {
                    if (res.success) {
                        showToast(res.data.message, false);
                        setTimeout(function() { window.location.reload(); }, 600);
                    } else {
                        showToast(res.data.message || 'Lỗi khi xóa cache trang', true);
                    }
                },
                error: function() {
                    showToast('Không thể kết nối đến máy chủ.', true);
                }
            });
        });

        // --- 9. Processing Bar & Activity Tracker (Client-Side Iframe Crawler Warmup) ---
        function getLogTime() {
            var d = new Date();
            var pad = function(n) { return n < 10 ? '0' + n : n; };
            return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        }

        function appendLog(msg, type) {
            type = type || 'info';
            var $body = $('#tqk-console-body');
            if (!$body.length) return;

            var $line = $('<div class="tqk-log-line is-' + type + '"><span class="log-time">[' + getLogTime() + ']</span> <span>' + msg + '</span></div>');
            $body.append($line);
            $body.scrollTop($body[0].scrollHeight);
        }

        function updateProgress(curr, total) {
            var pct = total > 0 ? Math.round((curr / total) * 100) : 0;
            $('#tqk-progress-bar').css('width', pct + '%');
            $('#tqk-progress-count').text(curr);
            $('#tqk-progress-total').text(total);
            $('#tqk-progress-percent').text(pct + '%');
        }

        $(document).on('click', '.tqk-console-clear-btn', function(e) {
            e.preventDefault();
            $('#tqk-console-body').empty().append('<div class="tqk-log-line is-info"><span class="log-time">[' + getLogTime() + ']</span> Nhật ký đã được làm sạch.</div>');
        });

        // The limit only matters for the "not cached yet" mode.
        $(document).on('change', '#tqk-rebuild-mode', function() {
            $('#tqk-rebuild-limit-wrap').toggle($(this).val() === 'missing');
        });

        // Client-Side Crawler via Hidden Iframe (Zero CPU load on server, 100% Guest isolation)
        $(document).on('click', '.tqk-start-warmup-btn', function(e) {
            e.preventDefault();

            switchTab('tab-dashboard');

            var $allWarmupBtns = $('.tqk-start-warmup-btn');
            $allWarmupBtns.prop('disabled', true);

            $('#tqk-progress-status').text('Khởi động Client-side Crawler...');
            updateProgress(0, 0);

            appendLog('[BẮT ĐẦU] Khởi động trình thu thập thông tin Client-Side (Trình duyệt tự tải qua Sandbox Iframe, không tốn tài nguyên cURL máy chủ)...', 'info');

            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: {
                    action: 'tqk_cache_preload_start',
                    mode: $('#tqk-rebuild-mode').val() || 'essential',
                    limit: $('#tqk-rebuild-limit').val() || 200,
                    nonce: tqkCacheData.nonce
                },
                success: function(res) {
                    if (!res.success || !res.data || !res.data.items) {
                        appendLog('[LỖI] Không thể lấy danh sách hàng đợi trang.', 'error');
                        $allWarmupBtns.prop('disabled', false);
                        return;
                    }

                    var items = res.data.items;
                    var total = items.length;
                    if (res.data.mode === 'lazy' || total === 0) {
                        var doneMsg = res.data.mode === 'lazy' ? tqkCacheData.rb_lazy_done : tqkCacheData.rb_missing_none;
                        updateProgress(0, 0);
                        $('#tqk-progress-status').text(doneMsg);
                        appendLog(doneMsg, 'success');
                        showToast(doneMsg, false);
                        $allWarmupBtns.prop('disabled', false);
                        return;
                    }
                    appendLog('[' + (res.data.mode || '') + '] ' + String(tqkCacheData.rb_queue).replace('%s', total), 'info');
                    updateProgress(0, total);

                    var index = 0;
                    var $iframe = $('#tqk-crawler-frame');
                    if (!$iframe.length) {
                        $iframe = $('<iframe id="tqk-crawler-frame" src="about:blank" style="position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;opacity:0;border:none;"></iframe>').appendTo('body');
                    }

                    var pageStartedAt = 0;   // pacing: wait longer after slow pages / when the server reports a high load
                    function crawlNext() {
                        if (index >= total) {
                            updateProgress(total, total);
                            $('#tqk-progress-status').text('Hoàn tất 100%! Toàn bộ trang đã được tạo cache tĩnh.');
                            appendLog('[HOÀN TẤT 100%] Đã làm nóng toàn bộ cache trên đĩa bằng Client-Side Crawler!', 'success');
                            showToast('Đã hoàn tất làm nóng Cache qua Client-Side Crawler!', false);
                            $allWarmupBtns.prop('disabled', false);
                            return;
                        }

                        var item = items[index++];
                        $('#tqk-progress-status').text('Đang tải [' + index + '/' + total + ']: ' + item.title + '...');
                        appendLog('→ [' + index + '/' + total + '] Đang crawl: ' + item.title + ' (' + item.url + ')', 'info');

                        // Build warmup URL with guest isolation
                        var sep = item.url.indexOf('?') !== -1 ? '&' : '?';
                        var warmupUrl = item.url + sep + 'tqk_cache_guest=1&tqk_t=' + Date.now();

                        var hasHandled = false;
                        var timeoutTimer = setTimeout(function() {
                            if (!hasHandled) {
                                hasHandled = true;
                                onIframeLoaded(item, true);
                            }
                        }, 9000); // 9s timeout safety

                        $iframe.off('load').on('load', function() {
                            if (!hasHandled) {
                                hasHandled = true;
                                clearTimeout(timeoutTimer);
                                onIframeLoaded(item, false);
                            }
                        });

                        pageStartedAt = Date.now();
                        $iframe.attr('src', warmupUrl);
                    }

                    function onIframeLoaded(item, wasTimeout) {
                        // Verify that static cache was generated on disk
                        $.ajax({
                            url: tqkCacheData.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'tqk_cache_preload_verify',
                                url: item.url,
                                nonce: tqkCacheData.nonce
                            },
                            success: function(vRes) {
                                if (vRes.success && vRes.data) {
                                    if (vRes.data.is_cached) {
                                        appendLog('  ✓ Đã lưu file tĩnh: ' + item.title + ' (' + vRes.data.file_size + ')', 'success');
                                    } else {
                                        appendLog('  ~ Đã tải qua trình duyệt: ' + item.title + (wasTimeout ? ' (Timeout)' : ''), 'warn');
                                    }
                                    if (vRes.data.stats) {
                                        $('#stat-cache-size').text(vRes.data.stats.size_formatted);
                                        $('#stat-cache-files').text(vRes.data.stats.files);
                                    }
                                }
                            },
                            complete: function(xhr) {
                                updateProgress(index, total);
                                // The page just took N ms of server time: rest at least that long x1.5 (CPU duty cycle <= 40%), 3 s more when busy.
                                var took = Date.now() - pageStartedAt;
                                var busy = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.busy;
                                setTimeout(crawlNext, Math.max(400, Math.min(5000, took * 1.5)) + (busy ? 3000 : 0));
                            }
                        });
                    }

                    crawlNext();
                },
                error: function() {
                    appendLog('[LỖI] Lỗi kết nối máy chủ khi lấy hàng đợi.', 'error');
                    $allWarmupBtns.prop('disabled', false);
                }
            });
        });

        // =====================================================================
        // --- 10. Database & Revisions Analyzer Logic ---
        // =====================================================================
        function loadRevisionsList() {
            var $tbody = $('#tqk-revisions-table tbody');
            $tbody.html('<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--tqk-muted);">Đang phân tích cơ sở dữ liệu…</td></tr>');
            $('#tqk-clean-revisions-btn').prop('disabled', true);
            $('#tqk-revisions-select-all').prop('checked', false);

            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: {
                    action: 'tqk_cache_db_get_revisions',
                    nonce: tqkCacheData.nonce
                },
                success: function(res) {
                    if (!res.success || !res.data || !res.data.items || !res.data.items.length) {
                        $tbody.html('<tr><td colspan="6" style="text-align:center; padding:24px; color:var(--tqk-good); font-weight:600;">Tuyệt vời! Không có bài viết nào chứa bản sửa đổi (revisions) thừa.</td></tr>');
                        return;
                    }

                    var html = '';
                    $.each(res.data.items, function(i, item) {
                        var badgeClass = item.revision_count > 15 ? 'is-high' : '';
                        html += '<tr>';
                        html += '<td><input type="checkbox" class="tqk-rev-item-cb" value="' + item.id + '" data-count="' + item.revision_count + '"></td>';
                        html += '<td><strong>' + item.title + '</strong>';
                        if (item.edit_url) {
                            html += ' <a href="' + item.edit_url + '" target="_blank" style="font-size:11px; margin-left:6px; color:var(--tqk-primary);">[Sửa]</a>';
                        }
                        html += '</td>';
                        html += '<td><code>' + item.type + '</code></td>';
                        html += '<td style="text-align:center;"><span class="tqk-badge-count ' + badgeClass + '">' + item.revision_count + ' bản</span></td>';
                        html += '<td style="text-align:center;"><input type="number" class="tqk-rev-keep-input" value="3" min="0" max="100" style="width:65px; text-align:center; padding:4px 6px; font-size:12px; border:1px solid var(--tqk-border); border-radius:4px;" title="Số bản sửa đổi mới nhất muốn giữ lại"></td>';
                        html += '<td style="text-align:right;">' + item.size_formatted + '</td>';
                        html += '<td style="font-size:12px; color:var(--tqk-muted);">' + item.modified + '</td>';
                        html += '</tr>';
                    });

                    $tbody.html(html);
                },
                error: function() {
                    $tbody.html('<tr><td colspan="7" style="text-align:center; padding:20px; color:var(--tqk-bad);">Lỗi khi phân tích revisions.</td></tr>');
                }
            });
        }

        function loadJunkStats() {
            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: {
                    action: 'tqk_cache_db_get_junk_stats',
                    nonce: tqkCacheData.nonce
                },
                success: function(res) {
                    if (res.success && res.data && res.data.stats) {
                        var s = res.data.stats;
                        $('#tqk-stat-transients').text(s.expired_transients);
                        $('#tqk-stat-autodrafts').text(s.auto_drafts);
                        $('#tqk-stat-orphanmeta').text(s.orphan_meta);
                        $('#tqk-stat-totalrevisions').text(s.total_revisions);
                    }
                }
            });
        }

        $(document).on('click', '#tqk-refresh-revisions-btn', function(e) {
            e.preventDefault();
            loadRevisionsList();
            loadJunkStats();
        });

        $(document).on('change', '#tqk-revisions-select-all', function() {
            var isChecked = $(this).is(':checked');
            $('.tqk-rev-item-cb').prop('checked', isChecked);
            toggleCleanRevisionsBtn();
        });

        $(document).on('change', '.tqk-rev-item-cb', function() {
            toggleCleanRevisionsBtn();
        });

        $(document).on('click', '#tqk-apply-all-keep-btn', function(e) {
            e.preventDefault();
            var presetKeep = parseInt($('#tqk-revisions-keep').val(), 10);
            if (isNaN(presetKeep) || presetKeep < 0) presetKeep = 3;

            var $checked = $('.tqk-rev-item-cb:checked');
            if (!$checked.length) {
                showToast('Vui lòng tích chọn ít nhất 1 bài viết trong danh sách bên dưới!', true);
                return;
            }

            $checked.each(function() {
                var $row = $(this).closest('tr');
                $row.find('.tqk-rev-keep-input').val(presetKeep);
            });

            showToast('Đã áp dụng giữ lại ' + presetKeep + ' bản cho ' + $checked.length + ' bài viết đã chọn.', false);
        });

        function toggleCleanRevisionsBtn() {
            var count = $('.tqk-rev-item-cb:checked').length;
            $('#tqk-clean-revisions-btn').prop('disabled', count === 0);
        }

        $(document).on('click', '#tqk-clean-revisions-btn', function(e) {
            e.preventDefault();
            var items = [];
            $('.tqk-rev-item-cb:checked').each(function() {
                var pid = parseInt($(this).val(), 10);
                var $row = $(this).closest('tr');
                var keepVal = parseInt($row.find('.tqk-rev-keep-input').val(), 10);
                if (isNaN(keepVal) || keepVal < 0) keepVal = 3;
                items.push({ id: pid, keep: keepVal });
            });

            if (!items.length) return;

            var confirmMsg = 'Bạn có chắc chắn muốn dọn dẹp revisions cho ' + items.length + ' bài viết đã chọn với thông số giữ lại riêng của từng bài?';
            if (!confirm(confirmMsg)) return;

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: {
                    action: 'tqk_cache_db_clean_revisions',
                    items: items,
                    nonce: tqkCacheData.nonce
                },
                success: function(res) {
                    if (res.success) {
                        showToast(res.data.message || 'Đã dọn dẹp revisions thành công!', false);
                        loadRevisionsList();
                        loadJunkStats();
                    } else {
                        showToast(res.data.message || 'Lỗi dọn revisions', true);
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    showToast('Lỗi kết nối khi dọn revisions.', true);
                    $btn.prop('disabled', false);
                }
            });
        });

        $(document).on('click', '#tqk-clean-junk-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            $btn.prop('disabled', true);

            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: {
                    action: 'tqk_cache_db_clean_junk',
                    nonce: tqkCacheData.nonce
                },
                success: function(res) {
                    if (res.success) {
                        showToast(res.data.message || 'Đã dọn sạch rác hệ thống!', false);
                        loadJunkStats();
                    } else {
                        showToast(res.data.message || 'Lỗi dọn rác', true);
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        });

        // =====================================================================
        // --- 11. Trash Manager Logic (WooCommerce Accelerated) ---
        // =====================================================================
        var activeTrashType = 'post';

        function loadTrashSummary() {
            var $grid = $('#tqk-trash-types-grid');
            $grid.html('<div style="padding:14px; color:var(--tqk-muted);">Đang tải thống kê thùng rác…</div>');

            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: {
                    action: 'tqk_cache_trash_get_summary',
                    nonce: tqkCacheData.nonce
                },
                success: function(res) {
                    if (!res.success || !res.data || !res.data.summary || !res.data.summary.length) {
                        $grid.html('<div style="padding:16px; color:var(--tqk-good); font-weight:600;">Thùng rác hoàn toàn trống rỗng! Không có bài viết hay sản phẩm nào bị bỏ rơi.</div>');
                        $('#tqk-trash-table tbody').html('<tr><td colspan="5" style="text-align:center; padding:24px; color:var(--tqk-good);">Không có mục nào trong thùng rác.</td></tr>');
                        return;
                    }

                    var html = '';
                    $.each(res.data.summary, function(i, item) {
                        var isActive = (item.post_type === activeTrashType) || (i === 0 && !activeTrashType);
                        if (isActive) activeTrashType = item.post_type;

                        html += '<div class="tqk-trash-type-card ' + (isActive ? 'is-active' : '') + '" data-type="' + item.post_type + '">';
                        html += '<strong>' + item.count + '</strong>';
                        html += '<span>' + item.label + '</span>';
                        html += '</div>';
                    });

                    $grid.html(html);
                    loadTrashItems(activeTrashType);
                }
            });
        }

        function loadTrashItems(postType) {
            activeTrashType = postType;
            $('#tqk-trash-active-type-title').text('Thùng rác: ' + postType.toUpperCase());
            var $tbody = $('#tqk-trash-table tbody');
            $tbody.html('<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--tqk-muted);">Đang tải danh sách…</td></tr>');
            $('#tqk-restore-trash-btn, #tqk-delete-trash-btn').prop('disabled', true);
            $('#tqk-trash-select-all').prop('checked', false);

            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: {
                    action: 'tqk_cache_trash_get_items',
                    post_type: postType,
                    nonce: tqkCacheData.nonce
                },
                success: function(res) {
                    if (!res.success || !res.data || !res.data.items || !res.data.items.length) {
                        $tbody.html('<tr><td colspan="5" style="text-align:center; padding:24px; color:var(--tqk-muted);">Không có bài nào trong loại ' + postType + '.</td></tr>');
                        return;
                    }

                    var html = '';
                    $.each(res.data.items, function(i, item) {
                        html += '<tr>';
                        html += '<td><input type="checkbox" class="tqk-trash-item-cb" value="' + item.id + '"></td>';
                        html += '<td><code>#' + item.id + '</code></td>';
                        html += '<td><strong>' + item.title + '</strong></td>';
                        html += '<td>' + item.author + '</td>';
                        html += '<td style="font-size:12px; color:var(--tqk-muted);">' + item.date + '</td>';
                        html += '</tr>';
                    });

                    $tbody.html(html);
                }
            });
        }

        $(document).on('click', '.tqk-trash-type-card', function() {
            $('.tqk-trash-type-card').removeClass('is-active');
            $(this).addClass('is-active');
            var type = $(this).data('type');
            loadTrashItems(type);
        });

        $(document).on('change', '#tqk-trash-select-all', function() {
            var isChecked = $(this).is(':checked');
            $('.tqk-trash-item-cb').prop('checked', isChecked);
            toggleTrashActionBtns();
        });

        $(document).on('change', '.tqk-trash-item-cb', function() {
            toggleTrashActionBtns();
        });

        function toggleTrashActionBtns() {
            var count = $('.tqk-trash-item-cb:checked').length;
            $('#tqk-restore-trash-btn, #tqk-delete-trash-btn').prop('disabled', count === 0);
        }

        $(document).on('click', '#tqk-restore-trash-btn', function(e) {
            e.preventDefault();
            var ids = [];
            $('.tqk-trash-item-cb:checked').each(function() { ids.push($(this).val()); });
            if (!ids.length) return;

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: {
                    action: 'tqk_cache_trash_restore_items',
                    post_ids: ids,
                    nonce: tqkCacheData.nonce
                },
                success: function(res) {
                    if (res.success) {
                        showToast(res.data.message || 'Đã phục hồi thành công!', false);
                        loadTrashSummary();
                    }
                }
            });
        });

        $(document).on('click', '#tqk-delete-trash-btn', function(e) {
            e.preventDefault();
            var ids = [];
            $('.tqk-trash-item-cb:checked').each(function() { ids.push($(this).val()); });
            if (!ids.length) return;

            if (!confirm('Bạn có chắc chắn muốn xóa vĩnh viễn ' + ids.length + ' mục đã chọn? Thao tác này KHÔNG thể khôi phục!')) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.ajax({
                url: tqkCacheData.ajax_url,
                type: 'POST',
                data: {
                    action: 'tqk_cache_trash_delete_items',
                    post_ids: ids,
                    post_type: activeTrashType,
                    nonce: tqkCacheData.nonce
                },
                success: function(res) {
                    if (res.success) {
                        showToast(res.data.message || 'Đã xóa vĩnh viễn thành công!', false);
                        loadTrashSummary();
                    }
                }
            });
        });

        $(document).on('click', '#tqk-empty-type-trash-btn', function(e) {
            e.preventDefault();
            if (!confirm('Bạn có chắc chắn muốn XÓA SẠCH TOÀN BỘ rác của loại ' + activeTrashType.toUpperCase() + '?')) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);

            function emptyBatch() {
                $.ajax({
                    url: tqkCacheData.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'tqk_cache_trash_empty_type',
                        post_type: activeTrashType,
                        nonce: tqkCacheData.nonce
                    },
                    success: function(res) {
                        if (res.success && res.data) {
                            if (res.data.remaining > 0) {
                                showToast('Đang xử lý dọn rác... Còn ' + res.data.remaining + ' mục', false);
                                setTimeout(emptyBatch, 200);
                            } else {
                                showToast('Đã dọn sạch toàn bộ thùng rác loại ' + activeTrashType, false);
                                loadTrashSummary();
                                $btn.prop('disabled', false);
                            }
                        }
                    },
                    error: function() {
                        showToast('Lỗi khi dọn thùng rác', true);
                        $btn.prop('disabled', false);
                    }
                });
            }

            emptyBatch();
        });

        // --- Pro Modal Event Handlers ---
        $(document).on('click', '.tqk-open-pro-modal-btn', function(e) {
            e.preventDefault();
            $('#tqk-pro-modal').removeClass('is-hidden');
            $('#tqk-pro-license-key-input').focus();
        });

        $(document).on('click', '#tqk-pro-modal-close-btn, #tqk-pro-modal-done-btn', function(e) {
            e.preventDefault();
            $('#tqk-pro-modal').addClass('is-hidden');
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && !$('#tqk-pro-modal').hasClass('is-hidden')) {
                $('#tqk-pro-modal').addClass('is-hidden');
            }
        });

        // --- Support report: collected by the server (secrets removed), saved as a file in the browser ---
        $(document).on('click', '#tqk-diag-btn', function(e) {
            e.preventDefault();
            var $btn = $(this).prop('disabled', true);
            var $msg = $('#tqk-diag-msg').text(tqkCacheData.diag_working);
            $.post(tqkCacheData.ajax_url, { action: 'tqk_cache_diagnostics', nonce: tqkCacheData.nonce }).done(function(res) {
                if (!res || !res.success) { $msg.text(tqkCacheData.diag_failed); return; }
                var blob = new Blob([JSON.stringify(res.data.report, null, 2)], { type: 'application/json' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = res.data.filename || 'tqk-report.json';
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
                setTimeout(function() { URL.revokeObjectURL(a.href); }, 2000);
                $msg.text(tqkCacheData.diag_done);
            }).fail(function() { $msg.text(tqkCacheData.diag_failed); }).always(function() { $btn.prop('disabled', false); });
        });

        // --- Report a problem: the message (and, if ticked, the report) is e-mailed to support by the server ---
        $(document).on('click', '#tqk-sup-send', function(e) {
            e.preventDefault();
            var $btn = $(this).prop('disabled', true);
            var $msg = $('#tqk-sup-msg').removeClass('is-error').text(tqkCacheData.sup_sending);
            $.post(tqkCacheData.ajax_url, {
                action: 'tqk_cache_support_send', nonce: tqkCacheData.nonce,
                name: $('#tqk-sup-name').val(), email: $('#tqk-sup-email').val(), message: $('#tqk-sup-message').val(),
                attach: $('#tqk-sup-attach').is(':checked') ? 1 : 0
            }).done(function(res) {
                var ok = !!(res && res.success);
                $msg.toggleClass('is-error', !ok).text(res && res.data && res.data.message ? res.data.message : tqkCacheData.sup_failed);
                if (ok) { $('#tqk-sup-message').val(''); }
            }).fail(function() { $msg.addClass('is-error').text(tqkCacheData.sup_failed); }).always(function() { $btn.prop('disabled', false); });
        });

        // --- Cloudflare: test the connection with what is typed (empty token field = the saved token) ---
        $(document).on('click', '#tqk-cf-test-btn', function(e) {
            e.preventDefault();
            var $msg = $('#tqk-cf-test-msg'), $btn = $(this);
            $btn.prop('disabled', true);
            $msg.css('color', 'var(--tqk-muted)').text('…');
            $.post(tqkCacheData.ajax_url, { action: 'tqk_cache_cf_test', nonce: tqkCacheData.nonce, cf_zone: $('#field_cf_zone').val(), cf_token: $('#field_cf_token').val() })
                .done(function(res) { $msg.css('color', res.success ? 'var(--tqk-good)' : 'var(--tqk-bad)').text(res.data && res.data.message ? res.data.message : ''); })
                .fail(function() { $msg.css('color', 'var(--tqk-bad)').text('Request failed.'); })
                .always(function() { $btn.prop('disabled', false); });
        });

        // --- Hook Tab Switch Listeners for On-Demand Data Loading ---
        $(document).on('click', '.tqk-tab-nav[data-tab="tab-database"]', function() {
            loadRevisionsList();
            loadJunkStats();
        });

        $(document).on('click', '.tqk-tab-nav[data-tab="tab-trash"]', function() {
            loadTrashSummary();
        });

    });
})(jQuery);

