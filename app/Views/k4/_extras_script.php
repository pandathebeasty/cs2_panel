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

    document.querySelectorAll('[data-k4x-filter]').forEach(function (bar) {
        var target = document.querySelector(bar.getAttribute('data-k4x-filter'));
        if (!target) return;
        bar.addEventListener('click', function (e) {
            var b = e.target.closest('[data-f]');
            if (!b) return;
            bar.querySelectorAll('[data-f]').forEach(function (x) { x.classList.toggle('active', x === b); });
            var f = b.getAttribute('data-f');
            target.querySelectorAll('[data-state]').forEach(function (el) {
                el.hidden = f !== 'all' && el.getAttribute('data-state') !== f;
            });
        });
    });
})();
</script>
