(function () {
    'use strict';

    if (window.MoePanelSpa) {
        return;
    }

    window.MoePanelSpa = true;
    window.__spaMarker = 1;
    window.__spaDebug = [];

    var controller = null;
    var navSeq = 0;
    var progress = document.createElement('div');
    progress.className = 'sp-ajax-progress';
    progress.setAttribute('aria-hidden', 'true');
    document.body.appendChild(progress);

    function isInternalLink(link) {
        if (!link || link.target || link.hasAttribute('download')) return false;
        if (link.hasAttribute('data-sp-no-ajax')) return false;

        var url;
        try { url = new URL(link.href, location.href); } catch (error) { return false; }
        if (url.origin !== location.origin) return false;
        if (url.pathname.indexOf('/admin/') === -1) return false;
        if (url.pathname.indexOf('/action/') !== -1) return false;
        if (/logout|login\.php|register\.php/i.test(url.pathname + url.search)) return false;
        if (/(^|&)(kook_upload|kook_save)=/i.test(url.search)) return false;
        if (link.getAttribute('href') === '#' || link.href === location.href + '#') return false;
        if (link.closest('[data-sp-no-ajax]')) return false;
        if (link.closest('.sp-command-palette')) return false;

        return true;
    }

    var progressTimer = null;
    var progressDoneTimer = null;

    function setBusy(busy) {
        document.documentElement.classList.toggle('sp-ajax-busy', busy);
        window.clearTimeout(progressTimer);
        window.clearTimeout(progressDoneTimer);

        if (busy) {
            progress.classList.remove('is-done');
            progress.classList.add('is-active');
            progress.style.transform = 'scaleX(0.12)';
            progressTimer = window.setTimeout(function () {
                progress.style.transform = 'scaleX(0.72)';
            }, 120);
        } else {
            progress.style.transform = 'scaleX(1)';
            progress.classList.add('is-done');
            progressDoneTimer = window.setTimeout(function () {
                progress.classList.remove('is-active', 'is-done');
                progress.style.transform = 'scaleX(0)';
            }, 260);
        }
    }

    function activateNav(url) {
        var links = document.querySelectorAll('.sp-nav-core a');

        links.forEach(function (link) {
            var item = link.closest('li');
            if (item) item.classList.remove('focus');
            link.removeAttribute('aria-current');
        });

        links.forEach(function (link) {
            var candidate;
            try { candidate = new URL(link.href, location.href); } catch (error) { return; }
            if (candidate.pathname !== url.pathname) return;
            if (candidate.search && candidate.search !== url.search) return;

            var item = link.closest('li');
            if (item) item.classList.add('focus');
            link.setAttribute('aria-current', 'page');
        });
    }

    function loadScript(src) {
        return new Promise(function (resolve) {
            var isSettingsScript = src.indexOf('/kook-settings.js') !== -1;
            var existing = document.querySelector('script[src="' + src.replace(/"/g, '\\"') + '"]');
            if (existing && !isSettingsScript) return resolve();

            var script = document.createElement('script');
            script.src = isSettingsScript ? src + (src.indexOf('?') === -1 ? '?' : '&') + 'sp_ajax=' + Date.now() : src;
            script.async = false;
            script.onload = resolve;
            script.onerror = resolve;
            document.body.appendChild(script);
        });
    }

    function adoptDocument(doc) {
        var main = doc.querySelector('main.main');
        var current = document.querySelector('main.main');
        if (!main || !current) return false;

        var styles = doc.querySelectorAll('link[rel="stylesheet"][href], style');
        styles.forEach(function (asset) {
            if (asset.tagName === 'LINK') {
                var href = new URL(asset.getAttribute('href'), location.href).href;
                if (!document.querySelector('link[rel="stylesheet"][href="' + href.replace(/"/g, '\\"') + '"]')) {
                    var link = document.createElement('link');
                    link.rel = 'stylesheet';
                    link.href = href;
                    document.head.appendChild(link);
                }
            }
        });

        var scripts = Array.prototype.slice.call(doc.querySelectorAll('script'));

        document.querySelectorAll('body > .kookauth-save, body > .sp-ks-toast, body > .sp-kc-overlay').forEach(function (node) {
            node.remove();
        });
        document.body.classList.remove('sp-kc-open');
        current.replaceWith(document.importNode(main, true));
        document.title = doc.title;
        var sidebarHidden = document.body.classList.contains('sp-sidebar-hidden');
        document.body.className = doc.body.className + ' sp-js' + (sidebarHidden ? ' sp-sidebar-hidden' : '');
        document.documentElement.classList.add('sp-ajax-ready');

        return scripts.reduce(function (chain, old) {
            return chain.then(function () {
                if (old.src) return loadScript(new URL(old.src, location.href).href);

                if (old.textContent && old.textContent.trim()) {
                    var inline = document.createElement('script');
                    inline.textContent = old.textContent;
                    document.body.appendChild(inline);
                }
            });
        }, Promise.resolve()).then(function () {
            activateNav(new URL(location.href));
            if (window.MoePanelRefresh) {
                try { window.MoePanelRefresh(); } catch (error) { window.__spaDebug.push(String(error)); }
            }
            window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
            window.__spaMarker += 1;
            return true;
        });
    }

    function navigate(url, push) {
        var seq = ++navSeq;
        if (controller) controller.abort();
        controller = new AbortController();
        document.querySelectorAll('body > .kookauth-save, body > .sp-ks-toast, body > .sp-kc-overlay').forEach(function (node) {
            node.remove();
        });
        document.body.classList.remove('sp-kc-open');
        setBusy(true);

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
            cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.text();
        }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            if (doc.querySelector('input[name="password"]') && !document.querySelector('.sp-sidebar')) {
                location.href = url;
                return;
            }
            if (push && location.href !== url) {
                history.pushState({ sp: true }, '', url);
            }
            return adoptDocument(doc).then(function (ok) {
                if (!ok) location.href = url;
            });
        }).catch(function (error) {
            if ('AbortError' !== error.name) {
                window.__spaDebug.push(String(error && error.stack || error));
                location.href = url;
            }
        }).then(function () {
            if (seq === navSeq) setBusy(false);
        });
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        var link = event.target.closest('a');
        if (!isInternalLink(link)) return;

        event.preventDefault();
        navigate(link.href, true);
    });

    window.addEventListener('popstate', function () {
        if (location.href.indexOf('/admin/') !== -1) navigate(location.href, false);
    });
})();
