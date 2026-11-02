(function () {
    'use strict';

    document.querySelectorAll('form[data-live-search]').forEach(function (form) {
        var key = form.dataset.liveSearch;
        var results = document.querySelector('[data-live-search-results="' + key + '"]');
        var feedback = document.querySelector('[data-live-search-feedback="' + key + '"]');
        var input = form.querySelector('[name="search"]');
        if (!results || !feedback || !input) {
            console.error('[CS2 Panel] Live search controls are incomplete:', key);
            return;
        }
        var message = feedback.querySelector('[data-search-message]');
        var retry = feedback.querySelector('[data-search-retry]');
        var fallback = feedback.querySelector('[data-search-fallback]');
        var controls = Array.from(form.elements).filter(function (field) { return field.name && field.type !== 'submit'; });
        var resultActions = document.querySelectorAll('[data-live-search-enabled="' + key + '"]');
        var initialValues = new Map(controls.map(function (field) { return [field.name, field.value]; }));
        var timer = null;
        var request = null;
        var revision = 0;
        var composing = false;
        var appliedUrl = new URL(location.href);
        var retryRequest = null;

        input.type = 'search';
        input.autocomplete = 'off';
        if (!input.hasAttribute('aria-label')) input.setAttribute('aria-label', feedback.dataset.searchLabel);
        input.setAttribute('aria-controls', results.id);

        function emit(name) {
            document.dispatchEvent(new CustomEvent(name, { detail: { key: key, results: results } }));
        }

        function cancel() {
            clearTimeout(timer);
            timer = null;
            revision++;
            if (request) request.abort();
            request = null;
        }

        function formUrl() {
            var url = new URL(form.action || location.href, location.href);
            url.search = location.search;
            var fields = new FormData(form);
            controls.forEach(function (field) { url.searchParams.delete(field.name); });
            fields.forEach(function (value, name) { url.searchParams.append(name, value); });
            url.searchParams.delete('page');
            return url;
        }

        function restoreControls(url) {
            controls.forEach(function (field) {
                if (url.searchParams.has(field.name)) {
                    field.value = url.searchParams.get(field.name);
                } else if (field.name === 'search' || (field instanceof HTMLSelectElement
                    && Array.from(field.options).some(function (option) { return option.value === ''; }))) {
                    field.value = '';
                } else {
                    field.value = initialValues.get(field.name);
                }
            });
        }

        function pending() {
            feedback.hidden = false;
            feedback.dataset.state = 'loading';
            message.setAttribute('role', 'status');
            message.textContent = feedback.dataset.loadingLabel;
            retry.hidden = true;
            fallback.hidden = true;
            results.inert = true;
            results.setAttribute('aria-busy', 'true');
            resultActions.forEach(function (button) { button.disabled = true; });
            emit('cs2:search-pending');
        }

        async function load(url, historyMode, focusResults) {
            cancel();
            var version = revision;
            var controller = new AbortController();
            request = controller;
            retryRequest = { url: url, historyMode: historyMode, focusResults: focusResults };
            pending();
            var timedOut = false;
            var timeout = setTimeout(function () {
                timedOut = true;
                controller.abort();
            }, 12000);
            try {
                var response = await fetch(url.href, {
                    headers: { 'X-Panel-Search': key, 'X-Requested-With': 'fetch', 'Accept': 'text/html' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                });
                if (version !== revision) return;
                if (!response.ok || response.redirected || response.headers.get('X-Panel-Search') !== key) {
                    throw new Error('Live search returned an unavailable page (HTTP ' + response.status + ').');
                }
                var html = await response.text();
                if (version !== revision) return;
                var page = new DOMParser().parseFromString(html, 'text/html');
                var replacement = page.querySelector('[data-live-search-results="' + key + '"]');
                var meta = replacement && replacement.querySelector('[data-live-search-meta]');
                var total = meta ? Number(meta.dataset.total) : NaN;
                var currentPage = meta ? Number(meta.dataset.page) : NaN;
                if (!replacement || !Number.isSafeInteger(total) || total < 0
                    || !Number.isSafeInteger(currentPage) || currentPage < 1) {
                    throw new Error('Live search returned invalid results.');
                }
                // Only the result region is replaced; forms, focus and modal state stay in place.
                replacement.querySelectorAll('script').forEach(function (script) { script.remove(); });
                results.replaceChildren.apply(results, Array.from(replacement.childNodes));
                results.inert = false;
                results.removeAttribute('aria-busy');
                resultActions.forEach(function (button) { button.disabled = total === 0; });
                if (url.searchParams.has('page')) url.searchParams.set('page', String(currentPage));
                if (historyMode === 'push' && url.href !== location.href) {
                    history.pushState(history.state, '', url.href);
                } else if (historyMode !== 'none') {
                    history.replaceState(history.state, '', url.href);
                }
                appliedUrl = new URL(url.href);
                retryRequest = null;
                feedback.dataset.state = 'ready';
                message.textContent = feedback.dataset.resultsLabel.replace('{n}', total.toLocaleString(document.documentElement.lang || 'en'));
                emit('cs2:search-updated');
                if (focusResults) {
                    results.focus({ preventScroll: true });
                    results.scrollIntoView({ block: 'start', behavior: 'instant' });
                }
            } catch (error) {
                if (version !== revision || (error.name === 'AbortError' && !timedOut)) return;
                console.error('[CS2 Panel] Live search failed:', error);
                results.removeAttribute('aria-busy');
                feedback.dataset.state = 'error';
                message.setAttribute('role', 'alert');
                message.textContent = feedback.dataset.errorLabel;
                retry.hidden = false;
                fallback.href = url.href;
                fallback.hidden = false;
            } finally {
                clearTimeout(timeout);
                if (version === revision) request = null;
            }
        }

        function schedule() {
            cancel();
            pending();
            timer = setTimeout(function () { load(formUrl(), 'replace', false); }, 350);
        }

        input.addEventListener('compositionstart', function () { composing = true; cancel(); });
        input.addEventListener('compositionend', function () { composing = false; schedule(); });
        input.addEventListener('input', function (event) {
            if (!composing && !event.isComposing) schedule();
        });
        form.addEventListener('change', function (event) {
            if (event.target instanceof HTMLSelectElement) load(formUrl(), 'replace', false);
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!composing) load(formUrl(), 'replace', false);
        });
        retry.addEventListener('click', function () {
            if (retryRequest) load(retryRequest.url, retryRequest.historyMode, retryRequest.focusResults);
        });
        document.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            var link = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
            var pagination = results.contains(link) && link.closest('.pagination');
            var refresh = (results.contains(link) || form.contains(link)) && link.hasAttribute('data-live-search-link');
            if (!pagination && !refresh) return;
            if (link.classList.contains('disabled')) return;
            var url = new URL(link.href, location.href);
            if (url.origin !== location.origin || url.pathname !== location.pathname) return;
            event.preventDefault();
            if (form.contains(link)) restoreControls(url);
            load(url, pagination ? 'push' : 'replace', !!pagination);
        });
        window.addEventListener('popstate', function () {
            var url = new URL(location.href);
            if (url.pathname !== appliedUrl.pathname || (url.search === appliedUrl.search && !results.inert)) return;
            restoreControls(url);
            load(url, 'none', false);
        });
        window.addEventListener('pagehide', cancel);
        window.addEventListener('pageshow', function (event) {
            if (event.persisted && results.inert) load(formUrl(), 'replace', false);
        });
    });
})();
