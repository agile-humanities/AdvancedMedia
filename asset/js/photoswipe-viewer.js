/**
 * Opens single images in PhotoSwipe.
 *
 * Progressive enhancement throughout: the markup is a working link to the full-size
 * image, and everything here only intercepts it. If the libraries failed to load, if
 * an AMD loader swallowed their globals, or if anything below throws, the link still
 * does what it says.
 */
(function () {
    'use strict';

    /**
     * PhotoSwipe v5 ships zoom and close, but NO fullscreen button — "fullscreen" in
     * its docs means opening the viewer already fullscreen, which is not what a
     * visitor looking at a manuscript wants. So we register one, using the same UI
     * API its own buttons use, and drive the Fullscreen API on the viewer's root
     * element. Where the browser has no Fullscreen API (older iOS Safari), the
     * button is simply not registered rather than added and dead.
     */
    function fullscreenElement() {
        return document.fullscreenElement || document.webkitFullscreenElement || null;
    }

    function fullscreenSupported(el) {
        return !!(el.requestFullscreen || el.webkitRequestFullscreen);
    }

    function toggleFullscreen(el) {
        if (fullscreenElement()) {
            var exit = document.exitFullscreen || document.webkitExitFullscreen;
            if (exit) { exit.call(document); }
            return;
        }
        var request = el.requestFullscreen || el.webkitRequestFullscreen;
        if (!request) { return; }
        // requestFullscreen() returns a promise that REJECTS when the browser
        // refuses — no user gesture, an embedding context that disallows it, a
        // kiosk policy. Unhandled, that is a button that does nothing and says
        // nothing. The viewer still works; only fullscreen is unavailable.
        var result = request.call(el);
        if (result && typeof result.catch === 'function') {
            result.catch(function (error) {
                if (window.console && console.info) {
                    console.info('AdvancedMedia: fullscreen was refused by the browser.', error);
                }
            });
        }
    }

    var FULLSCREEN_ICON =
        '<svg class="pswp__icn" viewBox="0 0 32 32" width="32" height="32" aria-hidden="true">'
        + '<path d="M12 10H10v2H8V8h4v2zm12 2h-2v-2h-2V8h4v4zM10 20v2h2v2H8v-4h2zm12 2v-2h2v4h-4v-2h2z"'
        + ' fill="currentColor"/></svg>';

    function addFullscreenButton(lightbox) {
        lightbox.on('uiRegister', function () {
            var pswp = lightbox.pswp;
            if (!pswp || !pswp.element || !fullscreenSupported(pswp.element)) {
                return;
            }
            pswp.ui.registerElement({
                name: 'fullscreen',
                // PhotoSwipe labels its own buttons; a registered one gets nothing
                // unless we say so, which leaves an unnamed control — invisible to a
                // screen reader and to anything else that navigates by name.
                ariaLabel: 'Toggle fullscreen',
                title: 'Toggle fullscreen',
                // Between zoom (10) and close (20), so close stays at the end where
                // people expect it.
                order: 15,
                isButton: true,
                html: FULLSCREEN_ICON,
                onClick: function (event, el, instance) {
                    toggleFullscreen(instance.element);
                }
            });
        });
    }

    function lightboxFor(root) {
        var lightbox = new window.PhotoSwipeLightbox({
            gallery: root,
            children: 'a.advancedmedia-image-link',
            pswpModule: window.PhotoSwipe,
            // Zoom out of the thumbnail rather than fading in: it keeps the
            // connection between the small image and the large one.
            showHideAnimationType: 'zoom',
            bgOpacity: 0.95,
            padding: { top: 20, bottom: 40, left: 20, right: 20 },
            wheelToZoom: true,
            // This player renders exactly one image, so paging controls are
            // meaningless. PhotoSwipe loops by default, which leaves the arrows
            // ENABLED on a single slide rather than disabling them — they would sit
            // there inviting a click that does nothing. Not registering them is
            // cleaner than hiding them in CSS.
            arrowPrev: false,
            arrowNext: false,
            counter: false
        });
        addFullscreenButton(lightbox);
        return lightbox;
    }

    /**
     * PhotoSwipe needs the full image's pixel size before it opens, to lay the viewer
     * out without a reflow. Normally the server supplies it (IiifServer caches
     * dimensions on the media). When it has not, we measure the image ourselves — but
     * only once the visitor asks to see it, because measuring means downloading it,
     * and downloading a full-resolution scan that nobody clicked on is exactly what
     * this player is meant to avoid.
     */
    function openAfterMeasuring(root, anchor) {
        function handler(event) {
            event.preventDefault();
            anchor.removeEventListener('click', handler);

            var probe = new Image();
            probe.onload = function () {
                anchor.dataset.pswpWidth = probe.naturalWidth;
                anchor.dataset.pswpHeight = probe.naturalHeight;
                var lightbox = lightboxFor(root);
                lightbox.init();
                lightbox.loadAndOpen(0);
            };
            probe.onerror = function () {
                // Could not measure it; fall back to the link's own behaviour.
                window.open(anchor.href, '_blank', 'noopener');
            };
            probe.src = anchor.href;
        }
        anchor.addEventListener('click', handler);
    }

    function enhance(root) {
        if (root.dataset.advancedmediaPhotoswipeReady) { return; }
        root.dataset.advancedmediaPhotoswipeReady = '1';

        var anchor = root.querySelector('a.advancedmedia-image-link');
        if (!anchor) { return; }

        if (anchor.dataset.pswpWidth && anchor.dataset.pswpHeight) {
            lightboxFor(root).init();
            return;
        }
        openAfterMeasuring(root, anchor);
    }

    function init() {
        if (typeof window.PhotoSwipe !== 'function' || typeof window.PhotoSwipeLightbox !== 'function') {
            return;
        }
        Array.prototype.forEach.call(
            document.querySelectorAll('.advancedmedia-image[data-photoswipe]'),
            enhance
        );
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
