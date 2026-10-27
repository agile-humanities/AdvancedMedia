/*
 * Mirador page links: open a Mirador viewer at `?page=N` and keep the address bar
 * in step as the visitor pages, so copying the URL is the bookmark.
 * Spec: docs/mirador-page-links.md
 *
 *   ?page=N           1-based canvas in the first viewer on the page
 *   ?page=N&viewer=K  canvas N in the K-th viewer (document order); K=1 is never written
 *
 * Loaded (deferred) by the MiradorPageLinks helper decorator, right after the Mirador
 * module's own js/mirador.js (Mirador 3), or with js/mirador-page-links-4.mjs (Mirador 4, see init()). That position is what makes this work without a
 * Mirador plugin:
 *
 *  - It runs BEFORE Mirador boots. Until jQuery ready, `miradors[id]` is still the
 *    plain config object, so we can set `windows[0].canvasIndex` and Mirador's own
 *    starting-canvas saga opens that page from first paint.
 *  - It then waits for `miradors[id]` to become the instance ({plugins, config,
 *    store}) and watches that viewer's store for page changes.
 *
 * Invalid input is silent: a bad `page` opens page 1 and the URL is left alone;
 * an invalid `viewer` ignores the link entirely rather than guessing a viewer.
 */
(function () {
    'use strict';

  /**
   * Mirador 3 is a global (window.Mirador). Mirador 4 is an ES module that never sets one: the companion module
   * js/mirador-page-links-4.mjs imports it through the page's import map and calls window.advancedMediaPageLinksInit
   * with it. Both run before Mirador 4 builds its viewers (on window load), so the requested page still lands in the
   * config first.
   */
  function init(Mirador) {
    if (!Mirador || !Mirador.actions || !Mirador.selectors
        || !window.miradors || window.advancedMediaPageLinks) {
        // No Mirador 3 or 4 on this page (Mirador 2), or already initialised.
        return;
    }
    window.advancedMediaPageLinks = true;

    const PAGE = 'page';
    const VIEWER = 'viewer';
    const WAIT_FOR_VIEWER_MS = 30000;
    const POLL_MS = 100;
    // Keyboard paging fires a change per keypress. Safari throws a SecurityError
    // after ~100 replaceState calls in a short window; debouncing keeps us well clear.
    const WRITE_DEBOUNCE_MS = 250;

    /** A strict 1-based integer: no sign, decimals, leading zeros or whitespace. */
    function parseOrdinal(value) {
        return value !== null && /^[1-9][0-9]*$/.test(value) ? parseInt(value, 10) : null;
    }

    function decodeName(rawName) {
        try {
            return decodeURIComponent(rawName.replace(/\+/g, ' '));
        } catch (e) {
            return rawName;
        }
    }

    /**
     * Returns `search` with our parameters set (or removed, for null), editing the raw
     * query pair by pair. URLSearchParams would re-serialise EVERY parameter —
     * `property[0][text]=a b` becomes `property%5B0%5D%5Btext%5D=a+b` — so the visitor's
     * other parameters would visibly change even though they mean the same thing.
     */
    function withParams(search, updates) {
        const done = new Set();
        const pairs = [];
        search.replace(/^\?/, '').split('&').forEach(function (pair) {
            if (!pair) {
                return;
            }
            const name = decodeName(pair.split('=')[0]);
            if (!Object.prototype.hasOwnProperty.call(updates, name)) {
                pairs.push(pair);
            } else if (!done.has(name)) {
                // Replace in place, keeping the parameter's position; drop duplicates.
                done.add(name);
                if (updates[name] !== null) {
                    pairs.push(name + '=' + updates[name]);
                }
            }
        });
        Object.keys(updates).forEach(function (name) {
            if (!done.has(name) && updates[name] !== null) {
                pairs.push(name + '=' + updates[name]);
            }
        });
        return pairs.length ? '?' + pairs.join('&') : '';
    }

    function writeUrl(page, ordinal) {
        const search = withParams(window.location.search, {
            [PAGE]: String(page),
            [VIEWER]: ordinal === 1 ? null : String(ordinal),
        });
        if (search === window.location.search) {
            return;
        }
        try {
            window.history.replaceState(window.history.state, '',
                window.location.pathname + search + window.location.hash);
        } catch (e) {
            // Throttled by the browser. Nothing to recover: the next page turn writes again.
        }
    }

    function whenInstance(id, callback) {
        const started = Date.now();
        (function poll() {
            const entry = window.miradors[id];
            if (entry && entry.store) {
                callback(entry);
            } else if (Date.now() - started < WAIT_FOR_VIEWER_MS) {
                setTimeout(poll, POLL_MS);
            }
        })();
    }

    /**
     * Watches one viewer. `requestedIndex` is the 0-based canvas the link asked for
     * when this viewer is the target, else null.
     */
    function track(instance, ordinal, requestedIndex) {
        const store = instance.store;
        const configuredWindow = (instance.config && instance.config.windows || [])[0] || {};
        let windowId = null;
        let lastCanvasId = null;
        let settled = false;
        let timer = null;

        function onChange() {
            const state = store.getState();
            if (!windowId || !state.windows[windowId]) {
                windowId = Object.keys(state.windows)[0] || null;
            }
            const win = windowId && state.windows[windowId];
            if (!win || !win.canvasId || win.canvasId === lastCanvasId) {
                return;
            }
            lastCanvasId = win.canvasId;

            if (!settled) {
                // The first canvas is Mirador opening the viewer, not the visitor paging.
                // Never write it: a `?page=99` that fell back to page 1 must stay `?page=99`.
                settled = true;
                if (requestedIndex !== null) {
                    // canvasIndex loses to a manifest-declared `start` canvas, and is too
                    // late if Mirador booted before this script ran. Correct once, when
                    // the requested page exists. Set lastCanvasId first so the change this
                    // dispatch triggers is not mistaken for the visitor paging.
                    const canvases = Mirador.selectors.getCanvases(state, { windowId }) || [];
                    const current = Mirador.selectors.getCanvasIndex(state, { windowId });
                    if (requestedIndex < canvases.length && current !== requestedIndex) {
                        lastCanvasId = canvases[requestedIndex].id;
                        store.dispatch(Mirador.actions.setCanvas(windowId, lastCanvasId));
                    }
                }
                return;
            }

            // An item-set viewer lets the visitor open another manifest; the URL cannot
            // name that one, so a page number would bookmark the wrong thing.
            if (configuredWindow.manifestId && win.manifestId !== configuredWindow.manifestId) {
                return;
            }

            const page = Mirador.selectors.getCanvasIndex(state, { windowId }) + 1;
            clearTimeout(timer);
            timer = setTimeout(function () {
                writeUrl(page, ordinal);
            }, WRITE_DEBOUNCE_MS);
        }

        store.subscribe(onChange);
        // The first canvas may already be set if the manifest loaded before we attached.
        onChange();
    }

    const elements = Array.prototype.filter.call(
        document.querySelectorAll('.mirador.viewer'),
        function (el) {
            return el.id && window.miradors[el.id];
        }
    );
    if (!elements.length) {
        return;
    }

    const params = new URLSearchParams(window.location.search);
    const page = parseOrdinal(params.get(PAGE));
    // Absent `viewer` means the first viewer; present but invalid means no target at all.
    const ordinal = params.get(VIEWER) === null ? 1 : parseOrdinal(params.get(VIEWER));
    const target = page && ordinal ? elements[ordinal - 1] || null : null;

    if (target) {
        const entry = window.miradors[target.id];
        if (!entry.store && entry.windows && entry.windows[0]) {
            entry.windows[0].canvasIndex = page - 1;
        }
    }

    elements.forEach(function (el, i) {
        whenInstance(el.id, function (instance) {
            track(instance, i + 1, el === target ? page - 1 : null);
        });
    });
  }

    if (window.Mirador) {
        init(window.Mirador);
    } else {
        window.advancedMediaPageLinksInit = init;
        if (window.advancedMediaMirador) {
            // The Mirador 4 module ran first.
            init(window.advancedMediaMirador);
        }
    }
})();
