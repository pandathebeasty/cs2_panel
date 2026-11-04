<?php
use App\Core\Lang;
$k4xUnits = [
    'd' => mb_substr(Lang::t('time.days'), 0, 1),
    'h' => mb_substr(Lang::t('time.hours'), 0, 1),
    'm' => mb_substr(Lang::t('time.minutes'), 0, 1),
];
?>
<script>
(function () {
    var u = <?= json_encode($k4xUnits, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var started = Date.now();
    var nodes = [].slice.call(document.querySelectorAll('[data-k4x-countdown]'));
    function fmt(s) {
        s = Math.max(0, Math.floor(s));
        var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), sec = s % 60;
        if (d > 0) return d + u.d + ' ' + h + u.h;
        if (h > 0) return h + u.h + ' ' + String(m).padStart(2, '0') + u.m;
        return m + u.m + ' ' + String(sec).padStart(2, '0') + 's';
    }
    function tick() {
        var gone = (Date.now() - started) / 1000;
        nodes.forEach(function (n) { n.textContent = fmt(+n.getAttribute('data-k4x-countdown') - gone); });
    }
    if (nodes.length) { tick(); setInterval(tick, 1000); }

    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduce) {
        document.querySelectorAll('[data-k4x-count]').forEach(function (n) {
            var to = +n.getAttribute('data-k4x-count'), t0 = null;
            if (!(to > 0)) return;
            function step(ts) {
                t0 = t0 || ts;
                var k = Math.min(1, (ts - t0) / 900);
                n.textContent = Math.round(to * (1 - Math.pow(1 - k, 3))).toLocaleString();
                if (k < 1) requestAnimationFrame(step);
            }
            requestAnimationFrame(step);
        });
    }

    // State buttons and the instant text filter share one pass per grid.
    var grids = new Map();
    function grid(sel) {
        if (!grids.has(sel)) grids.set(sel, { el: document.querySelector(sel), state: 'all', text: '' });
        return grids.get(sel);
    }
    function apply(g) {
        if (!g.el) return;
        var shown = 0;
        g.el.querySelectorAll('[data-k4x-text]').forEach(function (el) {
            var ok = (g.state === 'all' || el.getAttribute('data-state') === g.state)
                && (g.text === '' || el.getAttribute('data-k4x-text').indexOf(g.text) !== -1);
            el.hidden = !ok;
            if (ok) shown++;
        });
        var empty = g.el.querySelector('[data-k4x-find-empty]');
        if (empty) empty.hidden = shown > 0;
    }
    document.querySelectorAll('[data-k4x-filter]').forEach(function (bar) {
        var g = grid(bar.getAttribute('data-k4x-filter'));
        bar.addEventListener('click', function (e) {
            var b = e.target.closest('[data-f]');
            if (!b) return;
            bar.querySelectorAll('[data-f]').forEach(function (x) { x.classList.toggle('active', x === b); });
            g.state = b.getAttribute('data-f');
            apply(g);
        });
    });
    document.querySelectorAll('[data-k4x-find]').forEach(function (input) {
        var g = grid(input.getAttribute('data-k4x-find'));
        input.addEventListener('input', function () {
            g.text = input.value.trim().toLowerCase();
            apply(g);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && input.value !== '') { input.value = ''; g.text = ''; apply(g); }
        });
    });

    // Live player suggestions in the header search.
    var form = document.querySelector('[data-k4x-suggest]');
    var box = form && form.querySelector('.k4x-suggest');
    var field = form && form.querySelector('input[name="q"]');
    if (form && box && field) {
        var timer = null, ctrl = null, active = -1;
        function close() { box.hidden = true; box.replaceChildren(); active = -1; field.setAttribute('aria-expanded', 'false'); }
        function mark(i) {
            var items = box.querySelectorAll('a');
            if (!items.length) return;
            active = (i + items.length) % items.length;
            items.forEach(function (a, n) { a.classList.toggle('active', n === active); a.setAttribute('aria-selected', n === active ? 'true' : 'false'); });
            items[active].scrollIntoView({ block: 'nearest' });
        }
        function render(list) {
            box.replaceChildren();
            active = -1;
            if (!list.length) {
                var none = document.createElement('div');
                none.className = 'k4x-suggest-empty';
                none.textContent = form.getAttribute('data-k4x-empty');
                box.appendChild(none);
            }
            list.forEach(function (p) {
                var a = document.createElement('a');
                a.href = form.getAttribute('data-k4x-go').replace('{id}', encodeURIComponent(p.id));
                a.setAttribute('role', 'option');
                var img = document.createElement('img');
                img.src = p.avatar; img.alt = ''; img.loading = 'lazy';
                var txt = document.createElement('span');
                var name = document.createElement('strong'); name.textContent = p.name;
                var id = document.createElement('small'); id.textContent = p.id;
                txt.append(name, id);
                a.append(img, txt);
                box.appendChild(a);
            });
            box.hidden = false;
            field.setAttribute('aria-expanded', 'true');
        }
        field.addEventListener('input', function () {
            clearTimeout(timer);
            if (ctrl) ctrl.abort();
            var q = field.value.trim();
            if (q.length < 2) { close(); return; }
            timer = setTimeout(function () {
                ctrl = new AbortController();
                fetch(form.getAttribute('data-k4x-suggest') + '?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
                    credentials: 'same-origin',
                    signal: ctrl.signal
                }).then(function (r) { return r.ok ? r.json() : []; })
                  .then(function (list) { if (field.value.trim() === q) render(Array.isArray(list) ? list : []); })
                  .catch(function () {});
            }, 250);
        });
        field.addEventListener('keydown', function (e) {
            if (box.hidden) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); mark(active + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); mark(active - 1); }
            else if (e.key === 'Escape') { close(); }
            else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); location.href = box.querySelectorAll('a')[active].href; }
        });
        document.addEventListener('click', function (e) { if (!form.contains(e.target)) close(); });
    }
})();
</script>
