(function () {
    'use strict';

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    var desktopPointer = window.matchMedia('(min-width: 901px) and (hover: hover) and (pointer: fine)');
    var animationLibrary = window.gsap;
    var motionContext = animationLibrary ? animationLibrary.context(function () {}, document.body) : null;
    var smoothScroll = null;
    var smoothScrollTick = null;
    var spotlightControls = [];
    function effectsReduced() {
        return document.documentElement.dataset.effects === 'reduced';
    }
    var motionSkipped = window.CS2_MOTION_LOADED === false;
    if (!animationLibrary && !motionSkipped) console.error('[CS2 Panel] GSAP failed to load; using native animation fallbacks.');
    if (!window.Lenis && !motionSkipped) console.error('[CS2 Panel] Lenis failed to load; retaining native scrolling.');
    document.body.classList.toggle('panel-gsap-motion', !!animationLibrary && !reducedMotion.matches);
    var panelTitle = document.getElementById('dashboard-overview-title');
    if (panelTitle) {
        var titleFrame = null;
        function fitPanelTitle() {
            titleFrame = null;
            panelTitle.style.removeProperty('font-size');
            var available = panelTitle.clientWidth;
            var required = panelTitle.scrollWidth;
            if (available > 0 && required > available) {
                var size = parseFloat(getComputedStyle(panelTitle).fontSize);
                panelTitle.style.fontSize = Math.max(16, size * available / required) + 'px';
            }
        }
        function scheduleTitleFit() {
            if (titleFrame === null) titleFrame = requestAnimationFrame(fitPanelTitle);
        }
        new ResizeObserver(scheduleTitleFit).observe(panelTitle.parentElement);
        document.fonts.ready.then(scheduleTitleFit);
        scheduleTitleFit();
    }
    var drawer = document.getElementById('portal-navigation');
    var menuButton = document.querySelector('.portal-menu');
    var backdrop = document.querySelector('.sidebar-backdrop');
    var navigationGroups = document.querySelectorAll('[data-panel-dropdown]');
    function closeNavigationGroups(except) {
        navigationGroups.forEach(function (group) {
            if (group === except) return;
            group.querySelector('.panel-nav-trigger').setAttribute('aria-expanded', 'false');
            group.querySelector('.panel-nav-panel').hidden = true;
        });
    }
    navigationGroups.forEach(function (group) {
        var trigger = group.querySelector('.panel-nav-trigger');
        var panel = group.querySelector('.panel-nav-panel');
        function open() {
            closeNavigationGroups(group);
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
        }
        trigger.addEventListener('click', function () {
            if (panel.hidden) open();
            else closeNavigationGroups();
        });
        trigger.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                event.stopPropagation();
                open();
                panel.querySelector('a').focus();
            }
        });
        group.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeNavigationGroups();
                trigger.focus();
                return;
            }
            if (panel.hidden || !['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            var links = Array.from(panel.querySelectorAll('a'));
            var current = links.indexOf(document.activeElement);
            if (current < 0) return;
            event.preventDefault();
            var next = event.key === 'Home' ? 0 : event.key === 'End' ? links.length - 1
                : (current + (event.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
            links[next].focus();
        });
        group.addEventListener('focusout', function () {
            setTimeout(function () {
                if (!group.contains(document.activeElement)) {
                    trigger.setAttribute('aria-expanded', 'false');
                    panel.hidden = true;
                }
            }, 0);
        });
    });
    document.addEventListener('pointerdown', function (event) {
        if (!event.target.closest('[data-panel-dropdown]')) closeNavigationGroups();
    });

    if (drawer && menuButton) {
        function syncDrawer() {
            var open = drawer.classList.contains('open');
            menuButton.setAttribute('aria-expanded', String(open));
            drawer.setAttribute('aria-hidden', String(!open));
            drawer.inert = !open;
            document.body.classList.toggle('portal-drawer-open', open);
        }
        new MutationObserver(syncDrawer).observe(drawer, { attributes: true, attributeFilter: ['class'] });
        syncDrawer();
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && drawer.classList.contains('open')) {
                drawer.classList.remove('open');
                if (backdrop) backdrop.classList.remove('show');
                menuButton.focus();
            }
        });
    }

    var heroes = document.querySelectorAll('[data-portal-hero], .auth-scene');
    heroes.forEach(function (hero) {
        var pending = false;
        var pointerX = 0;
        var pointerY = 0;
        hero.addEventListener('pointermove', function (event) {
            if (reducedMotion.matches || effectsReduced() || event.pointerType === 'touch') return;
            pointerX = event.clientX;
            pointerY = event.clientY;
            if (pending) return;
            pending = true;
            requestAnimationFrame(function () {
                pending = false;
                if (reducedMotion.matches || effectsReduced()) return;
                var bounds = hero.getBoundingClientRect();
                if (bounds.width <= 0 || bounds.height <= 0) return;
                hero.style.setProperty('--portal-x', ((pointerX - bounds.left) / bounds.width) * 100 + '%');
                hero.style.setProperty('--portal-y', ((pointerY - bounds.top) / bounds.height) * 100 + '%');
            });
        });
        hero.addEventListener('pointerleave', function () {
            hero.style.setProperty('--portal-x', '50%');
            hero.style.setProperty('--portal-y', '50%');
        });
    });

    var counterFrames = new Map();
    function revealCounter(element) {
        if (element.dataset.portalCounted === '1') return;
        element.dataset.portalCounted = '1';
        var original = element.textContent;
        var raw = element.dataset.portalCount || original.replace(/[,\s]/g, '');
        if (!/^\d+$/.test(raw)) {
            console.error('[CS2 Portal] Invalid counter value:', raw);
            return;
        }
        var value = Number(raw);
        if (!Number.isSafeInteger(value)) {
            console.error('[CS2 Portal] Counter exceeds safe integer range:', raw);
            return;
        }
        if (reducedMotion.matches || value === 0) return;
        if (animationLibrary && motionContext) {
            motionContext.add(function () {
                var state = { value: 0 };
                var tween = animationLibrary.to(state, {
                    value: value,
                    duration: .85,
                    ease: 'power3.out',
                    onUpdate: function () { element.textContent = Math.round(state.value).toLocaleString('en-US'); },
                    onComplete: function () {
                        element.textContent = original;
                        counterFrames.delete(element);
                    },
                });
                counterFrames.set(element, { tween: tween, original: original });
            });
            return;
        }
        var start = performance.now();
        function frame(now) {
            var progress = Math.min((now - start) / 850, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            element.textContent = Math.round(value * eased).toLocaleString('en-US');
            if (progress < 1) {
                counterFrames.set(element, { id: requestAnimationFrame(frame), original: original });
            } else {
                element.textContent = original;
                counterFrames.delete(element);
            }
        }
        counterFrames.set(element, { id: requestAnimationFrame(frame), original: original });
    }

    var targets = document.querySelectorAll(
        '.content > :not(script):not(.modal-overlay), .portal-hero-copy > *, .portal-hero-note, ' +
        '.server-card, .contest-card, .store-card, .benefits-card, .auth-login-card'
    );
    var observer = null;
    if (!reducedMotion.matches && 'IntersectionObserver' in window) {
        document.body.classList.add('portal-motion-ready');
        observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var element = entry.target;
                element.classList.add('portal-visible');
                if (animationLibrary && motionContext) {
                    motionContext.add(function () {
                        var isHeading = element.matches('h1, h2, .portal-section-heading');
                        var from = {
                            opacity: 0,
                            y: isHeading ? 16 : 22,
                        };
                        var to = {
                            opacity: 1,
                            y: 0,
                            duration: .65,
                            delay: parseFloat(element.style.getPropertyValue('--portal-delay')) / 1000 || 0,
                            ease: 'power3.out',
                            clearProps: 'opacity,transform,filter',
                        };
                        if (isHeading && !effectsReduced()) {
                            from.filter = 'blur(7px)';
                            to.filter = 'blur(0px)';
                        }
                        animationLibrary.fromTo(element, from, to);
                    });
                }
                element.querySelectorAll('[data-portal-count], .stat .value').forEach(revealCounter);
                element.querySelectorAll('[data-panel-chart]').forEach(function (chart) {
                    chart.classList.add('panel-chart-animate');
                });
                observer.unobserve(element);
            });
        }, { threshold: 0, rootMargin: '0px 0px -20px 0px' });
        targets.forEach(function (element, index) {
            element.classList.add('portal-reveal');
            element.style.setProperty('--portal-delay', Math.min(index % 5, 4) * 65 + 'ms');
            observer.observe(element);
        });
    }

    function applyMotionPreference() {
        document.body.classList.toggle('panel-gsap-motion', !!animationLibrary && !reducedMotion.matches);
        updateSmoothScroll();
        if (!reducedMotion.matches) {
            if (animationLibrary && !motionContext) motionContext = animationLibrary.context(function () {}, document.body);
            return;
        }
        document.body.classList.remove('portal-motion-ready');
        if (observer) observer.disconnect();
        targets.forEach(function (element) { element.classList.add('portal-visible'); });
        counterFrames.forEach(function (animation, element) {
            if (animation.tween) animation.tween.kill();
            else cancelAnimationFrame(animation.id);
            element.textContent = animation.original;
        });
        counterFrames.clear();
        if (motionContext) motionContext.revert();
        motionContext = null;
        spotlightControls.forEach(function (control) { control.quick = null; });
    }
    reducedMotion.addEventListener('change', applyMotionPreference);

    function syncScrollLock() {
        if (!smoothScroll) return;
        var locked = document.body.classList.contains('portal-drawer-open')
            || !!document.querySelector('.modal-overlay.open, dialog[open], .update-hud.open');
        if (locked && !smoothScroll.isStopped) smoothScroll.stop();
        else if (!locked && smoothScroll.isStopped) smoothScroll.start();
    }
    function stopSmoothScroll() {
        if (!smoothScroll) return;
        animationLibrary.ticker.remove(smoothScrollTick);
        smoothScroll.destroy();
        smoothScroll = null;
        smoothScrollTick = null;
        document.body.classList.remove('panel-smooth-scroll');
    }
    function updateSmoothScroll() {
        var enabled = !!animationLibrary && !!window.Lenis && desktopPointer.matches && !reducedMotion.matches
            && !effectsReduced() && !document.hidden
            && !document.body.classList.contains('install-body') && !document.querySelector('.skins-app');
        if (!enabled) {
            stopSmoothScroll();
            return;
        }
        if (smoothScroll) return;
        smoothScroll = new window.Lenis({
            autoRaf: false,
            lerp: .1,
            smoothWheel: true,
            syncTouch: false,
            anchors: false,
            prevent: function (node) {
                if (node.closest('.modal-overlay, dialog, .portal-drawer, .x-modal-layer')) return true;
                var region = node.closest('.dropdown-menu, .panel-nav-panel, .rcon-out, .err-trace, .install-form-scroll, .chat-log-text');
                if (!region || region.scrollHeight <= region.clientHeight) return false;
                var overflow = getComputedStyle(region).overflowY;
                return overflow === 'auto' || overflow === 'scroll';
            },
            virtualScroll: function (event) { return !event.event.defaultPrevented && Math.abs(event.deltaX) <= Math.abs(event.deltaY); },
        });
        smoothScrollTick = function (time) { smoothScroll.raf(time * 1000); };
        animationLibrary.ticker.add(smoothScrollTick);
        animationLibrary.ticker.lagSmoothing(0);
        document.body.classList.add('panel-smooth-scroll');
        syncScrollLock();
    }
    desktopPointer.addEventListener('change', updateSmoothScroll);
    document.addEventListener('visibilitychange', updateSmoothScroll);
    document.addEventListener('cs2:effectschange', function () {
        updateSmoothScroll();
        if (!effectsReduced()) return;
        spotlightControls.forEach(function (control) {
            if (control.quick) {
                Object.keys(control.quick).forEach(function (key) { control.quick[key].tween.kill(); });
                control.quick = null;
            }
            control.element.style.setProperty('--panel-spotlight-presence', 0);
        });
    });
    new MutationObserver(syncScrollLock).observe(document.body, {
        attributes: true, subtree: true, attributeFilter: ['class', 'open'],
    });
    updateSmoothScroll();
    window.addEventListener('pagehide', stopSmoothScroll);

    document.querySelectorAll('.portal-hero-note, .portal-moderation-layout > .card, .server-card, .contest-card, .store-card, .benefits-card').forEach(function (card) {
        card.classList.add('panel-spotlight-card');
        var control = { quick: null, element: card };
        spotlightControls.push(control);
        card.addEventListener('pointermove', function (event) {
            if (!animationLibrary || !motionContext || reducedMotion.matches || effectsReduced()
                || !desktopPointer.matches || event.pointerType === 'touch') return;
            if (!control.quick) {
                motionContext.add(function () {
                    control.quick = {
                        x: animationLibrary.quickTo(card, '--panel-spotlight-x', { duration: .35, ease: 'power3.out' }),
                        y: animationLibrary.quickTo(card, '--panel-spotlight-y', { duration: .35, ease: 'power3.out' }),
                        presence: animationLibrary.quickTo(card, '--panel-spotlight-presence', { duration: .25, ease: 'power2.out' }),
                    };
                });
            }
            var bounds = card.getBoundingClientRect();
            control.quick.x(event.clientX - bounds.left);
            control.quick.y(event.clientY - bounds.top);
            control.quick.presence(1);
        });
        card.addEventListener('pointerleave', function () {
            if (control.quick) control.quick.presence(0);
        });
    });

    var progress = document.createElement('div');
    progress.className = 'portal-scroll-progress';
    progress.setAttribute('aria-hidden', 'true');
    document.body.appendChild(progress);
    var scrollPending = false;
    function updateProgress() {
        var available = document.documentElement.scrollHeight - window.innerHeight;
        progress.style.transform = 'scaleX(' + (available > 0 ? window.scrollY / available : 0) + ')';
        scrollPending = false;
    }
    window.addEventListener('scroll', function () {
        if (scrollPending) return;
        scrollPending = true;
        requestAnimationFrame(updateProgress);
    }, { passive: true });
    window.addEventListener('resize', updateProgress);
    updateProgress();

    window.addEventListener('pageshow', function () {
        if (animationLibrary) animationLibrary.set('.main, .auth-wrap', { clearProps: 'opacity,transform,filter' });
        updateSmoothScroll();
    });
    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        var link = event.target.closest('a[href]');
        if (!(link instanceof HTMLAnchorElement) || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        if (link.closest('[data-no-page-transition]') || link.hasAttribute('data-confirm') || link.hasAttribute('data-modal')) return;
        var destination = new URL(link.href);
        if (!['http:', 'https:'].includes(destination.protocol) || destination.origin !== window.location.origin) return;
        if (destination.pathname === window.location.pathname && destination.search === window.location.search) return;
        closeNavigationGroups();
    });
})();
