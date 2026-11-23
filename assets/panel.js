(function () {
    'use strict';

    var root = document.documentElement;
    var themeKey = 'studio-panel-theme';
    var navKey = 'studio-panel-nav';
    var cookieKey = 'studio_panel_theme';
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var reducedMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)').matches : false;
    var palette = null;
    var paletteItems = [];
    var paletteIndex = 0;
    var lastFocus = null;

    function readCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.*+?^${}()|[\]\\])/g, '\\$1') + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function writeCookie(name, value) {
        document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=31536000; SameSite=Lax';
    }

    function readJson(key) {
        try {
            return JSON.parse(localStorage.getItem(key) || '{}') || {};
        } catch (error) {
            return {};
        }
    }

    function writeJson(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (error) {
            // Storage can be unavailable; collapsing still works for this page.
        }
    }

    function currentTheme() {
        var stored = '';

        try {
            stored = localStorage.getItem(themeKey) || '';
        } catch (error) {
            stored = '';
        }

        stored = stored || readCookie(cookieKey);

        if (stored === 'dark' || stored === 'light') {
            return stored;
        }

        return root.getAttribute('data-sp-theme') === 'dark' ? 'dark' : 'light';
    }

    function applyTheme(theme, persist) {
        root.setAttribute('data-sp-theme', theme);
        root.style.colorScheme = theme;
        document.body.classList.toggle('sp-theme-dark', theme === 'dark');

        document.querySelectorAll('[data-sp-theme-toggle]').forEach(function (button) {
            var isDark = theme === 'dark';
            var label = isDark ? '切换到浅色模式' : '切换到深色模式';
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
            button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        });

        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute('content', theme === 'dark' ? '#080d1a' : '#0b1120');
        }

        if (persist) {
            try {
                localStorage.setItem(themeKey, theme);
            } catch (error) {
                // localStorage may be disabled; the cookie is the fallback.
            }
            writeCookie(cookieKey, theme);
        }
    }

    function toggleTheme() {
        var next = currentTheme() === 'dark' ? 'light' : 'dark';

        if (document.startViewTransition && !reducedMotion) {
            document.startViewTransition(function () {
                applyTheme(next, true);
            });
        } else {
            applyTheme(next, true);
        }

        showToast('dark' === next ? '已切换到深色模式' : '已切换到浅色模式', 'info');
    }

    function closeMenu() {
        document.body.classList.remove('sp-nav-open');
        var toggle = document.querySelector('[data-sp-menu-toggle]');
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        }
    }

    var icons = {
        search: '<svg class="sp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
        pen: '<svg class="sp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
        sparkle: '<svg class="sp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 1.7 5.3L19 10l-5.3 1.7L12 17l-1.7-5.3L5 10l5.3-1.7Z"/><path d="M19 16.5 19.6 18l1.5.6-1.5.6-.6 1.5-.6-1.5-1.5-.6 1.5-.6Z"/></svg>',
        moon: '<svg class="sp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.4 14.6A8.6 8.6 0 0 1 9.4 3.6 8.6 8.6 0 1 0 20.4 14.6Z"/></svg>',
        sun: '<svg class="sp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
        chevrons: '<svg class="sp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 9 5-5 5 5"/><path d="m7 15 5 5 5-5"/></svg>'
    };

    function siteTitle() {
        var parts = (document.title || '').split(' - ');

        if (parts.length >= 3) {
            return parts[1].trim() || 'Studio';
        }

        return (parts[0] || 'Studio').trim();
    }

    function navHref(fragment, fallback) {
        var link = document.querySelector('.typecho-head-nav a[href*="' + fragment + '"]');
        return link ? link.href : fallback;
    }

    function wrapPageTitle() {
        document.querySelectorAll('.typecho-page-title').forEach(function (title) {
            if (title.querySelector('.sp-page-title')) {
                return;
            }

            var heading = title.querySelector('h2');

            if (!heading) {
                return;
            }

            var now = new Date();
            var date = now.getFullYear() + '.'
                + String(now.getMonth() + 1).padStart(2, '0') + '.'
                + String(now.getDate()).padStart(2, '0');
            var wrap = document.createElement('div');
            wrap.className = 'sp-page-title';
            wrap.innerHTML = '<p class="sp-eyebrow"><span class="sp-eyebrow-dot" aria-hidden="true"></span>Workspace<span class="sp-eyebrow-line" aria-hidden="true"></span>' + date + '</p>';
            title.insertBefore(wrap, heading);
            wrap.appendChild(heading);
        });
    }

    /**
     * Some hosts disable output buffering or a third-party admin extension
     * renders its own header. In that case, rebuild the chrome from the
     * original Typecho header without touching the page content.
     */
    function ensureShellFallback() {
        if (document.querySelector('.sp-sidebar')) {
            return;
        }

        var header = document.querySelector('.typecho-head-nav');

        if (!header) {
            return;
        }

        var nav = header.querySelector('nav');

        if (!nav) {
            return;
        }

        var title = siteTitle();
        var initial = title.charAt(0).toUpperCase() || 'A';
        var adminUrl = nav.querySelector('a') ? nav.querySelector('a').href : '/admin/';
        var author = header.querySelector('.operate .author');
        var logout = header.querySelector('.operate .exit');
        var site = header.querySelector('.operate a:last-child');
        var userName = author ? author.textContent.trim() : 'Account';
        var userInitial = userName.charAt(0).toUpperCase() || 'A';
        var today = new Date();
        var dateText = today.getFullYear() + '.' + String(today.getMonth() + 1).padStart(2, '0') + '.' + String(today.getDate()).padStart(2, '0');
        var weekdays = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
        var searchUrl = navHref('manage-posts.php', '/admin/manage-posts.php');
        var writeUrl = navHref('write-post.php', '/admin/write-post.php');

        var aside = document.createElement('aside');
        aside.className = 'sp-sidebar';
        aside.setAttribute('aria-label', '后台导航');
        aside.innerHTML = ''
            + '<a class="sp-brand"><span class="sp-brand-logo" aria-hidden="true"></span><span class="sp-brand-text"><strong></strong><small>MoePanel</small></span></a>'
            + '<button type="button" class="sp-menu-btn" data-sp-menu-toggle aria-expanded="false" aria-controls="sp-nav"><span class="sp-menu-lines" aria-hidden="true"></span><span class="sr-only">菜单</span></button>'
            + '<div class="sp-nav-scroll"><div class="sp-nav-label"><span>Workspace</span><button type="button" class="sp-nav-collapse-all" data-sp-nav-collapse aria-label="收起全部分组" title="收起全部分组">' + icons.chevrons + '</button></div><div class="sp-nav" id="sp-nav"><div class="sp-nav-core"></div></div></div>'
            + '<div class="sp-sidebar-footer"><a class="sp-user"><span class="sp-user-avatar" aria-hidden="true"></span><span class="sp-user-info"><strong></strong><small>已登录</small></span></a><div class="sp-sidebar-actions"><a class="sp-site-link">查看站点 ↗</a><a class="sp-logout-link">登出</a></div></div>';

        aside.querySelector('.sp-brand').href = adminUrl;
        aside.querySelector('.sp-brand-logo').textContent = initial;
        aside.querySelector('.sp-brand-text strong').textContent = title;
        aside.querySelector('.sp-nav-core').appendChild(nav);
        aside.querySelector('.sp-user').href = author ? author.href : adminUrl;
        aside.querySelector('.sp-user-avatar').textContent = userInitial;
        aside.querySelector('.sp-user-info strong').textContent = userName;
        aside.querySelector('.sp-site-link').href = site ? site.href : '/';
        aside.querySelector('.sp-logout-link').href = logout ? logout.href : '/';

        var topbar = document.createElement('div');
        topbar.className = 'sp-topbar';
        topbar.innerHTML = ''
            + '<div class="sp-crumbs"><span class="sp-crumb-dot" aria-hidden="true"></span><span class="sp-crumb-title"></span><span class="sp-crumb-sep">/</span><span class="sp-crumb-current">MoePanel</span></div>'
            + '<form class="sp-search" role="search"><span class="sp-search-icon" aria-hidden="true">' + icons.search + '</span><input type="search" name="keywords" placeholder="搜索文章…" aria-label="搜索文章"><button type="button" class="sp-kbd" data-sp-command-open aria-haspopup="dialog" aria-expanded="false" aria-label="打开命令面板">⌘K</button></form>'
            + '<div class="sp-top-actions"><span class="sp-top-date">' + dateText + ' · ' + weekdays[today.getDay()] + '</span><button type="button" class="sp-command-trigger" data-sp-theme-menu-open aria-haspopup="menu" aria-expanded="false" aria-label="主题选择" title="主题选择">' + icons.sparkle + '</button><button type="button" class="sp-theme-toggle" data-sp-theme-toggle aria-label="切换深色模式" title="切换深色模式"><span class="sp-theme-icon sp-theme-icon--moon" aria-hidden="true">' + icons.moon + '</span><span class="sp-theme-icon sp-theme-icon--sun" aria-hidden="true">' + icons.sun + '</span></button><a class="sp-write-btn">' + icons.pen + '<span>写文章</span></a></div>';

        topbar.querySelector('.sp-crumb-title').textContent = title;
        topbar.querySelector('.sp-search').action = searchUrl;
        topbar.querySelector('.sp-write-btn').href = writeUrl;

        document.body.insertBefore(topbar, document.body.firstChild);
        document.body.insertBefore(aside, topbar);
        document.body.classList.add('sp-admin');
        header.remove();
        wrapPageTitle();
    }

    function setupNavGroups() {
        var groups = [];
        var saved = readJson(navKey);
        var collapseAll = document.querySelector('[data-sp-nav-collapse]');
        var nodes = Array.prototype.slice.call(document.querySelectorAll('.sp-nav-core nav > menu > li'));

        nodes.forEach(function (li, index) {
            if (li.classList.contains('operate')) {
                return;
            }

            var link = li.querySelector(':scope > a');
            var menu = li.querySelector(':scope > menu');

            if (!link || !menu) {
                return;
            }

            var title = (link.textContent || '').replace(/\s+/g, ' ').trim();
            var key = index + ':' + title;
            var id = 'sp-nav-group-' + index;
            var button = document.createElement('button');

            button.type = 'button';
            button.className = 'sp-nav-group-toggle';
            button.setAttribute('aria-controls', id);
            button.innerHTML = '<span class="sp-nav-group-title"></span><span class="sp-nav-count"></span><span class="sp-nav-chevron" aria-hidden="true"></span>';
            button.querySelector('.sp-nav-group-title').textContent = title;
            button.querySelector('.sp-nav-count').textContent = String(menu.querySelectorAll(':scope > li').length);

            link.replaceWith(button);
            menu.id = id;
            li.classList.add('sp-nav-group');

            groups.push({
                key: key,
                li: li,
                menu: menu,
                button: button,
                collapsed: saved[key] === true
            });
        });

        function measureGroup(group) {
            if (group.menu.scrollHeight > 0) {
                group.menu.style.setProperty('--sp-group-height', group.menu.scrollHeight + 'px');
            } else {
                group.menu.style.removeProperty('--sp-group-height');
            }
        }

        function setGroup(group, collapsed) {
            measureGroup(group);
            group.li.classList.toggle('is-collapsed', collapsed);
            group.button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            group.menu.setAttribute('aria-hidden', collapsed ? 'true' : 'false');
            group.menu.inert = collapsed;
        }

        function syncState() {
            var state = {};
            groups.forEach(function (group) {
                state[group.key] = group.li.classList.contains('is-collapsed');
            });
            writeJson(navKey, state);
        }

        function updateCollapseAll() {
            if (!collapseAll) {
                return;
            }

            var allCollapsed = groups.length > 0 && groups.every(function (group) {
                return group.li.classList.contains('is-collapsed');
            });

            collapseAll.classList.toggle('is-all-collapsed', allCollapsed);
            collapseAll.setAttribute('aria-label', allCollapsed ? '展开全部分组' : '收起全部分组');
            collapseAll.setAttribute('title', allCollapsed ? '展开全部分组' : '收起全部分组');
        }

        groups.forEach(function (group) {
            setGroup(group, group.collapsed);

            group.button.addEventListener('click', function () {
                var collapsed = !group.li.classList.contains('is-collapsed');
                setGroup(group, collapsed);
                syncState();
                updateCollapseAll();
            });
        });

        if (collapseAll) {
            collapseAll.addEventListener('click', function () {
                var shouldCollapse = groups.some(function (group) {
                    return !group.li.classList.contains('is-collapsed');
                });

                groups.forEach(function (group) {
                    setGroup(group, shouldCollapse);
                });
                syncState();
                updateCollapseAll();
            });
        }

        updateCollapseAll();

        window.addEventListener('resize', function () {
            groups.forEach(measureGroup);
        });
    }

    function collectCommands() {
        var seen = {};
        var commands = [];

        document.querySelectorAll('.sp-nav-core a, .sp-sidebar-actions a, .sp-write-btn').forEach(function (link) {
            var href = link.getAttribute('href');
            var label = (link.textContent || '').replace(/\s+/g, ' ').trim();

            if (!href || href === '#' || href.indexOf('javascript:') === 0 || !label || seen[href]) {
                return;
            }

            var group = link.closest('.sp-nav-group');
            var groupTitle = group ? group.querySelector('.sp-nav-group-title') : null;
            var meta = groupTitle ? groupTitle.textContent.trim() : (label.indexOf('站点') >= 0 ? 'Link' : 'Action');

            seen[href] = true;
            commands.push({
                label: label,
                href: link.href,
                meta: meta
            });
        });

        commands.unshift({
            label: currentTheme() === 'dark' ? '切换到浅色模式' : '切换到深色模式',
            action: 'theme',
            meta: 'Appearance'
        });

        return commands;
    }

    function ensurePalette() {
        if (palette) {
            return palette;
        }

        palette = document.createElement('div');
        palette.className = 'sp-cmd-overlay';
        palette.hidden = true;
        palette.innerHTML = ''
            + '<div class="sp-cmd" role="dialog" aria-modal="true" aria-label="命令面板">'
            + '  <div class="sp-cmd-field">'
            + '    <svg class="sp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>'
            + '    <input type="text" placeholder="搜索页面或操作…" autocomplete="off" spellcheck="false" aria-label="搜索页面或操作">'
            + '    <button type="button" class="sp-kbd" data-sp-command-close>ESC</button>'
            + '  </div>'
            + '  <ul class="sp-cmd-list" role="listbox" aria-label="命令列表"></ul>'
            + '  <div class="sp-cmd-empty" hidden>没有匹配的结果</div>'
            + '  <div class="sp-cmd-foot"><span>↑↓ 选择</span><span>↵ 打开</span><span>ESC 关闭</span></div>'
            + '</div>';

        document.body.appendChild(palette);

        var input = palette.querySelector('input');
        var list = palette.querySelector('.sp-cmd-list');

        input.addEventListener('input', function () {
            renderPalette(input.value);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                movePalette(1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                movePalette(-1);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                runPaletteItem();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closePalette();
            }
        });

        list.addEventListener('click', function (event) {
            var item = event.target.closest('.sp-cmd-item');
            if (item) {
                runPaletteItem(Number(item.getAttribute('data-index')));
            }
        });

        list.addEventListener('mousemove', function (event) {
            var item = event.target.closest('.sp-cmd-item');
            if (item) {
                setPaletteIndex(Number(item.getAttribute('data-index')));
            }
        });

        palette.addEventListener('click', function (event) {
            if (event.target === palette || event.target.closest('[data-sp-command-close]')) {
                closePalette();
            }
        });

        return palette;
    }

    function openPalette() {
        ensurePalette();

        if (!palette.hidden) {
            palette.querySelector('input').focus();
            return;
        }

        lastFocus = document.activeElement;
        palette.hidden = false;
        document.body.classList.add('sp-cmd-open');
        document.querySelectorAll('[data-sp-command-open]').forEach(function (button) {
            button.setAttribute('aria-expanded', 'true');
        });
        palette.querySelector('input').value = '';
        renderPalette('');

        requestAnimationFrame(function () {
            palette.querySelector('input').focus();
        });
    }

    function closePalette() {
        if (!palette) {
            return;
        }

        palette.hidden = true;
        document.body.classList.remove('sp-cmd-open');
        document.querySelectorAll('[data-sp-command-open]').forEach(function (button) {
            button.setAttribute('aria-expanded', 'false');
        });

        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
    }

    function renderPalette(query) {
        var list = palette.querySelector('.sp-cmd-list');
        var empty = palette.querySelector('.sp-cmd-empty');
        var searchForm = document.querySelector('.sp-search');
        var searchAction = searchForm ? searchForm.getAttribute('action') : '';
        var needle = (query || '').trim().toLowerCase();

        paletteItems = collectCommands();

        if (needle && searchAction) {
            paletteItems.unshift({
                label: '搜索文章 “' + query.trim() + '”',
                action: 'search',
                meta: 'Search'
            });
        }

        if (needle) {
            paletteItems = paletteItems.filter(function (item) {
                return (item.label + ' ' + item.meta).toLowerCase().indexOf(needle) >= 0;
            });
        }

        list.innerHTML = '';
        paletteIndex = 0;

        paletteItems.forEach(function (item, index) {
            var row = document.createElement('li');
            row.className = 'sp-cmd-item';
            row.setAttribute('role', 'option');
            row.setAttribute('data-index', String(index));
            row.innerHTML = '<span class="sp-cmd-glyph" aria-hidden="true"></span><span class="sp-cmd-label"></span><span class="sp-cmd-meta"></span>';
            row.querySelector('.sp-cmd-glyph').textContent = item.action === 'theme' ? '◐' : (item.action === 'search' ? '⌕' : '↗');
            row.querySelector('.sp-cmd-label').textContent = item.label;
            row.querySelector('.sp-cmd-meta').textContent = item.meta;
            list.appendChild(row);
        });

        empty.hidden = paletteItems.length > 0;
        list.hidden = paletteItems.length === 0;

        if (paletteItems.length > 0) {
            setPaletteIndex(0);
        }
    }

    function setPaletteIndex(index) {
        if (!paletteItems.length) {
            return;
        }

        paletteIndex = Math.max(0, Math.min(index, paletteItems.length - 1));

        palette.querySelectorAll('.sp-cmd-item').forEach(function (row, rowIndex) {
            var active = rowIndex === paletteIndex;
            row.classList.toggle('is-active', active);
            row.setAttribute('aria-selected', active ? 'true' : 'false');

            if (active) {
                row.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    function movePalette(direction) {
        if (!paletteItems.length) {
            return;
        }

        var next = paletteIndex + direction;
        if (next < 0) {
            next = paletteItems.length - 1;
        }
        if (next >= paletteItems.length) {
            next = 0;
        }

        setPaletteIndex(next);
    }

    function runPaletteItem(index) {
        var item = paletteItems[typeof index === 'number' ? index : paletteIndex];

        if (!item) {
            return;
        }

        if (item.action === 'theme') {
            toggleTheme();
            closePalette();
            return;
        }

        if (item.action === 'search') {
            var form = document.querySelector('.sp-search');
            var query = palette.querySelector('input').value.trim();

            if (!form || !query) {
                closePalette();
                if (form) {
                    form.querySelector('input').focus();
                }
                return;
            }

            window.location.href = form.getAttribute('action') + '?keywords=' + encodeURIComponent(query);
            return;
        }

        if (item.href) {
            window.location.href = item.href;
        }
    }

    function animateCounters() {
        if (reducedMotion) {
            return;
        }

        document.querySelectorAll('[data-sp-count]').forEach(function (element) {
            var target = Number(element.getAttribute('data-sp-count'));

            if (!Number.isFinite(target) || target < 0) {
                return;
            }

            var start = null;
            var duration = 700;

            function frame(timestamp) {
                if (start === null) {
                    start = timestamp;
                }

                var progress = Math.min((timestamp - start) / duration, 1);
                var eased = 1 - Math.pow(1 - progress, 3);
                element.textContent = String(Math.round(target * eased));

                if (progress < 1) {
                    requestAnimationFrame(frame);
                }
            }

            element.textContent = '0';
            requestAnimationFrame(frame);
        });
    }

    document.addEventListener('click', function (event) {
        var oauthLink = event.target.closest('.sp-kook-oauth');

        if (oauthLink && !event.metaKey && !event.ctrlKey && !event.shiftKey && event.button === 0) {
            // Some admin scripts force external links to target="_blank"; the
            // OAuth callback needs to return in this tab so the new session is
            // visible immediately.
            event.preventDefault();
            window.location.assign(oauthLink.href);
            return;
        }

        var themeButton = event.target.closest('[data-sp-theme-toggle]');
        if (themeButton) {
            toggleTheme();
            return;
        }

        var menuButton = event.target.closest('[data-sp-menu-toggle]');
        if (menuButton) {
            var open = !document.body.classList.contains('sp-nav-open');
            document.body.classList.toggle('sp-nav-open', open);
            menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');

            if (open) {
                requestAnimationFrame(function () {
                    document.querySelectorAll('.sp-nav-group > menu').forEach(function (menu) {
                        menu.style.setProperty('--sp-group-height', menu.scrollHeight + 'px');
                    });
                });
            }
            return;
        }

        var commandButton = event.target.closest('[data-sp-command-open]');
        if (commandButton) {
            event.preventDefault();
            openPalette();
            return;
        }

        var passwordButton = event.target.closest('[data-sp-password-toggle]');
        if (passwordButton) {
            var input = document.querySelector('#password');
            if (!input) {
                return;
            }

            var visible = input.getAttribute('type') === 'text';
            input.setAttribute('type', visible ? 'password' : 'text');
            passwordButton.classList.toggle('is-visible', !visible);
            passwordButton.setAttribute('aria-pressed', visible ? 'false' : 'true');
            passwordButton.setAttribute('aria-label', visible ? '显示密码' : '隐藏密码');
            return;
        }

        if (event.target.closest('#sp-nav a')) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function (event) {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            openPalette();
            return;
        }

        if (event.key === 'Escape') {
            if (palette && !palette.hidden) {
                closePalette();
            } else {
                closeMenu();
            }
        }
    });

    if (media && media.addEventListener) {
        media.addEventListener('change', function (event) {
            var saved = '';
            try {
                saved = localStorage.getItem(themeKey) || '';
            } catch (error) {
                saved = '';
            }
            if (!saved && !readCookie(cookieKey)) {
                applyTheme(event.matches ? 'dark' : 'light', false);
            }
        });
    }

    function setupConfirmModal() {
        var overlay = document.createElement('div');
        overlay.className = 'sp-confirm-overlay';
        overlay.hidden = true;
        overlay.innerHTML = ''
            + '<div class="sp-confirm-window" role="alertdialog" aria-modal="true" aria-labelledby="sp-confirm-title">'
            + '  <span class="sp-confirm-kicker">CONFIRM ACTION</span>'
            + '  <h3 id="sp-confirm-title">请确认操作</h3>'
            + '  <p class="sp-confirm-message"></p>'
            + '  <div class="sp-confirm-actions">'
            + '    <button type="button" class="sp-confirm-cancel">取消</button>'
            + '    <button type="button" class="sp-confirm-ok">确认</button>'
            + '  </div>'
            + '</div>';
        document.body.appendChild(overlay);

        var message = overlay.querySelector('.sp-confirm-message');
        var ok = overlay.querySelector('.sp-confirm-ok');
        var cancel = overlay.querySelector('.sp-confirm-cancel');
        var pending = null;

        function close() {
            overlay.hidden = true;
            pending = null;
        }

        function ask(text, callback) {
            pending = callback;
            message.textContent = text || '确定执行此操作吗？';
            overlay.hidden = false;
            cancel.focus();
        }

        ok.addEventListener('click', function () {
            var callback = pending;
            close();

            if (callback) {
                var nativeConfirm = window.confirm;
                window.confirm = function () { return true; };

                try {
                    callback();
                } finally {
                    window.setTimeout(function () {
                        window.confirm = nativeConfirm;
                    }, 0);
                }
            }
        });

        cancel.addEventListener('click', close);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) close();
        });
        document.addEventListener('keydown', function (event) {
            if (!overlay.hidden && 'Escape' === event.key) close();
        });

        function confirmText(node) {
            var onclick = node.getAttribute('onclick') || '';
            var onsubmit = node.getAttribute('onsubmit') || '';
            var match = onclick.match(/confirm\(\s*(['"])([\s\S]*?)\1\s*\)/)
                || onsubmit.match(/confirm\(\s*(['"])([\s\S]*?)\1\s*\)/);
            return match ? match[2] : '';
        }

        document.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0) return;
            var node = event.target.closest('[onclick]');

            if (!node || node.dataset.spConfirmed) return;

            var text = confirmText(node);

            if (!text) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            ask(text, function () {
                node.dataset.spConfirmed = '1';
                node.click();
                window.setTimeout(function () { delete node.dataset.spConfirmed; }, 0);
            });
        }, true);

        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (!form || form.dataset.spConfirmed) return;

            var text = confirmText(form);

            if (!text) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            ask(text, function () {
                form.dataset.spConfirmed = '1';

                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(event.submitter || undefined);
                } else {
                    form.submit();
                }

                window.setTimeout(function () { delete form.dataset.spConfirmed; }, 0);
            });
        }, true);
    }

    function enhanceWriteArea() {
        var textarea = document.querySelector('.typecho-post-area #text');

        if (!textarea || textarea.dataset.spWriteEnhanced) {
            return;
        }

        textarea.dataset.spWriteEnhanced = '1';
        var host = textarea.closest('p') || textarea.parentNode;
        var meta = document.createElement('div');
        meta.className = 'sp-write-meta';
        meta.innerHTML = '<span data-count>0 字</span>'
            + '<span data-paragraphs>0 段</span>'
            + '<span data-reading>约 1 分钟</span>'
            + '<span class="sp-write-state">写作中</span>';
        host.insertAdjacentElement('beforebegin', meta);

        var count = meta.querySelector('[data-count]');
        var paragraphs = meta.querySelector('[data-paragraphs]');
        var reading = meta.querySelector('[data-reading]');
        var state = meta.querySelector('.sp-write-state');
        var save = document.querySelector('#btn-save');

        var titleInput = document.querySelector('.typecho-post-area #title');
        var titleCount = null;

        if (titleInput && !titleInput.dataset.spTitleTools) {
            titleInput.dataset.spTitleTools = '1';
            var tools = document.createElement('div');
            tools.className = 'sp-title-tools';
            tools.innerHTML = '<span data-title-count>0 字</span><button type="button" data-kook-upload>上传 KOOK 图片</button><button type="button" data-focus-write>专注模式</button><button type="button" data-fullscreen-write>全屏</button>';
            titleInput.insertAdjacentElement('afterend', tools);
            titleCount = tools.querySelector('[data-title-count]');
            titleCount.textContent = Array.from(titleInput.value || '').length + ' 字';

            titleInput.addEventListener('input', function () {
                titleCount.textContent = Array.from(titleInput.value || '').length + ' 字';
            });

            tools.querySelector('[data-focus-write]').addEventListener('click', function () {
                document.body.classList.toggle('sp-focus-write');
                var active = document.body.classList.contains('sp-focus-write');
                this.textContent = active ? '退出专注' : '专注模式';
                showToast(active ? '已进入专注写作' : '已退出专注写作', 'info');
            });
            tools.querySelector('[data-fullscreen-write]').addEventListener('click', function () {
                if (document.fullscreenElement) {
                    document.exitFullscreen();
                } else if (document.documentElement.requestFullscreen) {
                    document.documentElement.requestFullscreen();
                }
            });

            var mediaButton = tools.querySelector('[data-kook-upload]');
            var mediaFile = document.createElement('input');
            mediaFile.type = 'file';
            mediaFile.accept = 'image/png,image/jpeg,image/gif,image/webp';
            mediaFile.hidden = true;
            tools.appendChild(mediaFile);
            mediaButton.addEventListener('click', function () { mediaFile.click(); });
            mediaFile.addEventListener('change', function () {
                var picked = mediaFile.files && mediaFile.files[0];
                var media = window.MoePanelMedia || {};
                if (!picked) return;
                if (!media.endpoint || !media.token) {
                    showToast('KOOK 上传接口未就绪', 'error');
                    return;
                }
                var data = new FormData();
                data.append('file', picked);
                data.append('_', media.token);
                mediaButton.disabled = true;
                mediaButton.textContent = '上传中…';
                fetch(media.endpoint, { method: 'POST', credentials: 'same-origin', body: data })
                    .then(function (response) { return response.json(); })
                    .then(function (payload) {
                        if (!payload || !payload.ok || !payload.url) throw new Error((payload && payload.error) || '上传失败');
                        var markdown = '![' + (picked.name || 'image') + '](' + payload.url + ')';
                        textarea.setRangeText(markdown, textarea.selectionStart, textarea.selectionEnd, 'end');
                        textarea.dispatchEvent(new Event('input', { bubbles: true }));
                        showToast('KOOK 图片已插入', 'info');
                    })
                    .catch(function (error) { showToast(error.message || '上传失败', 'error'); })
                    .then(function () { mediaButton.disabled = false; mediaButton.textContent = '上传 KOOK 图片'; mediaFile.value = ''; });
            });
        }

        function update() {
            var value = textarea.value || '';
            var characters = Array.from(value.replace(/\s/g, '')).length;
            var blocks = value.trim() ? value.trim().split(/\n\s*\n/).length : 0;
            count.textContent = characters.toLocaleString('zh-CN') + ' 字';
            paragraphs.textContent = blocks + ' 段';
            reading.textContent = '约 ' + Math.max(1, Math.ceil(characters / 500)) + ' 分钟';
        }

        textarea.addEventListener('input', function () {
            update();
            state.textContent = '有未保存更改';
            state.classList.add('is-dirty');
        });

        if (save) {
            save.addEventListener('click', function () {
                state.textContent = '正在保存…';
                state.classList.remove('is-dirty');
            });
        }

        document.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && 's' === event.key.toLowerCase() && save) {
                event.preventDefault();
                save.click();
            }
        });

        update();
    }

    function showToast(message, type) {
        var stack = document.querySelector('.sp-toast-stack');

        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'sp-toast-stack';
            document.body.appendChild(stack);
        }

        var toast = document.createElement('div');
        toast.className = 'sp-toast' + (type ? ' is-' + type : '');
        toast.textContent = message;
        stack.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('is-visible'); });
        window.setTimeout(function () {
            toast.classList.remove('is-visible');
            window.setTimeout(function () { toast.remove(); }, 220);
        }, 2400);
    }

    window.MoePanelToast = showToast;

    function setupSidebarToggle() {
        var sidebar = document.querySelector('.sp-sidebar');
        if (!sidebar || sidebar.querySelector('.sp-sidebar-toggle')) return;

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'sp-sidebar-toggle';
        button.title = '收起侧边栏 (Shift+S)';
        button.setAttribute('aria-label', '收起侧边栏');
        button.innerHTML = '‹';
        sidebar.appendChild(button);

        var reveal = document.createElement('button');
        reveal.type = 'button';
        reveal.className = 'sp-sidebar-reveal';
        reveal.title = '展开侧边栏 (Shift+S)';
        reveal.setAttribute('aria-label', '展开侧边栏');
        reveal.innerHTML = '›';
        document.body.appendChild(reveal);

        function apply(hidden, persist) {
            document.body.classList.toggle('sp-sidebar-hidden', hidden);
            button.setAttribute('aria-expanded', hidden ? 'false' : 'true');
            if (persist) writeJson('sp-sidebar-state', { hidden: hidden });
        }

        var stored = readJson('sp-sidebar-state', {});
        apply(!!(stored && stored.hidden), false);

        button.addEventListener('click', function () {
            apply(true, true);
            showToast('侧边栏已收起', 'info');
        });
        reveal.addEventListener('click', function () {
            apply(false, true);
            showToast('侧边栏已展开', 'info');
        });
        document.addEventListener('keydown', function (event) {
            if (event.shiftKey && !event.ctrlKey && !event.metaKey && 's' === event.key.toLowerCase()
                && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
                event.preventDefault();
                var hidden = document.body.classList.contains('sp-sidebar-hidden');
                apply(!hidden, true);
            }
        });
    }

    function setupBackToTop() {
        if (document.querySelector('.sp-backtop')) return;

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'sp-backtop';
        button.setAttribute('aria-label', '回到顶部');
        button.innerHTML = '<span>↑</span>';
        document.body.appendChild(button);

        function sync() {
            var max = document.documentElement.scrollHeight - window.innerHeight;
            var ratio = max > 0 ? Math.min(1, window.scrollY / max) : 0;
            button.style.setProperty('--sp-progress', Math.round(ratio * 100) + '%');
            button.classList.toggle('is-visible', window.scrollY > 420);
        }

        window.addEventListener('scroll', sync, { passive: true });
        button.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        sync();
    }

    function setupReveal() {
        var nodes = document.querySelectorAll('.sp-card, .sp-stat-card, .sp-dash-panel, .sp-page-title');
        if (!('IntersectionObserver' in window)) {
            nodes.forEach(function (node) { node.classList.add('sp-revealed'); });
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('sp-revealed');
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.06 });

        nodes.forEach(function (node) {
            if (node.classList.contains('sp-revealed')) return;
            node.classList.add('sp-reveal');
            observer.observe(node);
        });
    }

    function setupClock() {
        var date = document.querySelector('.sp-top-date');
        if (!date || date.querySelector('.sp-live-clock')) return;

        var clock = document.createElement('span');
        clock.className = 'sp-live-clock';
        date.appendChild(clock);

        function tick() {
            clock.textContent = new Date().toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit', hour12: false });
        }

        tick();
        window.setInterval(tick, 30000);
    }

    function setupRipple() {
        document.addEventListener('pointerdown', function (event) {
            var target = event.target.closest('.sp-write-btn, .sp-top-actions button, .sp-btn, .sp-ks-bot-actions button');
            if (!target || target.disabled) return;

            var rect = target.getBoundingClientRect();
            var ripple = document.createElement('span');
            ripple.className = 'sp-ripple';
            ripple.style.left = (event.clientX - rect.left) + 'px';
            ripple.style.top = (event.clientY - rect.top) + 'px';
            target.appendChild(ripple);
            window.setTimeout(function () { ripple.remove(); }, 520);
        });
    }

    window.MoePanelRefresh = function () {
        animateCounters();
        enhanceWriteArea();
        setupReveal();
        setupClock();
        setupBackToTop();
        setupCustomSelects();
        setupThemeMenu();
        setupThemeDelegation();
        restoreAccentColors();
    };

    function enhanceSelect(select) {
        if (!select || select.dataset.spSelectReady || select.multiple || Number(select.size) > 1) {
            return;
        }

        if (select.closest('.sp-select') || select.classList.contains('sp-no-custom-select')) {
            return;
        }

        select.dataset.spSelectReady = '1';
        var wrap = document.createElement('div');
        wrap.className = 'sp-select';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('sp-select-native');

        var display = document.createElement('button');
        display.type = 'button';
        display.className = 'sp-select-display';
        display.setAttribute('role', 'combobox');
        display.setAttribute('aria-haspopup', 'listbox');
        display.setAttribute('aria-expanded', 'false');
        var arrow = document.createElement('span');
        arrow.className = 'sp-select-arrow';
        arrow.textContent = '⌄';
        display.appendChild(document.createTextNode(''));
        display.appendChild(arrow);
        var menu = document.createElement('div');
        menu.className = 'sp-select-menu';
        menu.setAttribute('role', 'listbox');
        wrap.appendChild(display);
        wrap.appendChild(menu);

        function sync() {
            var option = select.options[select.selectedIndex] || null;
            display.firstChild.nodeValue = option ? option.textContent : '';
            display.disabled = select.disabled;
            wrap.classList.toggle('is-disabled', select.disabled);
        }

        function buildMenu() {
            menu.innerHTML = '';

            Array.prototype.forEach.call(select.options, function (option, index) {
                if (option.disabled) return;

                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'sp-select-option' + (index === select.selectedIndex ? ' is-selected' : '');
                item.textContent = option.textContent;
                item.setAttribute('role', 'option');
                item.addEventListener('click', function () {
                    select.selectedIndex = index;
                    select.dispatchEvent(new Event('input', { bubbles: true }));
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    sync();
                    close();
                });
                menu.appendChild(item);
            });
        }

        function open() {
            if (select.disabled) return;
            buildMenu();
            menu.classList.add('is-open');
            display.setAttribute('aria-expanded', 'true');
            var selected = menu.querySelector('.sp-select-option.is-selected');
            if (selected) selected.scrollIntoView({ block: 'nearest' });
        }

        function close() {
            menu.classList.remove('is-open');
            display.setAttribute('aria-expanded', 'false');
        }

        display.addEventListener('click', function (event) {
            event.stopPropagation();
            menu.classList.contains('is-open') ? close() : open();
        });
        display.addEventListener('keydown', function (event) {
            if ('Escape' === event.key) {
                close();
            } else if ('ArrowDown' === event.key || 'Enter' === event.key || ' ' === event.key) {
                event.preventDefault();
                open();
                var first = menu.querySelector('.sp-select-option');
                if (first) first.focus();
            }
        });
        menu.addEventListener('keydown', function (event) {
            var items = Array.prototype.slice.call(menu.querySelectorAll('.sp-select-option'));
            var index = items.indexOf(document.activeElement);

            if ('Escape' === event.key) {
                close();
                display.focus();
            } else if ('ArrowDown' === event.key) {
                event.preventDefault();
                (items[index + 1] || items[0]).focus();
            } else if ('ArrowUp' === event.key) {
                event.preventDefault();
                (items[index - 1] || items[items.length - 1]).focus();
            }
        });
        select.addEventListener('change', sync);

        if ('MutationObserver' in window) {
            new MutationObserver(sync).observe(select, { childList: true });
        }

        sync();
        window.__spSelects = window.__spSelects || [];
        window.__spSelects.push(sync);
    }

    function hexToRgb(hex) {
        var match = /^#?([a-f0-9]{6})$/i.exec(String(hex || ''));
        if (!match) return '';
        var value = parseInt(match[1], 16);
        return [(value >> 16) & 255, (value >> 8) & 255, value & 255].join(', ');
    }

    function applyAccentColors(primary, secondary, persist) {
        if (!/^#[0-9a-f]{6}$/i.test(primary)) return;
        if (!/^#[0-9a-f]{6}$/i.test(secondary)) secondary = primary;
        root.style.setProperty('--sp-accent', primary);
        root.style.setProperty('--sp-accent-2', secondary);
        root.style.setProperty('--sp-accent-rgb', hexToRgb(primary));

        var first = document.querySelector('input[name="accentColor"]');
        var second = document.querySelector('input[name="accentColor2"]');
        [first, second].forEach(function (input, index) {
            if (!input) return;
            input.value = index === 0 ? primary : secondary;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        if (persist) {
            try {
                localStorage.setItem('sp-accent', primary);
                localStorage.setItem('sp-accent-2', secondary);
            } catch (error) {}
        }
    }

    function restoreAccentColors() {
        try {
            var primary = localStorage.getItem('sp-accent') || '';
            var secondary = localStorage.getItem('sp-accent-2') || '';
            if (/^#[0-9a-f]{6}$/i.test(primary)) applyAccentColors(primary, secondary || primary, false);
        } catch (error) {}
    }

    function setupThemeMenu() {
        document.querySelectorAll('[data-sp-theme-menu-open]').forEach(function (trigger) {
            trigger.addEventListener('click', function (event) {
                event.stopPropagation();
                var old = document.querySelector('.sp-theme-menu');
                if (old) { old.remove(); return; }

                var menu = document.createElement('div');
                menu.className = 'sp-theme-menu sp-theme-menu--wide';
                menu.innerHTML = '<div class="sp-theme-menu-head"><strong>外观</strong><span>APPEARANCE</span></div>'
                    + '<button type="button" data-theme-choice="light"><i class="sp-theme-swatch sp-theme-swatch--light"></i><span>Mac 浅色</span><em>Light</em></button>'
                    + '<button type="button" data-theme-choice="dark"><i class="sp-theme-swatch sp-theme-swatch--dark"></i><span>Mac 深色</span><em>Dark</em></button>'
                    + '<div class="sp-theme-section">主题配色</div>'
                    + '<div class="sp-theme-swatches"><button type="button" data-accent="#5b5bd6" data-accent2="#7c6cff" style="--sw:#5b5bd6" title="紫"></button><button type="button" data-accent="#4a9eff" data-accent2="#7cc4ff" style="--sw:#4a9eff" title="蓝"></button><button type="button" data-accent="#2f9e5f" data-accent2="#55c47f" style="--sw:#2f9e5f" title="绿"></button><button type="button" data-accent="#d99a35" data-accent2="#e8b45a" style="--sw:#d99a35" title="橙"></button><button type="button" data-accent="#d9534f" data-accent2="#ef7a76" style="--sw:#d9534f" title="红"></button><button type="button" data-accent="#e36fa8" data-accent2="#f39ac4" style="--sw:#e36fa8" title="粉"></button></div>'
                    + '<div class="sp-theme-custom"><label><span>主色</span><input type="color" data-accent-primary></label><label><span>辅色</span><input type="color" data-accent-secondary></label><button type="button" data-accent-apply>应用</button></div>';
                document.body.appendChild(menu);
                var rect = trigger.getBoundingClientRect();
                menu.style.top = (rect.bottom + 8) + 'px';
                menu.style.right = Math.max(12, window.innerWidth - rect.right) + 'px';

                var primaryInput = menu.querySelector('[data-accent-primary]');
                var secondaryInput = menu.querySelector('[data-accent-secondary]');
                var storedPrimary = '';
                var storedSecondary = '';
                try {
                    storedPrimary = localStorage.getItem('sp-accent') || '';
                    storedSecondary = localStorage.getItem('sp-accent-2') || '';
                } catch (error) {}
                primaryInput.value = root.style.getPropertyValue('--sp-accent') || storedPrimary || '#5b5bd6';
                secondaryInput.value = root.style.getPropertyValue('--sp-accent-2') || storedSecondary || '#7c6cff';

                menu.querySelectorAll('[data-accent]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        applyAccentColors(button.getAttribute('data-accent'), button.getAttribute('data-accent2'), true);
                    });
                });
                menu.querySelector('[data-accent-apply]').addEventListener('click', function () {
                    applyAccentColors(primaryInput.value, secondaryInput.value, true);
                    showToast('主题配色已更新', 'info');
                });

                menu.querySelectorAll('[data-theme-choice]').forEach(function (button) {
                    button.classList.toggle('is-active', button.getAttribute('data-theme-choice') === currentTheme());
                    button.addEventListener('click', function () {
                        applyTheme(button.getAttribute('data-theme-choice'), true);
                        menu.remove();
                        showToast('dark' === button.getAttribute('data-theme-choice') ? '已切换到深色模式' : '已切换到浅色模式', 'info');
                    });
                });

                trigger.setAttribute('aria-expanded', 'true');
                var close = function (e) {
                    if (menu.contains(e.target)) return;
                    menu.remove();
                    trigger.setAttribute('aria-expanded', 'false');
                    document.removeEventListener('click', close);
                };
                window.setTimeout(function () { document.addEventListener('click', close); }, 0);
            });
        });
    }

    function setupThemeDelegation() {
        if (window.__spThemeDelegated) return;
        window.__spThemeDelegated = true;
        document.addEventListener('click', function (event) {
            var choice = event.target.closest ? event.target.closest('[data-theme-choice]') : null;
            if (!choice) return;
            event.preventDefault();
            event.stopPropagation();
            var theme = choice.getAttribute('data-theme-choice');
            applyTheme(theme, true);
            var menu = document.querySelector('.sp-theme-menu');
            if (menu) menu.remove();
            showToast('dark' === theme ? '已切换到 Mac 深色' : '已切换到 Mac 浅色', 'info');
        }, true);
    }

    function setupCustomSelects() {
        document.querySelectorAll('select').forEach(enhanceSelect);

        if (window.__spSelectObserver) return;

        window.__spSelectObserver = new MutationObserver(function () {
            window.requestAnimationFrame(function () {
                document.querySelectorAll('select').forEach(enhanceSelect);
            });
        });
        window.__spSelectObserver.observe(document.body, { childList: true, subtree: true });
    }

    function init() {
        root.classList.add('sp-js');
        ensureShellFallback();
        applyTheme(currentTheme(), false);
        restoreAccentColors();
        setupThemeMenu();
        setupThemeDelegation();
        setupNavGroups();
        wrapPageTitle();
        animateCounters();
        setupConfirmModal();

        [enhanceWriteArea, setupSidebarToggle, setupBackToTop, setupReveal, setupClock, setupRipple, setupCustomSelects].forEach(function (step) {
            try {
                step();
            } catch (error) {
                if (window.console) console.warn('MoePanel step failed', error);
            }
        });

        document.querySelectorAll('.sp-nav-core li.focus > a').forEach(function (link) {
            link.setAttribute('aria-current', 'page');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
