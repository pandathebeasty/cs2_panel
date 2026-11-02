(function () {
    'use strict';

    var hero = document.querySelector('[data-dashboard-hero]');
    if (!hero) return;
    var viewport = hero.querySelector('[data-server-viewport]');
    var slides = Array.from(hero.querySelectorAll('[data-server-slide]'));
    var dots = Array.from(hero.querySelectorAll('[data-server-dot]'));
    var status = hero.querySelector('[data-server-status]');
    var currentMap = hero.querySelector('[data-current-map]');
    var currentName = hero.querySelector('[data-current-server]');
    var currentPlayers = hero.querySelector('[data-current-players]');
    var currentAddress = hero.querySelector('[data-current-address]');
    var connect = hero.querySelector('[data-server-connect]');
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    var snapshots = new Map();
    var requests = new Map();
    var active = 0;
    var refreshTimer = null;
    var scrollTimer = null;
    var wheelUntil = 0;

    function paint(index, snapshot) {
        var slide = slides[index];
        var image = slide.querySelector('[data-server-map]');
        var data = snapshot && snapshot.data;
        var online = data && data.online;
        var map = online ? data.map_label : '—';
        var players = online && data.players !== null && data.max_players !== null
            ? data.players + ' / ' + data.max_players : '—';
        slide.querySelectorAll('[data-server-map-name]').forEach(function (label) {
            label.textContent = map;
            label.title = map;
        });
        slide.querySelectorAll('[data-server-players]').forEach(function (label) { label.textContent = players; });
        if (online && data.map_image) {
            if (image.getAttribute('src') !== data.map_image) image.src = data.map_image;
            image.hidden = false;
        } else {
            image.hidden = true;
            image.removeAttribute('src');
        }
        if (index !== active) return;
        currentName.textContent = data && data.name ? data.name : slide.dataset.serverName;
        currentName.title = currentName.textContent;
        currentMap.textContent = map;
        currentMap.title = map;
        if (currentPlayers) currentPlayers.textContent = players;
        var state = data ? (online ? 'online' : 'unavailable') : (snapshot ? 'unavailable' : 'loading');
        hero.dataset.serverState = state;
        status.textContent = state === 'online' ? hero.dataset.onlineLabel
            : state === 'loading' ? hero.dataset.loadingLabel : hero.dataset.errorLabel;
    }

    async function load(index, force) {
        var slide = slides[index];
        if (!slide.dataset.infoUrl) return;
        var snapshot = snapshots.get(index);
        if (!force && snapshot && Date.now() - snapshot.time < 30000) {
            paint(index, snapshot);
            return;
        }
        if (requests.has(index)) return;
        var controller = new AbortController();
        var timedOut = false;
        var requestTimer = setTimeout(function () {
            timedOut = true;
            controller.abort();
        }, 7000);
        requests.set(index, controller);
        try {
            var response = await fetch(slide.dataset.infoUrl, {
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            });
            if (!response.ok) throw new Error('Server query returned HTTP ' + response.status);
            var data = await response.json();
            if (data.ok !== true || typeof data.online !== 'boolean' || typeof data.name !== 'string'
                || typeof data.map_label !== 'string' || !(data.map_image === null || typeof data.map_image === 'string')
                || !(data.players === null || Number.isInteger(data.players) && data.players >= 0)
                || !(data.max_players === null || Number.isInteger(data.max_players) && data.max_players >= 0)) {
                throw new Error('Invalid live server response.');
            }
            if (data.map_image !== null) {
                var url = new URL(data.map_image, location.href);
                if (url.origin !== location.origin) throw new Error('Map image is not a local panel asset.');
            }
            snapshot = { data: data, time: Date.now() };
            snapshots.set(index, snapshot);
            paint(index, snapshot);
        } catch (error) {
            if (error.name === 'AbortError' && !timedOut) return;
            console.error('[CS2 Panel] Live server query failed:', error);
            snapshot = { data: null, time: Date.now() };
            snapshots.set(index, snapshot);
            paint(index, snapshot);
        } finally {
            clearTimeout(requestTimer);
            if (requests.get(index) === controller) requests.delete(index);
        }
    }

    function activate(index) {
        active = index;
        slides.forEach(function (slide, position) {
            slide.setAttribute('aria-hidden', String(position !== index));
        });
        dots.forEach(function (dot, position) {
            dot.setAttribute('aria-current', String(position === index));
        });
        requests.forEach(function (controller, position) {
            if (position !== index) {
                controller.abort();
                requests.delete(position);
            }
        });
        var address = slides[index].dataset.connectUrl;
        if (currentAddress) currentAddress.textContent = slides[index].dataset.serverAddress || '—';
        if (address) {
            connect.href = address;
            connect.removeAttribute('aria-disabled');
            connect.removeAttribute('tabindex');
        } else {
            connect.removeAttribute('href');
            connect.setAttribute('aria-disabled', 'true');
            connect.setAttribute('tabindex', '-1');
        }
        if (slides[index].dataset.infoUrl) {
            paint(index, snapshots.get(index));
            load(index, false);
        }
    }

    function move(index) {
        index = Math.max(0, Math.min(slides.length - 1, index));
        activate(index);
        viewport.scrollTo({ left: index * viewport.clientWidth, behavior: reducedMotion.matches ? 'instant' : 'smooth' });
    }
    dots.forEach(function (dot) {
        dot.addEventListener('click', function () { move(Number(dot.dataset.serverDot)); });
    });
    viewport.addEventListener('scroll', function () {
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(function () {
            var index = Math.max(0, Math.min(slides.length - 1, Math.round(viewport.scrollLeft / viewport.clientWidth)));
            if (index !== active) activate(index);
        }, 120);
    }, { passive: true });
    viewport.addEventListener('keydown', function (event) {
        var next = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? active + 1
            : event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? active - 1
            : event.key === 'Home' ? 0 : event.key === 'End' ? slides.length - 1 : null;
        if (next === null) return;
        event.preventDefault();
        move(next);
    });
    hero.addEventListener('wheel', function (event) {
        if (slides.length < 2 || event.ctrlKey || event.target.closest('a, button, input, select, .portal-hero-note')) return;
        var delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;
        if (!delta) return;
        var next = active + (delta > 0 ? 1 : -1);
        if (next < 0 || next >= slides.length) return;
        event.preventDefault();
        if (Date.now() < wheelUntil) return;
        wheelUntil = Date.now() + 600;
        move(next);
    }, { passive: false });
    slides.forEach(function (slide) {
        slide.querySelector('[data-server-map]').addEventListener('error', function (event) {
            console.error('[CS2 Panel] Server map image failed to load.');
            event.currentTarget.hidden = true;
        });
    });
    new ResizeObserver(function () {
        viewport.scrollTo({ left: active * viewport.clientWidth, behavior: 'instant' });
    }).observe(viewport);
    function start() {
        if (refreshTimer !== null) return;
        activate(active);
        refreshTimer = setInterval(function () {
            if (!document.hidden) load(active, true);
        }, 30000);
    }
    window.addEventListener('pagehide', function () {
        clearInterval(refreshTimer);
        refreshTimer = null;
        requests.forEach(function (controller) { controller.abort(); });
        requests.clear();
    });
    window.addEventListener('pageshow', start);
    start();
})();
