/* CS2 Panel - UI interactions: theme, modals, RCON AJAX, mobile menu, confirmations. */
(function () {
    'use strict';

                                        
    // A dedicated fixed element that hosts the animated backgrounds. Using a real
    // element (instead of body::before) renders reliably on mobile browsers and
    // is always present, so theme previews work everywhere. Themes target
    // [data-theme="x"] .theme-fx::before / ::after.
    (function ensureThemeFx() {
        function inject() {
            if (document.querySelector('.theme-fx')) return;
            var fx = document.createElement('div');
            fx.className = 'theme-fx';
            fx.setAttribute('aria-hidden', 'true');
            var terminal = document.createElement('div');
            terminal.className = 'theme-terminal';
            var terminalTitle = document.createElement('span');
            terminalTitle.className = 'theme-terminal-title';
            terminalTitle.textContent = 'TERMINAL GREEN / VISUAL CONSOLE';
            terminal.appendChild(terminalTitle);
            [
                '$ theme.load("terminal-green")',
                '$ palette --surface matte',
                '$ layout --contrast readable',
                '// decorative background only',
            ].forEach(function (text, index) {
                var line = document.createElement('span');
                line.className = 'theme-terminal-line';
                line.style.setProperty('--terminal-line', String(index));
                line.textContent = text;
                terminal.appendChild(line);
            });
            var cursor = document.createElement('span');
            cursor.className = 'theme-terminal-cursor';
            cursor.textContent = '> _';
            terminal.appendChild(cursor);
            fx.appendChild(terminal);
            document.body.insertBefore(fx, document.body.firstChild);
        }
        if (document.body) { inject(); }
        else { document.addEventListener('DOMContentLoaded', inject); }
    })();

                                                                          
    // Writes --mx/--my (viewport %) on <html> so a theme can render a glow that
    // follows the mouse. Throttled via rAF; only updates when a theme that opts
    // in ([data-theme] with cursor-based FX) is active.
    (function cursorGlow() {
        var d = document.documentElement;
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
        var pending = false, lastX = 50, lastY = 40;
        function isInteractive() {
            var t = d.getAttribute('data-theme') || '';
            return !document.hidden && !reduce.matches && d.dataset.effects !== 'reduced' && t === 'liquid-mesh';
        }
        window.addEventListener('pointermove', function (e) {
            if (!isInteractive()) return;
            lastX = (e.clientX / window.innerWidth) * 100;
            lastY = (e.clientY / window.innerHeight) * 100;
            if (!pending) {
                pending = true;
                window.requestAnimationFrame(function () {
                    if (isInteractive()) {
                        d.style.setProperty('--mx', lastX.toFixed(2) + '%');
                        d.style.setProperty('--my', lastY.toFixed(2) + '%');
                    }
                    pending = false;
                });
            }
        }, { passive: true });
    })();

                                                               
    // Rockets launch from the bottom, explode into colored sparks that fly out
    // and fall with gravity + trails. Runs only while the "fireworks" theme is
    // active; the canvas is created/destroyed as the theme changes, and it
    // pauses when the tab is hidden or reduced-motion is requested.
    (function fireworksEngine() {
        var d = document.documentElement;
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
        var canvas = null, ctx = null, raf = 0, rockets = [], sparks = [], flashes = [], lastLaunch = 0, lastFrame = 0, dpr = 1;
        var renderWidth = 1, renderHeight = 1;
        var FRAME_INTERVAL = 1000 / 30;
        var MAX_CANVAS_PIXELS = 1400000;
        var MAX_SPARKS = 480;
        // Dark-mode palette: bright colors that glow on a dark sky.
        var COLORS_DARK = ['#ffca3a', '#ff5da2', '#38b6ff', '#7cf2b8', '#8b5cf6', '#ff8c00', '#ffffff'];
        // Light-mode palette: deeper, saturated colors that stay visible on a
        // light background (no white/pale tones, which would disappear).
        var COLORS_LIGHT = ['#e6a700', '#e11d74', '#1e7fd6', '#0f9d58', '#7c3aed', '#e2551e', '#c026d3'];

        function lightMode() { return (d.getAttribute('data-mode') || 'dark') === 'light'; }
        function palette() { return lightMode() ? COLORS_LIGHT : COLORS_DARK; }

        function active() { return (d.getAttribute('data-theme') || '') === 'fireworks'; }

        function fx() { return document.querySelector('.theme-fx'); }

        function resize() {
            if (!canvas) return;
            renderWidth = Math.max(1, canvas.clientWidth || window.innerWidth);
            renderHeight = Math.max(1, canvas.clientHeight || window.innerHeight);
            dpr = Math.min(window.devicePixelRatio || 1, 1.25, Math.sqrt(MAX_CANVAS_PIXELS / (renderWidth * renderHeight)));
            canvas.width = Math.max(1, Math.floor(renderWidth * dpr));
            canvas.height = Math.max(1, Math.floor(renderHeight * dpr));
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        }

        function start() {
            if (canvas) return;
            var host = fx();
            if (!host) return;
            canvas = document.createElement('canvas');
            canvas.className = 'fw-canvas';
            host.appendChild(canvas);
            ctx = canvas.getContext('2d');
            if (!ctx) {
                console.error('[CS2 Panel] Fireworks canvas context is unavailable.');
                stop();
                return;
            }
            resize();
            window.addEventListener('resize', resize);
            rockets = []; sparks = []; flashes = []; lastLaunch = 0; lastFrame = 0;
            raf = window.requestAnimationFrame(loop);
        }

        function stop() {
            if (raf) { window.cancelAnimationFrame(raf); raf = 0; }
            window.removeEventListener('resize', resize);
            if (canvas && canvas.parentNode) canvas.parentNode.removeChild(canvas);
            canvas = null; ctx = null; rockets = []; sparks = []; flashes = [];
        }

        function launch(w, h) {
            var targetX = w * (0.15 + Math.random() * 0.7);
            var targetY = h * (0.15 + Math.random() * 0.35);
            rockets.push({
                x: targetX + (Math.random() - 0.5) * 40, y: h + 8,
                tx: targetX, ty: targetY,
                vy: -(h * 0.012 + Math.random() * h * 0.004),
                color: palette()[(Math.random() * palette().length) | 0],
                trail: [], wobble: Math.random() * Math.PI * 2
            });
        }

        function addSpark(x, y, vx, vy, color, options) {
            if (sparks.length >= MAX_SPARKS) return;
            options = options || {};
            sparks.push({
                x: x, y: y, vx: vx, vy: vy, color: color,
                life: 1,
                decay: options.decay || (0.007 + Math.random() * 0.009),
                drag: options.drag || 0.982,
                gravity: options.gravity || 0.034,
                size: options.size || (0.9 + Math.random() * 1.5),
                twinkle: options.twinkle !== false,
                crackle: !!options.crackle,
                crackled: false,
                trail: []
            });
        }

        function explode(x, y, color) {
            var typeRoll = Math.random();
            var type = typeRoll < 0.18 ? 'ring' : (typeRoll < 0.38 ? 'willow' : (typeRoll < 0.52 ? 'palm' : 'chrysanthemum'));
            var secondary = palette()[(Math.random() * palette().length) | 0];
            var n = type === 'palm' ? 28 : (type === 'ring' ? 56 : 64 + (Math.random() * 24 | 0));
            flashes.push({ x: x, y: y, life: 1, color: color, radius: type === 'palm' ? 34 : 52 });

            for (var i = 0; i < n; i++) {
                var ang = (Math.PI * 2 * i) / n;
                var spd;
                var opts = { crackle: Math.random() < 0.2 };

                if (type === 'ring') {
                    ang += Math.random() * 0.045;
                    spd = 3.7 + Math.random() * 0.35;
                    opts.decay = 0.009 + Math.random() * 0.004;
                } else if (type === 'willow') {
                    ang += (Math.random() - 0.5) * 0.12;
                    spd = 2.1 + Math.sqrt(Math.random()) * 3.4;
                    opts.decay = 0.0045 + Math.random() * 0.004;
                    opts.drag = 0.986;
                    opts.gravity = 0.045;
                    opts.twinkle = false;
                } else if (type === 'palm') {
                    ang += (Math.random() - 0.5) * 0.16;
                    spd = 3.6 + Math.random() * 2.2;
                    opts.decay = 0.005 + Math.random() * 0.004;
                    opts.drag = 0.987;
                    opts.gravity = 0.04;
                    opts.size = 1.7 + Math.random() * 1.2;
                } else {
                    ang += (Math.random() - 0.5) * 0.16;
                    spd = 1.3 + Math.sqrt(Math.random()) * 4.8;
                }

                var sparkColor = Math.random() < 0.24 ? secondary : color;
                if (Math.random() < 0.1) sparkColor = lightMode() ? '#222536' : '#ffffff';
                addSpark(x, y, Math.cos(ang) * spd, Math.sin(ang) * spd, sparkColor, opts);
            }

            // Hot white core: a few short-lived, fast fragments make the
            // detonation read as an ignition instead of a circle appearing.
            for (var core = 0; core < 10; core++) {
                var coreAngle = Math.random() * Math.PI * 2;
                var coreSpeed = 1.5 + Math.random() * 4;
                addSpark(x, y, Math.cos(coreAngle) * coreSpeed, Math.sin(coreAngle) * coreSpeed,
                    lightMode() ? '#343746' : '#ffffff', {
                        decay: 0.025 + Math.random() * 0.02,
                        gravity: 0.025, size: 1.1, twinkle: false
                    });
            }
        }

        function loop(ts) {
            if (!ctx) return;
            if (lastFrame && ts - lastFrame < FRAME_INTERVAL - .5) {
                raf = window.requestAnimationFrame(loop);
                return;
            }
            var w = renderWidth;
            var h = renderHeight;
            var step = lastFrame ? Math.min(3, (ts - lastFrame) / 16.667) : 1;
            lastFrame = ts;

            // Fade the previous frame slightly for nice trails. The fade color
            // follows the resolved theme mode (data-mode on <html>): a dark wash
            // on dark themes, a light wash on light themes — so the light/dark/
            // mode toggle changes the fireworks backdrop.
            ctx.globalCompositeOperation = 'source-over';
            var isLight = lightMode();
            var fade = 1 - Math.pow(1 - (isLight ? .23 : .16), step);
            ctx.fillStyle = (isLight ? 'rgba(205,213,222,' : 'rgba(5,5,15,') + fade + ')';
            ctx.fillRect(0, 0, w, h);
            // 'lighter' makes bright particles glow on dark; on light we keep
            // 'source-over' so the deeper colors stay opaque and readable.
            ctx.globalCompositeOperation = isLight ? 'source-over' : 'lighter';

            // Launch a new rocket periodically.
            if (ts - lastLaunch > 760 + Math.random() * 900) {
                lastLaunch = ts;
                launch(w, h);
                if (Math.random() < 0.28) launch(w, h); // occasional finale pair
            }

            // Rockets.
            for (var i = rockets.length - 1; i >= 0; i--) {
                var r = rockets[i];
                r.trail.push({ x: r.x, y: r.y });
                if (r.trail.length > 6) r.trail.shift();
                r.wobble += 0.08 * step;
                r.x += ((r.tx - r.x) * 0.04 + Math.sin(r.wobble) * 0.12) * step;
                r.y += r.vy * step;
                r.vy += 0.05 * step; // slight deceleration

                if (r.trail.length > 1) {
                    ctx.save();
                    ctx.globalAlpha = 0.55;
                    ctx.strokeStyle = r.color;
                    ctx.lineWidth = 1.4;
                    ctx.beginPath();
                    ctx.moveTo(r.trail[0].x, r.trail[0].y);
                    for (var trailIndex = 1; trailIndex < r.trail.length; trailIndex++) {
                        ctx.lineTo(r.trail[trailIndex].x, r.trail[trailIndex].y);
                    }
                    ctx.stroke();
                    ctx.restore();
                }
                ctx.fillStyle = r.color;
                ctx.beginPath(); ctx.arc(r.x, r.y, 2.2, 0, Math.PI * 2); ctx.fill();
                if (r.y <= r.ty || r.vy >= 0) {
                    explode(r.x, r.y, r.color);
                    rockets.splice(i, 1);
                }
            }

            // Brief radial ignition flashes behind the sparks.
            for (var flashIndex = flashes.length - 1; flashIndex >= 0; flashIndex--) {
                var flash = flashes[flashIndex];
                flash.life -= 0.085 * step;
                if (flash.life <= 0) { flashes.splice(flashIndex, 1); continue; }
                var radius = flash.radius * (1.15 - flash.life * 0.15);
                var glow = ctx.createRadialGradient(flash.x, flash.y, 0, flash.x, flash.y, radius);
                glow.addColorStop(0, isLight ? 'rgba(40,42,55,' + (flash.life * 0.42) + ')' : 'rgba(255,255,255,' + (flash.life * 0.9) + ')');
                glow.addColorStop(0.2, flash.color);
                glow.addColorStop(1, 'rgba(0,0,0,0)');
                ctx.save(); ctx.globalAlpha = flash.life * 0.7; ctx.fillStyle = glow;
                ctx.beginPath(); ctx.arc(flash.x, flash.y, radius, 0, Math.PI * 2); ctx.fill(); ctx.restore();
            }

            // Sparks.
            for (var j = sparks.length - 1; j >= 0; j--) {
                var s = sparks[j];
                s.trail.push({ x: s.x, y: s.y });
                if (s.trail.length > 4) s.trail.shift();
                s.vx *= Math.pow(s.drag, step); s.vy *= Math.pow(s.drag, step); s.vy += s.gravity * step;
                s.x += s.vx * step; s.y += s.vy * step; s.life -= s.decay * step;
                if (s.life <= 0) { sparks.splice(j, 1); continue; }

                if (s.crackle && !s.crackled && s.life < 0.38 && Math.random() < 0.08) {
                    s.crackled = true;
                    for (var crack = 0; crack < 5; crack++) {
                        var crackAngle = Math.random() * Math.PI * 2;
                        var crackSpeed = 0.4 + Math.random() * 1.2;
                        addSpark(s.x, s.y, Math.cos(crackAngle) * crackSpeed, Math.sin(crackAngle) * crackSpeed,
                            s.color, { decay: 0.035 + Math.random() * 0.025, gravity: 0.02, size: 0.8, twinkle: false });
                    }
                }

                var alpha = Math.max(0, s.life) * (s.twinkle && Math.random() < 0.08 ? 0.28 : 1);
                ctx.globalAlpha = alpha * 0.6;
                ctx.strokeStyle = s.color;
                ctx.lineWidth = Math.max(0.55, s.size * 0.72);
                ctx.beginPath();
                var trailStart = s.trail[0] || s;
                ctx.moveTo(trailStart.x, trailStart.y);
                ctx.lineTo(s.x, s.y);
                ctx.stroke();
                ctx.globalAlpha = alpha;
                ctx.fillStyle = s.color;
                ctx.beginPath(); ctx.arc(s.x, s.y, s.size, 0, Math.PI * 2); ctx.fill();
            }
            ctx.globalAlpha = 1;

            raf = window.requestAnimationFrame(loop);
        }

        function sync() {
            var shouldRun = active() && !reduce.matches && !document.hidden
                && d.dataset.effects !== 'reduced' && !document.querySelector('.skins-app');
            if (shouldRun) { start(); } else { stop(); }
        }

        // React to theme changes (data-theme attribute on <html>).
        var mo = new MutationObserver(sync);
        mo.observe(d, { attributes: true, attributeFilter: ['data-theme', 'data-effects'] });
        document.addEventListener('visibilitychange', sync);
        reduce.addEventListener('change', sync);
        // FX element may be injected slightly after this runs.
        if (document.body) { setTimeout(sync, 0); }
        else { document.addEventListener('DOMContentLoaded', function () { setTimeout(sync, 0); }); }
        window.CS2Fireworks = { sync: sync };
    })();

                                                                                      
    var MODES = ['dark', 'light'];
    var CUSTOM_STYLE_ID = 'custom-theme-style';

    // Theme state lives on the SERVER (window.CS2_THEME), shared across devices.
    // We keep a local mirror that starts from the server values.
    var THEME = window.CS2_THEME || { name: 'default', mode: 'dark', accent: '', custom: {} };
    THEME.mode = THEME.mode === 'light' ? 'light' : 'dark';
    function getTheme() { return THEME.name || 'default'; }
    function getMode() { return THEME.mode === 'light' ? 'light' : 'dark'; }
    function getAccent() { return THEME.accent || ''; }

    function customThemes() { return THEME.custom || {}; }

    // Persist the current theme state to the server so it applies for everyone,
    // on every device. Fire-and-forget; the UI already reflects the change.
    function persist(extra) {
        if (document.body.classList.contains('install-body')) {
            localStorage.setItem('theme', getTheme());
            localStorage.setItem('mode', getMode());
            localStorage.setItem('accent', getAccent());
            if (extra && extra.custom !== undefined) localStorage.setItem('customThemes', JSON.stringify(customThemes()));
            return Promise.resolve();
        }
        var base = (window.CS2_BASE || '');
        var body = new URLSearchParams();
        body.set('name', THEME.name || 'default');
        body.set('mode', getMode());
        body.set('accent', THEME.accent || '');
        if (extra && extra.custom !== undefined) { body.set('custom', JSON.stringify(THEME.custom || {})); }
        if (window.CS2_CSRF) { body.set('csrf', window.CS2_CSRF); }
        return fetch(base + '/settings/theme', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
            body: body.toString(),
            credentials: 'same-origin'
        }).catch(function () {});
    }

    // Inject CSS for a custom theme: both dark and light variants via [data-mode].
    function applyCustom(name) {
        var t = customThemes()[name];
        var el = document.getElementById(CUSTOM_STYLE_ID);
        if (!t) { if (el) el.remove(); return; }
        if (!el) { el = document.createElement('style'); el.id = CUSTOM_STYLE_ID; document.head.appendChild(el); }
        function block(sel, vars) {
            var css = sel + '{'; Object.keys(vars).forEach(function (k) { css += '--' + k + ':' + vars[k] + ';'; }); return css + '}';
        }
        var css = '';
        if (t.dark)  css += block('[data-theme="__custom"][data-mode="dark"]', t.dark);
        if (t.light) css += block('[data-theme="__custom"][data-mode="light"]', t.light);
        if (t.shared) css += block('[data-theme="__custom"]', t.shared);
        el.textContent = css;
    }

                                                                                
    // Previews leave the server-backed theme unchanged; reloading restores it.
    function applyValues(theme, mode, accent, persist) {
        var d = document.documentElement;
        d.setAttribute('data-theme-name', theme);
        d.setAttribute('data-mode-pref', mode);
        d.setAttribute('data-mode', mode);

        if (theme.indexOf('custom:') === 0 && customThemes()[theme.slice(7)]) {
            applyCustom(theme.slice(7));
            d.setAttribute('data-theme', '__custom');
        } else if (theme.indexOf('custom:') === 0) {
            // custom theme was deleted/missing -> fall back to default cleanly
            var elx = document.getElementById(CUSTOM_STYLE_ID); if (elx) elx.remove();
            theme = 'default';
            d.setAttribute('data-theme', 'default');
        } else {
            var el = document.getElementById(CUSTOM_STYLE_ID);
            if (el) el.remove();
            d.setAttribute('data-theme', theme);
        }
        // The user accent applies ONLY to the Default theme.
        if (theme === 'default' && accent) {
            d.style.setProperty('--accent', accent);
            d.style.setProperty('--accent-2', accent);
        } else {
            d.style.removeProperty('--accent');
            d.style.removeProperty('--accent-2');
        }
    }

                                                
    function apply() {
        applyValues(getTheme(), getMode(), getAccent(), true);
    }

    function animate() {
        document.body.classList.add('theme-anim');
        window.clearTimeout(window._themeT);
        window._themeT = window.setTimeout(function () { document.body.classList.remove('theme-anim'); }, 550);
    }

    // Pending name/accent selection, discarded on commit, restore or mode change.
    var previewState = null; // { theme, accent } or null

    window.CS2Theme = {
        // Persisting setters — update the server-backed state + save for everyone.
        setTheme: function (name) { THEME.name = name; previewState = null; animate(); apply(); persist(); },
        setMode: function (mode) {
            if (MODES.indexOf(mode) < 0) {
                console.error('[CS2 Panel] Theme mode must be dark or light.');
                return;
            }
            THEME.mode = mode; previewState = null; animate(); apply(); persist();
            updateThemeLabel();
        },
        setAccent: function (hex) { THEME.accent = hex; apply(); persist(); },
        getTheme: getTheme, getMode: getMode, getAccent: getAccent,
        customThemes: customThemes,

                                                                       
                                                                 
        previewTheme: function (name) { previewState = { theme: name, accent: getAccent() }; animate(); applyValues(name, getMode(), getAccent(), false); },
        previewAccent: function (hex) { var th = previewState ? previewState.theme : getTheme(); previewState = { theme: th, accent: hex }; applyValues(th, getMode(), hex, false); },
        // Preview an explicit combination (used to restore the pending selection).
        preview: function (theme, accent) { previewState = { theme: theme, accent: accent }; animate(); applyValues(theme, getMode(), accent, false); },
        // Commit a pending selection to the server (called on Save).
        commit: function (theme, accent) {
            if (typeof theme === 'string' && theme !== '') { THEME.name = theme; }
            if (typeof accent === 'string' && accent !== '') { THEME.accent = accent; }
            previewState = null;
            apply();
            persist();
        },
        // Re-apply the saved (server) values, discarding any preview.
        restore: function () { previewState = null; apply(); },
        saveCustom: function (name, data) {
            var all = customThemes(); all[name] = data;
            THEME.custom = all;
            persist({ custom: true });
        },
        removeCustom: function (name) {
            var all = customThemes(); delete all[name];
            THEME.custom = all;
            persist({ custom: true });
        }
    };

    apply();

    // Switching mode keeps the selected theme.
    var themeBtn = document.querySelector('.theme-toggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var next = MODES[(MODES.indexOf(getMode()) + 1) % MODES.length];
            window.CS2Theme.setMode(next);
            updateThemeLabel();
        });
        updateThemeLabel();
    }
    function updateThemeLabel() {
        if (!themeBtn) return;
        themeBtn.setAttribute('data-pref', getMode());
        themeBtn.title = 'Mode: ' + getMode();
    }

                                                          
    (function () {
        var el = document.getElementById('topbar-clock');
        if (!el) return;
        var timeEl = el.querySelector('.clock-time');
        var dateEl = el.querySelector('.clock-date');
        function pad(n) { return n < 10 ? '0' + n : '' + n; }
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        function tick() {
            var d = new Date();
            if (timeEl) timeEl.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes());
            if (dateEl) dateEl.textContent = pad(d.getDate()) + ' ' + months[d.getMonth()];
        }
        tick();
        window.setInterval(tick, 1000 * 20);
        // Re-sync on the minute boundary so it doesn't drift visually.
        window.setTimeout(function () { tick(); window.setInterval(tick, 60000); }, (60 - new Date().getSeconds()) * 1000);
    })();

                       
    function openModal(id) {
        var m = document.getElementById(id);
        if (!m || m.classList.contains('open')) return;
        m.classList.add('open');
        // Profile can open from the user dropdown, which closes with the modal.
        document.querySelectorAll('.dropdown.open').forEach(function (o) { o.classList.remove('open'); });
    }
    var sanctionDetailsOpener = null;
    function closeModal(el) {
        var overlay = el.closest('.modal-overlay');
        if (overlay) overlay.classList.remove('open');
        if (overlay && overlay.id === 'modal-sanction-details' && sanctionDetailsOpener) {
            if (sanctionDetailsOpener.isConnected) {
                sanctionDetailsOpener.focus();
            } else {
                var results = document.querySelector('[data-live-search-results]');
                if (results) results.focus();
            }
            sanctionDetailsOpener = null;
        }
    }

    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-modal]');
        if (opener) {
            // Prevent accidental form submission and duplicate document click handling.
            e.preventDefault();
            e.stopImmediatePropagation();
            if (opener.hasAttribute('data-sanction-details')) {
                var detailsTemplate = opener.parentElement.querySelector('[data-sanction-details-template]');
                var detailsContent = document.querySelector('#modal-sanction-details [data-sanction-details-content]');
                if (!detailsTemplate || !detailsContent) {
                    throw new Error('Sanction details template or dialog is missing.');
                }
                detailsContent.replaceChildren(detailsTemplate.content.cloneNode(true));
                detailsContent.scrollTop = 0;
                sanctionDetailsOpener = opener;
            }
            openModal(opener.getAttribute('data-modal'));
            if (opener.hasAttribute('data-sanction-details')) {
                document.querySelector('#modal-sanction-details .modal').scrollTop = 0;
                document.querySelector('#modal-sanction-details .modal-close').focus();
            }
            // prefill fields from data-attributes (data-set-<field>)
            var target = document.getElementById(opener.getAttribute('data-modal'));
            if (target) {
                Object.keys(opener.dataset).forEach(function (key) {
                    if (key.indexOf('set') === 0) {
                        var field = key.slice(3).toLowerCase();
                        var input = target.querySelector('[name="' + field + '"]');
                        if (input) input.value = opener.dataset[key];
                    }
                });
            }
            return;
        }
        // Close only via the X / Cancel buttons. Clicking the backdrop is
        // deliberately ignored so a stray click next to the dialog doesn't
        // discard a half-filled form.
        var closeBtn = e.target.closest('.modal-close');
        if (closeBtn) {
            closeModal(closeBtn);
        }
    });

    document.querySelectorAll('form[data-bulk-kind]').forEach(function (form) {
        var kind = form.dataset.bulkKind;
        var results = document.querySelector('[data-live-search-results="' + kind + '"]');
        var openers = document.querySelectorAll('[data-bulk-open="' + kind + '"]');
        var counters = document.querySelectorAll('[data-bulk-count="' + kind + '"]');
        var list = form.querySelector('[data-bulk-list]');
        var apply = Array.from(form.querySelectorAll('[data-bulk-apply]'));
        var confirm = form.elements.confirm_bulk;
        var submit = form.querySelector('[data-bulk-submit]');
        var error = form.querySelector('[data-bulk-error]');
        var action = form.querySelector('[data-bulk-action]');
        var reasonLabel = form.querySelector('[data-bulk-reason-label]');
        function rows() { return Array.from(document.querySelectorAll('[data-bulk-row="' + kind + '"]')); }
        function selected() { return rows().filter(function (row) { return row.checked; }); }
        function actionMode() { return action.selectedOptions[0].dataset.bulkActionMode; }
        function hasEdits() {
            return apply.some(function (checkbox) { return !checkbox.disabled && checkbox.checked; });
        }
        function sync(resetConfirmation) {
            var checked = selected();
            var mode = actionMode();
            var currentRows = rows();
            var master = document.querySelector('[data-bulk-all="' + kind + '"]');
            var pending = results && results.inert;
            if (resetConfirmation) confirm.checked = false;
            if (master) {
                master.checked = checked.length > 0 && checked.length === currentRows.length;
                master.indeterminate = checked.length > 0 && checked.length < currentRows.length;
            }
            counters.forEach(function (counter) { counter.textContent = checked.length; });
            openers.forEach(function (button) { button.disabled = pending || checked.length === 0; });
            list.replaceChildren();
            currentRows.forEach(function (row) {
                row.closest('tr').classList.toggle('bulk-row-selected', row.checked);
                if (!row.checked) return;
                var item = document.createElement('li');
                item.textContent = '#' + row.value + ' — ' + row.dataset.playerName;
                list.appendChild(item);
            });
            form.querySelectorAll('[data-bulk-field-modes]').forEach(function (field) {
                field.hidden = field.dataset.bulkFieldModes.split(' ').indexOf(mode) < 0;
            });
            form.querySelectorAll('[data-bulk-hint]').forEach(function (hint) {
                hint.hidden = hint.dataset.bulkHint !== mode;
            });
            reasonLabel.textContent = mode === 'lift' ? reasonLabel.dataset.liftLabel : reasonLabel.dataset.editLabel;
            apply.forEach(function (checkbox) {
                checkbox.disabled = checkbox.closest('[data-bulk-field-modes]').hidden;
                form.querySelector('[data-bulk-value="' + checkbox.dataset.bulkApply + '"]').disabled = checkbox.disabled || !checkbox.checked;
            });
            submit.disabled = pending || checked.length === 0 || (mode === 'edit' && !hasEdits()) || !confirm.checked;
            error.hidden = true;
        }
        document.addEventListener('change', function (event) {
            var target = event.target;
            if (target.matches('[data-bulk-all="' + kind + '"]')) {
                rows().forEach(function (row) { row.checked = target.checked; });
                sync(true);
            } else if (target.matches('[data-bulk-row="' + kind + '"]')) {
                sync(true);
            }
        });
        document.addEventListener('cs2:search-pending', function (event) {
            if (event.detail.key !== kind) return;
            rows().forEach(function (row) { row.checked = false; });
            sync(true);
        });
        document.addEventListener('cs2:search-updated', function (event) {
            if (event.detail.key === kind) sync(true);
        });
        openers.forEach(function (button) { button.addEventListener('click', function () { sync(true); }); });
        form.addEventListener('change', function (event) { sync(event.target !== confirm); });
        form.addEventListener('input', function (event) {
            if (event.target !== confirm && !event.target.matches('[data-bulk-apply]')) sync(true);
        });
        form.addEventListener('submit', function (event) {
            if (!selected().length || (actionMode() === 'edit' && !hasEdits())) {
                event.preventDefault();
                error.textContent = !selected().length ? form.dataset.selectionError : form.dataset.fieldsError;
                error.hidden = false;
            }
        });
        window.addEventListener('pageshow', function () { sync(true); });
        sync(true);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(function (m) { closeModal(m); });
        }
        if (e.key === 'Tab') {
            var detailsDialog = document.querySelector('#modal-sanction-details.open .modal');
            if (!detailsDialog) return;
            var focusable = Array.from(detailsDialog.querySelectorAll('button, a[href], [tabindex="0"]'));
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });

    (function () {
        var flashes = document.querySelectorAll('.flash');
        flashes.forEach(function (el) {
            // Let the user dismiss early by clicking.
            el.style.cursor = 'pointer';
            el.title = '';
            function dismiss() {
                if (el._dismissed) return;
                el._dismissed = true;
                el.classList.add('flash-out');
                window.setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 400);
            }
            el.addEventListener('click', dismiss);
            window.setTimeout(dismiss, 5000);
        });
    })();

                                                      
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.matches('[data-confirm]')) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        }
    });

                                                                   
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-copy');
        if (!btn) return;
        var sel = btn.getAttribute('data-copy-target');
        var src = sel ? document.querySelector(sel) : null;
        if (!src) return;
        var text = src.textContent || '';
        var done = function () {
            var original = btn.getAttribute('data-label') || btn.textContent;
            btn.setAttribute('data-label', original);
            btn.classList.add('copied');
            btn.textContent = '\u2713';
            window.setTimeout(function () {
                btn.classList.remove('copied');
                btn.textContent = btn.getAttribute('data-label');
            }, 1400);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () {});
        } else {
            var r = document.createRange(); r.selectNodeContents(src);
            var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
            try { document.execCommand('copy'); done(); } catch (err) {}
            s.removeAllRanges();
        }
    });

                            
    var toggle = document.querySelector('.menu-toggle');
    var sidebar = document.querySelector('.sidebar');
    var backdrop = document.querySelector('.sidebar-backdrop');
    function setSidebar(open) {
        if (sidebar) {
            sidebar.classList.toggle('open', open);
            if (open) sidebar.scrollTop = 0;
        }
        if (backdrop) backdrop.classList.toggle('show', open);
    }
    if (toggle && sidebar) {
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            setSidebar(!sidebar.classList.contains('open'));
        });
    }
    if (backdrop) backdrop.addEventListener('click', function () { setSidebar(false); });

                                                         
    function toggleSidebarCollapsed() {
        var collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
        localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
    }
    var collapseBtn = document.querySelector('.collapse-toggle');
    if (collapseBtn) {
        collapseBtn.addEventListener('click', function (e) { e.stopPropagation(); toggleSidebarCollapsed(); });
    }
    // When collapsed, the logo is the only brand element shown and doubles as the
    // "expand" control (the toggle button is hidden to keep the 74px rail clean).
    var brandLogo = document.querySelector('.brand img');
    if (brandLogo) {
        brandLogo.addEventListener('click', function () {
            if (document.documentElement.classList.contains('sidebar-collapsed')) {
                toggleSidebarCollapsed();
            }
        });
    }

                                                                                     
    (function () {
        var nameEl = document.querySelector('.brand .name');
        if (!nameEl) return;
        function fit() {
            // Skip while collapsed (the name is hidden then).
            if (document.documentElement.classList.contains('sidebar-collapsed')) return;
            var max = 16, min = 10;
            nameEl.style.setProperty('--brand-fs', max + 'px');
            var size = max;
            // Shrink until the text fits its container (no horizontal overflow).
            while (size > min && nameEl.scrollWidth > nameEl.clientWidth) {
                size -= 0.5;
                nameEl.style.setProperty('--brand-fs', size + 'px');
            }
        }
        fit();
        window.addEventListener('resize', fit);
        // Re-fit after expanding the sidebar (width changes back).
        if (collapseBtn) collapseBtn.addEventListener('click', function () { setTimeout(fit, 300); });
        if (brandLogo) brandLogo.addEventListener('click', function () { setTimeout(fit, 300); });
    })();

                                                                      
    (function () {
        var collapsed;
        try { collapsed = JSON.parse(localStorage.getItem('navCollapsed') || '{}'); }
        catch (e) { collapsed = {}; }
        if (typeof collapsed !== 'object' || collapsed === null) collapsed = {};

        document.querySelectorAll('.nav-section').forEach(function (section) {
            var id = section.getAttribute('data-section');
            var label = section.querySelector('.nav-label');
            if (collapsed[id]) section.classList.add('section-collapsed');
            if (label) {
                label.addEventListener('click', function () {
                    var isCollapsed = section.classList.toggle('section-collapsed');
                    collapsed[id] = isCollapsed;
                    localStorage.setItem('navCollapsed', JSON.stringify(collapsed));
                });
            }
        });
    })();
    // Close the mobile menu after navigating.
    if (sidebar) {
        sidebar.querySelectorAll('.nav-item').forEach(function (a) {
            a.addEventListener('click', function () {
                if (window.matchMedia('(max-width: 900px)').matches) setSidebar(false);
            });
        });
    }

                                                
    ['lang-dd', 'user-dd'].forEach(function (id) {
        var dd = document.getElementById(id);
        if (!dd) return;
        var toggle = dd.querySelector('.dropdown-toggle');
        if (toggle) {
            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                                             
                document.querySelectorAll('.dropdown.open').forEach(function (o) { if (o !== dd) o.classList.remove('open'); });
                dd.classList.toggle('open');
            });
        }
    });
    document.addEventListener('click', function () {
        document.querySelectorAll('.dropdown.open').forEach(function (o) { o.classList.remove('open'); });
    });

                                          
    document.querySelectorAll('[data-colorsync]').forEach(function (wrap) {
        var picker = wrap.querySelector('input[type=color]');
        var hex = wrap.querySelector('input[type=text]');
        if (!picker || !hex) return;
        picker.addEventListener('input', function () { hex.value = picker.value; });
        hex.addEventListener('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) picker.value = hex.value;
        });
    });

                                         
    document.querySelectorAll('.file-drop input[type=file]').forEach(function (input) {
        var label = input.closest('.file-drop').querySelector('.file-name');
        input.addEventListener('change', function () {
            label.textContent = input.files.length ? input.files[0].name : label.dataset.placeholder;
        });
    });

                        
    // Tabs may be split across multiple .tabs rows (grouped settings), so we
    // query ALL [data-tab] buttons in the host, not just the first row.
    function activateTab(host, name) {
        var buttons = host.querySelectorAll('.tabs [data-tab]');
        if (!buttons.length) return;
        var btn = host.querySelector('.tabs [data-tab="' + (window.CSS && CSS.escape ? CSS.escape(name) : name) + '"]');
        if (!btn) { // fall back to the first tab
            btn = buttons[0];
            name = btn.getAttribute('data-tab');
        }
        buttons.forEach(function (b) { b.classList.toggle('active', b === btn); });
        host.querySelectorAll('[data-tabpanel]').forEach(function (p) {
            if (p.closest('.tab-host') === host) {
                p.style.display = p.getAttribute('data-tabpanel') === name ? '' : 'none';
            }
        });
    }

    document.querySelectorAll('.tab-host').forEach(function (host) {
        var buttons = host.querySelectorAll('.tabs [data-tab]');
        if (!buttons.length) return;
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var name = btn.getAttribute('data-tab');
                activateTab(host, name);
                // remember the active tab in the URL hash so a refresh restores it
                try { history.replaceState(null, '', '#' + name); } catch (e) { location.hash = name; }
            });
        });
        // On load: restore the tab from the URL hash, otherwise activate whichever
        // button is marked .active (the first tab, which may vary by language).
        var fromHash = (location.hash || '').replace('#', '');
        if (fromHash) {
            activateTab(host, fromHash);
        } else {
            var initial = host.querySelector('.tabs [data-tab].active') || buttons[0];
            if (initial) activateTab(host, initial.getAttribute('data-tab'));
        }
    });

                          
    document.querySelectorAll('form.rcon-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var out = form.parentElement.querySelector('.rcon-out');
            var btn = form.querySelector('button[type=submit]');
            var cmd = form.querySelector('[name=command]').value.trim();
            if (!cmd) return;

            out.textContent = '> ' + cmd + '\n... running (short timeout) ...';
            btn.disabled = true;

            var data = new FormData(form);
            fetch(form.action, { method: 'POST', body: data, headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    out.textContent = json.ok
                        ? '> ' + cmd + '\n' + json.output
                        : '> ' + cmd + '\n[ERROR] ' + (json.error || 'unknown');
                })
                .catch(function () { out.textContent = '> ' + cmd + '\n[ERROR] Request failed.'; })
                .finally(function () { btn.disabled = false; });
        });
    });
})();
