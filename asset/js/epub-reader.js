/**
 * ePub reader — vanilla JS over our vendored epub.js.
 *
 * Progressive enhancement: the server renders a download link and hides the reader
 * chrome; this script reverses that only once a book is actually open. Every failure
 * path therefore lands the visitor back on a working link, never an empty frame.
 *
 * No jQuery: the circular audio player still needs it, this does not, and new code
 * should not add to that debt.
 */
(function () {
    'use strict';

    /** epub.js reads the whole OCF archive up front, so warn rather than hang. */
    var SLOW_FILE_BYTES = 25 * 1024 * 1024;

    /**
     * Give up on a book that never opens.
     *
     * ⚠️ NOT BELT AND BRACES — epub.js DOES hang here, intermittently. On an item
     * that also boots Mirador, `rendition.display()` sometimes never settles: no
     * error, no iframe, the promise simply stays pending (observed still pending
     * after 30 seconds, while the identical call on a quiet page renders in under
     * 200ms). Without this the reader would sit on "Opening the ePub…" for ever and
     * the visitor would never see the download link that was there before we hid it.
     */
    var OPEN_TIMEOUT_MS = 20000;

    function label(el, key, fallback) {
        return el.getAttribute('data-label-' + key) || fallback;
    }

    function withTimeout(promise, ms, message) {
        return new Promise(function (resolve, reject) {
            var timer = setTimeout(function () { reject(new Error(message)); }, ms);
            promise.then(
                function (value) { clearTimeout(timer); resolve(value); },
                function (error) { clearTimeout(timer); reject(error); }
            );
        });
    }

    function fail(root, message, error) {
        var status = root.querySelector('.advancedmedia-epub-status');
        var body = root.querySelector('.advancedmedia-epub-body');
        var toolbar = root.querySelector('.advancedmedia-epub-toolbar');
        if (body) { body.hidden = true; }
        if (toolbar) { toolbar.hidden = true; }
        if (status) {
            status.textContent = message;
            status.hidden = false;
        }
        // The fallback link is already in the DOM and visible. Leaving it alone is
        // the whole point of this function.
        if (error && window.console && console.warn) {
            console.warn('AdvancedMedia ePub reader:', error);
        }
    }

    function buildToc(root, rendition, navigation) {
        var toc = root.querySelector('.advancedmedia-epub-toc');
        var toggle = root.querySelector('.advancedmedia-epub-toc-toggle');
        if (!toc || !toggle) { return; }

        var items = (navigation && navigation.toc) || [];
        if (!items.length) {
            toggle.hidden = true;
            return;
        }

        var list = document.createElement('ul');
        items.forEach(function (item) {
            var li = document.createElement('li');
            var link = document.createElement('a');
            link.href = '#';
            link.textContent = item.label ? item.label.trim() : '';
            link.addEventListener('click', function (event) {
                event.preventDefault();
                rendition.display(item.href);
            });
            li.appendChild(link);
            list.appendChild(li);
        });
        toc.appendChild(list);

        toggle.addEventListener('click', function () {
            var opening = toc.hidden;
            toc.hidden = !opening;
            toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
            // Opening the panel narrows the text pane. epub.js paginates against a
            // width it measured once, so without this the text stays laid out for
            // the old width and is clipped mid-column. rAF so the new width is
            // settled before it measures again.
            requestAnimationFrame(function () { rendition.resize(); });
        });
    }

    function open(root) {
        var src = root.getAttribute('data-epub-src');
        var viewport = root.querySelector('.advancedmedia-epub-viewport');
        var body = root.querySelector('.advancedmedia-epub-body');
        var toolbar = root.querySelector('.advancedmedia-epub-toolbar');
        var status = root.querySelector('.advancedmedia-epub-status');
        var fallback = root.querySelector('.advancedmedia-epub-fallback');

        status.textContent = label(root, 'loading', 'Opening the ePub…');
        status.hidden = false;

        // Fetched by hand rather than handing epub.js the URL: it gives us one clear
        // place to catch a CORS or 404 failure (epub.js would surface it as an opaque
        // internal rejection), and the archive has to be read whole either way.
        fetch(src)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                if (Number(response.headers.get('Content-Length') || 0) > SLOW_FILE_BYTES) {
                    status.textContent = label(root, 'large', 'Opening a large ePub — this may take a moment…');
                }
                return response.arrayBuffer();
            })
            .then(function (buffer) {
                // ⚠️ REVEAL THE VIEWPORT BEFORE RENDERING, NOT AFTER.
                //
                // epub.js measures its container to paginate. Rendering into a hidden
                // element gives it a 0-height box, and it produces an iframe of height
                // 0 that never recovers when the element is later shown — a silent
                // blank reader with a perfectly good table of contents beside it.
                // (Mirador's thumbnail strip fails the same way when it boots hidden.)
                // The toolbar can wait; the viewport cannot.
                body.hidden = false;

                var book = window.ePub(buffer);
                var rendition = book.renderTo(viewport, {
                    width: '100%',
                    height: '100%',
                    flow: 'paginated',
                    spread: 'auto',
                    // The book is uploaded content: never let its own scripts run.
                    allowScriptedContent: false
                });

                return withTimeout(rendition.display(), OPEN_TIMEOUT_MS, 'display() never settled')
                    .then(function () {
                        toolbar.hidden = false;
                        status.hidden = true;
                        if (fallback) {
                            // Keep it reachable, just demoted once the reader works.
                            fallback.classList.add('advancedmedia-epub-fallback--secondary');
                        }

                        root.querySelector('.advancedmedia-epub-prev')
                            .addEventListener('click', function () { rendition.prev(); });
                        root.querySelector('.advancedmedia-epub-next')
                            .addEventListener('click', function () { rendition.next(); });

                        // Arrow keys, both from the page and from inside the book's own
                        // iframe (epub.js re-emits key events from the rendered document).
                        function onKey(event) {
                            if (event.key === 'ArrowLeft') { rendition.prev(); }
                            if (event.key === 'ArrowRight') { rendition.next(); }
                        }
                        rendition.on('keyup', onKey);
                        root.addEventListener('keyup', onKey);

                        return book.loaded.navigation.then(function (navigation) {
                            buildToc(root, rendition, navigation);
                        });
                    });
            })
            .catch(function (error) {
                fail(root, label(root, 'error', 'This ePub could not be opened in the page.'), error);
            });
    }

    /**
     * Open the book when the reader is scrolled near, not when the page loads.
     *
     * Two reasons, and both matter. An ePub has to be downloaded WHOLE before a
     * single page can be shown, so starting at page load spends a visitor's bandwidth
     * on a book they may never scroll to — on an item that also carries page images,
     * the reader is well below the viewer. And it keeps the reader out of the
     * page-load storm, where it competes with the image viewer booting and its tile
     * requests; that contention is where epub.js's intermittent hang shows up.
     */
    function whenNear(root, start) {
        if (typeof IntersectionObserver !== 'function') {
            start();
            return;
        }
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    observer.disconnect();
                    start();
                }
            });
        }, { rootMargin: '300px' });
        observer.observe(root);
    }

    function enhance(root) {
        if (root.dataset.advancedmediaEpubReady) { return; }
        root.dataset.advancedmediaEpubReady = '1';

        if (!root.getAttribute('data-epub-src') || typeof window.ePub !== 'function') {
            return;
        }
        if (!root.querySelector('.advancedmedia-epub-viewport')) {
            return;
        }

        whenNear(root, function () { open(root); });
    }

    function init() {
        Array.prototype.forEach.call(
            document.querySelectorAll('.advancedmedia-epub[data-epub-src]'),
            enhance
        );
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
