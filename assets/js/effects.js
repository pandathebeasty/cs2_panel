(function () {
    'use strict';

    var root = document.documentElement;
    var preferenceKey = 'cs2-panel-effects';
    var automaticKey = 'cs2-panel-auto-effects';
    var modes = ['auto', 'full', 'reduced'];
    var preference = 'auto';
    var automatic = 'full';
    var frame = null;
    var startTimer = null;
    var stopTimer = null;
    var lastSample = -Infinity;

    try {
        var saved = localStorage.getItem(preferenceKey);
        if (saved !== null && modes.indexOf(saved) < 0) {
            console.error('[CS2 Panel] Invalid visual effects preference; using automatic effects.');
        } else if (saved !== null) {
            preference = saved;
        }
        if (sessionStorage.getItem(automaticKey) === 'reduced') automatic = 'reduced';
    } catch (error) {
        console.error('[CS2 Panel] Visual effects preference could not be read:', error);
    }

    function effectiveMode() {
        return preference === 'auto' ? automatic : preference;
    }

    function syncControls() {
        document.querySelectorAll('[data-effects-toggle]').forEach(function (button) {
            var current = preference === 'auto' && automatic === 'reduced' ? 'autoReduced' : preference;
            var next = modes[(modes.indexOf(preference) + 1) % modes.length];
            var label = button.dataset.effectsHint
                .replace('{current}', button.dataset[current + 'Label'])
                .replace('{next}', button.dataset[next + 'Label']);
            button.title = label;
            button.setAttribute('aria-label', label);
            button.dataset.preference = preference;
            button.dataset.effective = effectiveMode();
            button.querySelector('[data-effects-indicator]').textContent = preference === 'auto' ? 'Auto'
                : preference === 'full' ? 'FX' : 'Lite';
        });
    }

    function apply(notify) {
        root.dataset.effectsPreference = preference;
        root.dataset.effects = effectiveMode();
        root.classList.toggle('effects-page-hidden', document.hidden);
        syncControls();
        if (notify) {
            document.dispatchEvent(new CustomEvent('cs2:effectschange', {
                detail: { preference: preference, effective: effectiveMode() },
            }));
        }
    }

    function stopSampling() {
        if (frame !== null) cancelAnimationFrame(frame);
        clearTimeout(startTimer);
        clearTimeout(stopTimer);
        frame = null;
        startTimer = null;
        stopTimer = null;
    }

    function rememberAutomatic(value) {
        try {
            if (value === 'reduced') sessionStorage.setItem(automaticKey, value);
            else sessionStorage.removeItem(automaticKey);
        } catch (error) {
            console.error('[CS2 Panel] Automatic effects decision could not be retained:', error);
        }
    }

    function sample() {
        startTimer = null;
        if (preference !== 'auto' || automatic !== 'full' || document.hidden) return;
        lastSample = performance.now();
        var last = null;
        var elapsed = 0;
        var frames = 0;
        var slowFrames = 0;
        var slowWindows = 0;
        stopTimer = setTimeout(stopSampling, 6000);

        function measure(now) {
            frame = null;
            if (document.hidden || preference !== 'auto' || automatic !== 'full') {
                stopSampling();
                return;
            }
            if (last !== null) {
                var interval = now - last;
                if (interval > 0) {
                    elapsed += interval;
                    frames++;
                    if (interval > 34) slowFrames++;
                }
            }
            last = now;
            if (elapsed >= 1200) {
                // Two slow windows avoid downgrading for a single loading stall.
                var slow = frames >= 2 && elapsed / frames > 28 && slowFrames / frames >= .45;
                if (!slow) {
                    stopSampling();
                    return;
                }
                slowWindows++;
                if (slowWindows >= 2) {
                    automatic = 'reduced';
                    rememberAutomatic(automatic);
                    stopSampling();
                    apply(true);
                    return;
                }
                elapsed = 0;
                frames = 0;
                slowFrames = 0;
            }
            frame = requestAnimationFrame(measure);
        }
        frame = requestAnimationFrame(measure);
    }

    function scheduleSample(delay) {
        if (document.readyState !== 'complete' || document.hidden || preference !== 'auto'
            || automatic !== 'full' || frame !== null || startTimer !== null
            || performance.now() - lastSample < 15000) return;
        startTimer = setTimeout(sample, delay);
    }

    function setPreference(value, save) {
        if (modes.indexOf(value) < 0) {
            console.error('[CS2 Panel] Invalid visual effects preference:', value);
            return;
        }
        stopSampling();
        preference = value;
        if (value === 'auto') {
            automatic = 'full';
            rememberAutomatic(automatic);
            lastSample = -Infinity;
        }
        if (save) {
            try {
                localStorage.setItem(preferenceKey, value);
            } catch (error) {
                console.error('[CS2 Panel] Visual effects preference could not be saved:', error);
            }
        }
        apply(true);
        scheduleSample(1000);
    }

    window.CS2Effects = {
        reduced: function () { return effectiveMode() === 'reduced'; },
        preference: function () { return preference; },
        setPreference: function (value) { setPreference(value, true); },
    };
    apply(false);

    document.addEventListener('click', function (event) {
        if (!(event.target instanceof Element) || !event.target.closest('[data-effects-toggle]')) return;
        event.preventDefault();
        setPreference(modes[(modes.indexOf(preference) + 1) % modes.length], true);
    });
    document.addEventListener('DOMContentLoaded', syncControls);
    window.addEventListener('load', function () { scheduleSample(1000); });
    window.addEventListener('pageshow', function () { scheduleSample(1000); });
    window.addEventListener('pagehide', stopSampling);
    window.addEventListener('scroll', function () { scheduleSample(0); }, { passive: true });
    document.addEventListener('visibilitychange', function () {
        root.classList.toggle('effects-page-hidden', document.hidden);
        if (document.hidden) stopSampling();
        else scheduleSample(1000);
    });
    window.addEventListener('storage', function (event) {
        if (event.key === preferenceKey && event.storageArea === localStorage) {
            setPreference(event.newValue === null ? 'auto' : event.newValue, false);
        }
    });
})();
