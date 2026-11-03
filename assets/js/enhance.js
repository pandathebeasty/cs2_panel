/* CS2 Panel — UI enhancements: toasts, confirm modal, command palette,
   dashboard layout, visitor colour mode and theme generator.
   Loaded before app.js; everything is progressive (the page works without it). */
(function () {
    'use strict';

    var UI = window.CS2_UI || {};
    var I18N = UI.i18n || {};
    var BASE = window.CS2_BASE || '';
    var doc = document;

    function t(key, fallback) { return I18N[key] || fallback || key; }
    function el(tag, cls, text) {
        var n = doc.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function withBase(url) {
        if (!url || /^(https?:)?\/\//.test(url) || !BASE || url.indexOf(BASE + '/') === 0) return url;
        return BASE + (url.charAt(0) === '/' ? url : '/' + url);
    }
    function reducedMotion() {
        return doc.documentElement.getAttribute('data-effects') === 'reduced'
            || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    // ---- Screen-reader announcements ------------------------------------
    function announce(msg) {
        var live = doc.getElementById('x-live');
        if (!live) return;
        live.textContent = '';
        setTimeout(function () { live.textContent = msg; }, 50);
    }
    window.CS2Announce = announce;

    // ---- Toasts (server flashes are styled as toasts by enhance.css) ------
    function toastHost() {
        var host = doc.querySelector('.x-flash-source');
        if (!host) {
            host = el('div', 'x-flash-source');
            doc.body.appendChild(host);
        }
        return host;
    }
    window.CS2Toast = function (message, type) {
        var n = el('div', 'flash flash-' + (type || 'success'), message);
        n.setAttribute('role', type === 'error' ? 'alert' : 'status');
        n.style.cursor = 'pointer';
        function dismiss() {
            if (n._gone) return;
            n._gone = true;
            n.classList.add('flash-out');
            setTimeout(function () { if (n.parentNode) n.parentNode.removeChild(n); }, 400);
        }
        n.addEventListener('click', dismiss);
        setTimeout(dismiss, 5000);
        toastHost().appendChild(n);
        return n;
    };

    // ---- Modal helper ------------------------------------------------------
    var lastFocus = null;
    function trapFocus(box, e) {
        if (e.key !== 'Tab') return;
        var f = box.querySelectorAll('button:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])');
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && doc.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && doc.activeElement === last) { e.preventDefault(); first.focus(); }
    }
    function openLayer(layer) {
        lastFocus = doc.activeElement;
        doc.body.appendChild(layer);
        doc.documentElement.classList.add('x-layer-open');
        requestAnimationFrame(function () { layer.classList.add('is-open'); });
    }
    function closeLayer(layer) {
        layer.classList.remove('is-open');
        doc.documentElement.classList.remove('x-layer-open');
        setTimeout(function () { if (layer.parentNode) layer.parentNode.removeChild(layer); }, reducedMotion() ? 0 : 180);
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }

    // ---- Confirm modal -----------------------------------------------------
    /** opts: {message, word, danger}; resolves true when confirmed. */
    window.CS2Confirm = function (opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var layer = el('div', 'x-modal-layer');
            var box = el('div', 'x-modal' + (opts.danger ? ' is-danger' : ''));
            box.setAttribute('role', 'alertdialog');
            box.setAttribute('aria-modal', 'true');
            var title = el('h2', 'x-modal-title', t('confirm_title', 'Are you sure?'));
            title.id = 'x-confirm-title';
            box.setAttribute('aria-labelledby', title.id);
            var icon = el('div', 'x-modal-icon');
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = opts.danger ? '!' : '?';
            box.appendChild(icon);
            box.appendChild(title);
            if (opts.message) {
                var p = el('p', 'x-modal-text', opts.message);
                p.id = 'x-confirm-text';
                box.setAttribute('aria-describedby', p.id);
                box.appendChild(p);
            }
            var input = null;
            if (opts.word) {
                var lab = el('label', 'x-modal-type');
                var hint = t('confirm_type', 'Type {word} to confirm').split('{word}');
                lab.appendChild(doc.createTextNode(hint[0] || ''));
                lab.appendChild(el('code', '', opts.word));
                lab.appendChild(doc.createTextNode(hint[1] || ''));
                input = el('input');
                input.type = 'text';
                input.autocomplete = 'off';
                input.spellcheck = false;
                lab.appendChild(input);
                box.appendChild(lab);
            }
            var actions = el('div', 'x-modal-actions');
            var cancel = el('button', 'btn btn-ghost', t('confirm_cancel', 'Cancel'));
            cancel.type = 'button';
            var ok = el('button', 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary'), t('confirm_ok', 'Confirm'));
            ok.type = 'button';
            if (input) ok.disabled = true;
            actions.appendChild(cancel);
            actions.appendChild(ok);
            box.appendChild(actions);
            layer.appendChild(box);

            var done = false;
            function finish(v) {
                if (done) return;
                done = true;
                doc.removeEventListener('keydown', onKey, true);
                closeLayer(layer);
                resolve(v ? (input ? input.value.trim() : true) : false);
            }
            function onKey(e) {
                if (e.key === 'Escape') { e.preventDefault(); finish(false); }
                else if (e.key === 'Enter' && !ok.disabled && (doc.activeElement === input || doc.activeElement === ok)) { e.preventDefault(); finish(true); }
                else trapFocus(box, e);
            }
            if (input) {
                input.addEventListener('input', function () { ok.disabled = input.value.trim() !== opts.word; });
            }
            cancel.addEventListener('click', function () { finish(false); });
            ok.addEventListener('click', function () { finish(true); });
            layer.addEventListener('mousedown', function (e) { if (e.target === layer) finish(false); });
            doc.addEventListener('keydown', onKey, true);
            openLayer(layer);
            setTimeout(function () { (input || cancel).focus(); }, 30);
        });
    };

    function markBusy(form, submitter) {
        setTimeout(function () {
            var btn = submitter || form.querySelector('[type=submit]');
            form.querySelectorAll('[type=submit]').forEach(function (b) { b.disabled = true; });
            if (btn) btn.classList.add('is-busy');
            form.setAttribute('aria-busy', 'true');
        }, 0);
    }

    doc.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.hasAttribute('data-confirm') && !form._xConfirmed) {
            e.preventDefault();
            e.stopImmediatePropagation();
            var submitter = e.submitter || null;
            var word = form.getAttribute('data-confirm-type') || '';
            window.CS2Confirm({
                message: form.getAttribute('data-confirm'),
                word: word,
                danger: form.hasAttribute('data-confirm-danger')
            }).then(function (res) {
                if (!res) return;
                if (word) {
                    var field = form.querySelector('[name="' + (form.getAttribute('data-confirm-field') || 'confirm') + '"]');
                    if (field) field.value = res;
                }
                form._xConfirmed = true;
                if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                else form.submit();
                setTimeout(function () { form._xConfirmed = false; }, 0);
            });
            return;
        }
        if (form.hasAttribute('data-busy') && !e.defaultPrevented) markBusy(form, e.submitter);
    }, true);

    // ---- Command palette ---------------------------------------------------
    var palette = null;
    function openPalette() {
        if (palette) return;
        var links = (UI.links || []).map(function (l) { return { label: l.label, group: l.group, url: withBase(l.url), kind: 'page' }; });
        var layer = el('div', 'x-modal-layer x-palette-layer');
        var box = el('div', 'x-palette');
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-label', t('palette_placeholder', 'Search'));
        var input = el('input', 'x-palette-input');
        input.type = 'search';
        input.placeholder = t('palette_placeholder', 'Search…');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-controls', 'x-palette-list');
        input.autocomplete = 'off';
        var list = el('ul', 'x-palette-list');
        list.id = 'x-palette-list';
        list.setAttribute('role', 'listbox');
        var foot = el('div', 'x-palette-foot', t('palette_hint', ''));
        box.appendChild(input);
        box.appendChild(list);
        box.appendChild(foot);
        layer.appendChild(box);

        var items = [], active = 0, players = [], timer = null, seq = 0;
        function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
        function render() {
            var q = norm(input.value.trim());
            var pages = links.filter(function (l) { return !q || norm(l.label).indexOf(q) !== -1 || norm(l.group).indexOf(q) !== -1; }).slice(0, 12);
            items = pages.concat(players);
            list.innerHTML = '';
            if (!items.length) {
                list.appendChild(el('li', 'x-palette-empty', t('palette_empty', 'No results')));
                input.removeAttribute('aria-activedescendant');
                return;
            }
            var lastKind = '';
            items.forEach(function (it, i) {
                if (it.kind !== lastKind) {
                    lastKind = it.kind;
                    var h = el('li', 'x-palette-group', it.kind === 'page' ? t('palette_pages', 'Pages') : t('palette_players', 'Players'));
                    h.setAttribute('role', 'presentation');
                    list.appendChild(h);
                }
                var li = el('li', 'x-palette-item' + (i === active ? ' is-active' : ''));
                li.id = 'x-pal-' + i;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', i === active ? 'true' : 'false');
                li.appendChild(el('span', 'x-palette-label', it.label));
                if (it.group) li.appendChild(el('span', 'x-palette-meta', it.group));
                li.addEventListener('mousemove', function () { if (active !== i) { active = i; highlight(); } });
                li.addEventListener('click', function () { go(i); });
                list.appendChild(li);
            });
            input.setAttribute('aria-activedescendant', 'x-pal-' + active);
        }
        function highlight() {
            list.querySelectorAll('.x-palette-item').forEach(function (li) {
                var on = li.id === 'x-pal-' + active;
                li.classList.toggle('is-active', on);
                li.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on) li.scrollIntoView({ block: 'nearest' });
            });
            input.setAttribute('aria-activedescendant', 'x-pal-' + active);
        }
        function go(i) {
            var it = items[i];
            if (it && it.url) window.location.href = it.url;
        }
        function search() {
            var q = input.value.trim();
            players = [];
            if (!UI.search || q.length < 2) { render(); return; }
            var my = ++seq;
            fetch(BASE + '/search?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : { players: [] }; })
                .then(function (d) {
                    if (my !== seq || !palette) return;
                    players = (d.players || []).map(function (p) { return { label: p.name, group: p.steamid, url: p.url, kind: 'player' }; });
                    render();
                })
                .catch(function () {});
        }
        input.addEventListener('input', function () {
            active = 0;
            render();
            clearTimeout(timer);
            timer = setTimeout(search, 220);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (items.length) { active = (active + 1) % items.length; highlight(); } }
            else if (e.key === 'ArrowUp') { e.preventDefault(); if (items.length) { active = (active - 1 + items.length) % items.length; highlight(); } }
            else if (e.key === 'Enter') { e.preventDefault(); go(active); }
        });
        function close() {
            doc.removeEventListener('keydown', onKey, true);
            closeLayer(layer);
            palette = null;
        }
        function onKey(e) {
            if (e.key === 'Escape') { e.preventDefault(); close(); }
            else trapFocus(box, e);
        }
        layer.addEventListener('mousedown', function (e) { if (e.target === layer) close(); });
        doc.addEventListener('keydown', onKey, true);
        palette = layer;
        render();
        openLayer(layer);
        setTimeout(function () { input.focus(); }, 30);
    }
    doc.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            openPalette();
        }
    });
    doc.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('[data-palette-open]')) openPalette();
    });

    // ---- Player right-click menu (any element with data-steamid) ------------
    var ctxMenu = null;
    function closeCtx() {
        if (!ctxMenu) return;
        ctxMenu.remove();
        ctxMenu = null;
        doc.removeEventListener('keydown', ctxKey, true);
    }
    function ctxKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); closeCtx(); return; }
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        e.preventDefault();
        var items = Array.prototype.slice.call(ctxMenu.querySelectorAll('.x-ctx-item'));
        var i = items.indexOf(doc.activeElement);
        items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length].focus();
    }
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
        var ta = el('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;opacity:0';
        doc.body.appendChild(ta);
        ta.select();
        try { doc.execCommand('copy'); } catch (err) {}
        ta.remove();
        return Promise.resolve();
    }
    doc.addEventListener('contextmenu', function (e) {
        var target = e.target.closest && e.target.closest('[data-steamid]');
        if (!target || e.shiftKey) return;
        var sid = target.getAttribute('data-steamid');
        if (!/^\d{17}$/.test(sid)) return;
        e.preventDefault();
        closeCtx();
        var menu = el('div', 'x-ctx');
        menu.setAttribute('role', 'menu');
        function add(label, href, onClick) {
            var a = el(href ? 'a' : 'button', 'x-ctx-item', label);
            a.setAttribute('role', 'menuitem');
            if (href) {
                a.href = href;
                if (/^https?:/.test(href)) { a.target = '_blank'; a.rel = 'noopener'; }
            } else {
                a.type = 'button';
            }
            a.addEventListener('click', function (ev) { if (onClick) { ev.preventDefault(); onClick(); } closeCtx(); });
            menu.appendChild(a);
        }
        if (UI.search) add(t('ctx_profile', 'Player Profile'), withBase('/player/' + sid));
        add(t('ctx_steam', 'Steam Profile') + ' ↗', 'https://steamcommunity.com/profiles/' + sid);
        add(t('ctx_copy', 'Copy SteamID64'), null, function () {
            copyText(sid).then(function () { if (window.CS2Toast) CS2Toast(t('ctx_copied', 'Copied') + ': ' + sid, 'success'); });
        });
        doc.body.appendChild(menu);
        var w = menu.offsetWidth, h = menu.offsetHeight;
        menu.style.left = Math.min(e.clientX, window.innerWidth - w - 8) + 'px';
        menu.style.top = Math.min(e.clientY, window.innerHeight - h - 8) + 'px';
        ctxMenu = menu;
        doc.addEventListener('keydown', ctxKey, true);
        menu.querySelector('.x-ctx-item').focus({ preventScroll: true });
    });
    ['mousedown', 'scroll', 'resize'].forEach(function (type) {
        (type === 'mousedown' ? doc : window).addEventListener(type, function (e) {
            if (ctxMenu && !(e.target && e.target.closest && e.target.closest('.x-ctx'))) closeCtx();
        }, true);
    });

    // ---- Visitor colour mode (non-managers; personal, stored locally) -------
    function initVisitorMode() {
        var btn = doc.querySelector('.x-visitor-mode');
        if (!btn) return;
        var root = doc.documentElement;
        var KEY = 'cs2.visitorMode';
        function stored() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
        btn.addEventListener('click', function () {
            var next = root.getAttribute('data-mode') === 'light' ? 'dark' : 'light';
            try { localStorage.setItem(KEY, next); } catch (e) {}
            root.setAttribute('data-mode', next);
            announce(next);
        });
        // The shared theme script may re-apply the panel mode; keep the visitor's pick.
        new MutationObserver(function () {
            var want = stored();
            if ((want === 'light' || want === 'dark') && root.getAttribute('data-mode') !== want) root.setAttribute('data-mode', want);
        }).observe(root, { attributes: true, attributeFilter: ['data-mode'] });
    }

    // ---- Dashboard: reorder / hide sections --------------------------------
    function initDashboard() {
        if (!doc.querySelector('[data-dashboard-hero]')) return;
        var headings = doc.querySelectorAll('#main-content .portal-section-heading');
        if (headings.length < 2) return;
        var parent = headings[0].parentElement;
        var KEY = 'cs2.dashLayout';
        var widgets = [];
        var node = headings[0];
        var current = null;
        while (node) {
            var next = node.nextElementSibling;
            if (node.classList.contains('portal-section-heading')) {
                current = el('div', 'x-widget');
                current.setAttribute('data-widget', String(widgets.length));
                parent.insertBefore(current, node);
                widgets.push(current);
            }
            if (current && node.tagName !== 'SCRIPT') current.appendChild(node);
            node = next;
        }
        if (widgets.length < 2) return;
        var anchor = el('div', 'x-dash-anchor');
        parent.insertBefore(anchor, widgets[0]);

        function load() { try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } }
        function save(s) { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {} }
        function state() {
            var s = load();
            var order = Array.isArray(s.order) ? s.order.filter(function (id) { return widgets[id]; }) : [];
            widgets.forEach(function (w, i) { if (order.indexOf(i) === -1) order.push(i); });
            return { order: order, hidden: Array.isArray(s.hidden) ? s.hidden : [] };
        }
        function apply(s) {
            var ref = anchor;
            s.order.forEach(function (id) {
                var w = widgets[id];
                parent.insertBefore(w, ref.nextSibling);
                ref = w;
                w.classList.toggle('is-hidden', s.hidden.indexOf(id) !== -1);
            });
        }
        apply(state());

        // Editing is started from Settings -> Themes (opens /?customize=1).
        var params = new URLSearchParams(location.search);
        if (params.get('customize') !== '1') return;
        var bar = el('div', 'x-dash-bar');
        var toggle = el('button', 'btn btn-sm btn-primary x-dash-toggle', t('dash_done', 'Done'));
        toggle.type = 'button';
        var reset = el('button', 'btn btn-sm btn-ghost', t('dash_reset', 'Reset'));
        reset.type = 'button';
        bar.appendChild(reset);
        bar.appendChild(toggle);
        parent.insertBefore(bar, anchor);

        function tools(w) {
            var id = +w.getAttribute('data-widget');
            var box = el('div', 'x-widget-tools');
            function mk(label, text, fn) {
                var b = el('button', 'btn btn-sm btn-icon', text);
                b.type = 'button';
                b.title = label;
                b.setAttribute('aria-label', label);
                b.addEventListener('click', fn);
                box.appendChild(b);
                return b;
            }
            mk('↑', '↑', function () { move(id, -1); });
            mk('↓', '↓', function () { move(id, 1); });
            var vis = mk(t('dash_hide', 'Hide'), '', function () {
                var s = state();
                var at = s.hidden.indexOf(id);
                if (at === -1) s.hidden.push(id); else s.hidden.splice(at, 1);
                save(s);
                apply(s);
                label();
            });
            function label() {
                var hidden = w.classList.contains('is-hidden');
                vis.textContent = hidden ? t('dash_show', 'Show') : t('dash_hide', 'Hide');
                vis.setAttribute('aria-label', vis.textContent);
                vis.classList.remove('btn-icon');
            }
            label();
            w.insertBefore(box, w.firstChild);
        }
        function move(id, dir) {
            var s = state();
            var i = s.order.indexOf(id), j = i + dir;
            if (j < 0 || j >= s.order.length) return;
            s.order[i] = s.order[j];
            s.order[j] = id;
            save(s);
            apply(s);
            var w = widgets[id];
            w.scrollIntoView({ block: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' });
            var b = w.querySelector('.x-widget-tools button:nth-child(' + (dir < 0 ? 1 : 2) + ')');
            if (b) b.focus();
        }
        parent.classList.add('x-dash-editing');
        widgets.forEach(tools);
        bar.scrollIntoView({ block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' });
        toggle.addEventListener('click', function () {
            parent.classList.remove('x-dash-editing');
            widgets.forEach(function (w) { var o = w.querySelector('.x-widget-tools'); if (o) o.remove(); });
            bar.remove();
            params.delete('customize');
            var qs = params.toString();
            try { history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash); } catch (e) {}
        });
        reset.addEventListener('click', function () {
            try { localStorage.removeItem(KEY); } catch (e) {}
            var s = state();
            apply(s);
            widgets.forEach(function (w) { var o = w.querySelector('.x-widget-tools'); if (o) { o.remove(); tools(w); } });
        });
    }

    doc.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-dash-reset]');
        if (!b) return;
        e.preventDefault();
        try { localStorage.removeItem('cs2.dashLayout'); } catch (err) {}
        if (window.CS2Toast) window.CS2Toast(t('dash_reset_done', 'Dashboard layout reset.'), 'success');
    });

    // ---- Theme editor: generate / export / import ---------------------------
    function hexToHsl(hex) {
        var m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
        if (!m) return [210, 80, 60];
        var n = parseInt(m[1], 16);
        var r = (n >> 16 & 255) / 255, g = (n >> 8 & 255) / 255, b = (n & 255) / 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2, h = 0, s = 0;
        if (max !== min) {
            var d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            h = max === r ? (g - b) / d + (g < b ? 6 : 0) : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
            h *= 60;
        }
        return [h, s * 100, l * 100];
    }
    function hsl(h, s, l) {
        s /= 100; l /= 100;
        var k = function (n) { return (n + h / 30) % 12; };
        var a = s * Math.min(l, 1 - l);
        var f = function (n) { return l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1))); };
        return '#' + [f(0), f(8), f(4)].map(function (v) { return Math.round(v * 255).toString(16).padStart(2, '0'); }).join('');
    }
    var THEME_KEYS = ['bg', 'card', 'border', 'text', 'mut'];
    function setColor(id, value) {
        var i = doc.getElementById(id);
        if (!i || !/^#[0-9a-f]{6}$/i.test(value || '')) return;
        i.value = value.toLowerCase();
        i.dispatchEvent(new Event('input', { bubbles: true }));
        i.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function initThemeTools() {
        var gen = doc.getElementById('ct-generate');
        var exp = doc.getElementById('ct-export');
        var imp = doc.getElementById('ct-import');
        var accent = doc.getElementById('ct-accent');
        if (!gen && !exp && !imp) return;
        if (gen && accent) gen.addEventListener('click', function () {
            var h = hexToHsl(accent.value)[0];
            var dark = { bg: hsl(h, 22, 6), card: hsl(h, 20, 10), border: hsl(h, 16, 19), text: hsl(h, 20, 93), mut: hsl(h, 10, 55) };
            var light = { bg: hsl(h, 22, 94), card: hsl(h, 30, 98), border: hsl(h, 14, 82), text: hsl(h, 28, 14), mut: hsl(h, 12, 38) };
            THEME_KEYS.forEach(function (k) { setColor('ctd-' + k, dark[k]); setColor('ctl-' + k, light[k]); });
        });
        if (exp) exp.addEventListener('click', function () {
            var data = { name: (doc.getElementById('ct-name') || {}).value || 'custom', accent: accent ? accent.value : '', dark: {}, light: {} };
            THEME_KEYS.forEach(function (k) {
                data.dark[k] = (doc.getElementById('ctd-' + k) || {}).value || '';
                data.light[k] = (doc.getElementById('ctl-' + k) || {}).value || '';
            });
            var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            var a = el('a');
            a.href = URL.createObjectURL(blob);
            a.download = (data.name.replace(/[^a-z0-9_-]+/gi, '-').replace(/^-+|-+$/g, '') || 'theme') + '.json';
            doc.body.appendChild(a);
            a.click();
            setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
        });
        if (imp) imp.addEventListener('change', function () {
            var file = imp.files && imp.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function () {
                try {
                    var d = JSON.parse(String(reader.result || '{}'));
                    var name = doc.getElementById('ct-name');
                    if (name && typeof d.name === 'string') { name.value = d.name.slice(0, 40); name.dispatchEvent(new Event('input', { bubbles: true })); }
                    if (d.accent) setColor('ct-accent', d.accent);
                    THEME_KEYS.forEach(function (k) {
                        if (d.dark) setColor('ctd-' + k, d.dark[k]);
                        if (d.light) setColor('ctl-' + k, d.light[k]);
                    });
                } catch (e) {
                    window.CS2Toast('Invalid theme file', 'error');
                }
                imp.value = '';
            };
            reader.readAsText(file);
        });
    }

    function init() {
        initVisitorMode();
        initDashboard();
        initThemeTools();
        var flashes = doc.querySelectorAll('.x-flash-source .flash');
        if (flashes.length) announce(flashes[flashes.length - 1].textContent.trim());
    }
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init);
    else init();
})();
