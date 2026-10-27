# AdvancedMedia — Mirador page links (`?page=N`) — Specification

**Status:** Implemented 2026-09-14 (uncommitted). All decisions settled with Bill 2026-09-14, including §5.
**Depends on:** [`architecture.md`](architecture.md) §1.4 (availability gating), §4.3 (adapters over viewer modules); [`STATUS.md`](STATUS.md) §4.4 (delegator precedent).
**Update (1.0.0, 2026-10-27):** from the Mirador module 3.4.17, Mirador 4 is the default viewer. It is an ES module
that sets no `window.Mirador`, so `js/mirador-page-links-4.mjs` imports it through the page's import map and hands
`setCanvas`, `getCanvases` and `getCanvasIndex` to the same script (Mirador 4 exports them at the top level). Mirador 4
builds its viewers on the window `load` event, after both scripts have run, so `?page=N` still lands in the config
first. The 3.4.9 notes below remain accurate for Mirador 3.

**Hard constraint (Mirador 3):** Mirador is pinned at 3.4.9 and its vendored bundle must not be rebuilt (3.4.17's rebuilt bundle blanks the viewer). Everything here works against the bundle as shipped.

> Deep-link into a Mirador viewer at a given page, and keep the address bar in step as the visitor pages, so copying the URL *is* the bookmark.

---

## 1. What ships

1. **Read:** `?page=N` (1-based) opens the viewer on canvas N from first paint — no flash of page 1.
2. **Write-back:** paging in the viewer rewrites `page` in the address bar (`history.replaceState`).
3. **Every Mirador viewer**, whichever template rendered it — anything that goes through the Mirador module's `mirador` view helper.
4. **JS in AdvancedMedia plus one small PHP delegator.** No Mirador plugin, no bundle rebuild, no edits to the Mirador module or the theme.

## 2. Decisions

| # | Question | Decision |
|---|---|---|
| 1 | What the URL identifies | `page=N`, 1-based canvas position → Mirador `canvasIndex: N-1` |
| 2 | Query param or route segment | Query parameter. Works unchanged on item, item-set and site pages; no routing changes |
| 3 | One-way or two-way | Two-way. Paging writes back, so the URL is the bookmark |
| 4 | In-page "copy link" UI | Deferred — write-back covers the need |
| 5 | Scope | Every Mirador viewer, regardless of calling template |
| 6 | Multiple viewers on one page | `viewer=K`, 1-based document order; an invalid `viewer` ignores the link — see §5 |
| 7 | Invalid `page` | Silently open page 1 (canvas 0). Do **not** rewrite the URL |

Calls made while writing this spec (approved 2026-09-14):

- **`replaceState`, not `pushState`** — see §4.3.
- **Other query params and the hash are preserved** untouched (`?q=wesley&page=4#x`).
- **Navigating back to page 1 writes `page=1` explicitly** rather than removing the param — it was a deliberate move and should bookmark as one.

---

## 3. Verified platform facts (2026-09-14, dev stack)

Checked in the pinned bundle and live on `/s/digital-collections/item/1379` (10 canvases).

### 3.1 Nothing existing does this for us

- **Mirador 3 core has no URL state.** The IIIF Content State convention (`?iiif-content=`) is absent: `iiif-content` has **0 hits** in `mirador.min.js`, `mirador-pack.min.js` and `mirador-bundle.min.js`. The `contentState` hits in `mirador-bundle.min.js` are Draft.js (the annotation editor), not IIIF.
- **Existing plugins don't fit.** `dbmdz/mirador-canvaslink` requires Mirador ≥ 4 and is an outbound share dialog that never reads the URL. `@harvard-lts/mirador-url-sync-plugin` is built for Harvard's mps-viewer with negligible adoption. `mirador-share-plugin` is outbound-only.
- **Any plugin is structurally unavailable anyway:** plugins are compiled into the bundle, and rebuilding the bundle is what broke 3.4.17.

### 3.2 The starting canvas is ordinary window config

De-minified from the `setWindowStartingCanvas` saga in `mirador-pack.min.js` (3.4.9):

```js
const { canvasId, canvasIndex, manifestId } = action.payload || action.window;
if (canvasId) {
  put(setCanvas(windowId, canvasId, null, { preserveViewport: !!action.payload }));
} else {
  const m = new Manifesto(yield select(getManifest, { manifestId }));
  const canvas = m.startCanvas || m.canvasAt(canvasIndex || 0) || m.canvasAt(0);
  if (canvas) put(setCanvas(windowId, canvas.id));
}
```

Precedence: **`canvasId` > manifest `start` > `canvasIndex` > canvas 0**. Resolution happens after the manifest loads, inside Mirador, so we never fetch or parse a manifest. Our IiifServer manifests carry no `start`.

### 3.3 Observed behaviour (fresh viewers booted in-page)

| Window config / action | `canvasId` sequence observed |
|---|---|
| `canvasIndex: 3` | `undefined → p4` — no flash of p1 |
| `canvasIndex: 99` (10 canvases) | `undefined → p1` — Mirador's own fallback gives decision 7 for free |
| post-init `store.dispatch(Mirador.actions.setCanvas(windowId, canvases[5].id))` | `→ p6`; `selectors.getCanvasIndex` returns `5` |

Each viewer has its **own store**.

### 3.4 Runtime surface

`window.Mirador` exports `viewer, actions, createStore, reducers, sagas, selectors`. Used here: `actions.setCanvas`, `selectors.getCanvases`, `selectors.getCanvasIndex`.

### 3.5 Boot sequence (as rendered)

```
<script defer src=".../mirador/mirador-pack.min.js">
<script defer src=".../js/mirador.js">   jQuery(ready) → miradors[id] = Mirador.viewer(miradors[id], plugins)
<script> miradors['mirador-1'] = { windows: [{ manifestId }], ... }    ← inline, runs at parse time
...
<div id="mirador-1" class="mirador viewer"></div>
```

`miradors[id]` holds the **config object** until jQuery ready, then is **replaced** by the instance `{ plugins, config, store }`.

### 3.6 Call sites

The `mirador` helper is invoked from six places outside the Mirador module:
AdvancedMedia `Media/Compound/Mirador.php`; AgileThemeTools `resource-page-block-layout/item-media.phtml`; IiifServer `iiif-player.phtml`; pitts3 `agile-theme-tools/agile-item/_show.phtml` (×2) and `resource-page-block-layout/item-media.phtml`. Plus the Mirador module's own resource-page and site-page blocks. Hooking any single caller would miss the rest.

### 3.7 `HeadScript` dedupes by `src`

`Laminas\View\Helper\HeadScript::isDuplicate()` skips a repeated `appendFile` of the same `src`, so appending on every viewer render is safe.

### 3.8 Mirador's thumbnail strip is broken in viewers that boot hidden

Mirador 3.4.9's `ThumbnailNavigation` scrolls its list on every page-index change, with no null check:

```js
componentDidUpdate(prev) {
  if (prev.canvasIndex !== this.props.canvasIndex) this.gridRef.current.scrollToItem(index, 'center');
}
```

A viewer that first renders inside a zero-size container (a closed tab, accordion or modal) never renders that list — **not even after it is shown**. Verified with a `display:none` wrapper at 1280×900: with no `page` param at all the list stays empty after showing, and the visitor's first page turn throws `Cannot read properties of null (reading 'scrollToItem')`, after which Mirador's error boundary removes the strip. **This is independent of page links.** No template in this codebase renders Mirador hidden today.

---

## 4. Behaviour

### 4.1 URL grammar

- **`page`** is valid iff it matches `^[1-9][0-9]*$` — no sign, decimals, leading zeros or whitespace. Anything else, including absent, means "no page".
- **`viewer`** — see §5 (proposed).
- Parameter names are fixed in v1 (no setting).

### 4.2 Read (page load)

1. The script runs deferred, after `mirador.js`, before DOMContentLoaded. Parse `page`.
2. **Valid** → set `windows[0].canvasIndex = N - 1` on the target viewer's `miradors[id]`, which is still the config object at this point.
3. **Invalid or absent** → do nothing. Mirador opens canvas 0 (or the manifest's `start`).
4. **Valid but beyond the manifest** → Mirador falls back to canvas 0 on its own (§3.3). The URL is left as-is (decision 7).
5. **Manifest declares `start`** → it outranks `canvasIndex` (§3.2). After the initial settle, if the requested index is in range and the window isn't on it, dispatch `setCanvas` once. That costs a brief flash on such manifests only; none of ours have `start`.
6. **Late arrival** → if `miradors[id]` is already an instance (has `store`), skip step 2 and go straight to the step-5 correction.
7. **Viewer boots hidden** (zero size) → step 2 applies as normal, so the viewer shows the requested page when revealed and write-back works. The only difference is *when* Mirador's thumbnail-strip error (§3.8) fires: at load instead of at the visitor's first page turn. The strip is non-functional in that situation either way.
   *A guard that withheld the page until the viewer was shown and its thumbnail list existed was built, tested and removed (2026-09-14): the list never appears after showing, so the guard protected nothing and only dropped the link.*

### 4.3 Write-back

- **Arm only after the initial settle.** The first `canvasId` transition (`undefined → X`) and any step-5 correction never write. This is what keeps decision 7 true: `?page=99` lands on p1 and must **not** become `page=1`.
- On each later `canvasId` change: `page = selectors.getCanvasIndex(state, { windowId }) + 1`; rebuild the URL from `location` with `URLSearchParams`; `history.replaceState(history.state, '', url)`.
- **Skip** when the rebuilt URL equals the current one.
- **Debounce (~250 ms) and wrap in try/catch.** Keyboard paging fires many changes; Safari throws `SecurityError` after roughly 100 `replaceState` calls in a short window, and Chrome throttles with a console warning.
- **Why `replaceState`:** `pushState` would make Back step backwards through every page turned — 1,712 of them on item 5862 — before leaving the page. It also means no `popstate` handling is needed.
- **Book view:** write the index of the window's current `canvasId`. Opening `page=N` shows the spread containing N; turning spreads writes the left-hand page.
- **Only while the window shows the configured manifest.** Item-set and collection viewers let the visitor open a different manifest; once `manifestId` differs from the configured one, stop writing, because the URL can't name that manifest. On read, `canvasIndex` on a Collection is simply inert.

### 4.4 Outcomes

| Request | Viewer opens | Address bar after load |
|---|---|---|
| no `page` | p1 | unchanged |
| `?page=4` | p4 | unchanged |
| `?page=99` (10 canvases) | p1 | unchanged |
| `?page=0` / `-2` / `3.5` / `abc` / `03` | p1 | unchanged |
| `?page=4`, visitor pages to p7 | p4 → p7 | `?page=7` |
| `?page=abc`, visitor pages to p2 | p1 → p2 | `?page=2` (invalid value overwritten) |
| `?q=wesley&page=4#x`, visitor pages to p5 | p4 → p5 | `?q=wesley&page=5#x` |
| `?page=4`, viewer boots in a hidden container | p4 once shown | unchanged — plus one Mirador console error at load (§3.8) |

---

## 5. Multiple viewers on one page (decision 6)

### 5.1 Why write-back makes this more than an edge case

One `page` param, several independent stores. With no targeting, paging viewer 2 writes `page=7`, and a reload applies 7 to **viewer 1**. So even "no targeting" needs an explicit rule.

### 5.2 Minimum rule (not chosen — kept for the record)

Only the first viewer in document order reads and writes `page`; the others are inert. It never misapplies a link.

### 5.3 The `viewer` target (chosen)

| Scheme | Example | Verdict |
|---|---|---|
| Page (document) order | `viewer=2` | **Recommended.** Matches what the visitor sees |
| Element id | `viewer=mirador-2` | Reject. The id comes from a PHP `static $id` counter, so it follows server render-call order, which can differ from document order. It exposes an internal and is no more stable than an ordinal |
| Resource | `item=1379` | Stable under reordering, but ambiguous when one item appears twice, and item sets / dynamic collections need other keys. Overkill for an edge case |

Behaviour:

- `viewer` is 1-based over `document.querySelectorAll('.mirador.viewer')`; absent means 1.
- Written only when K ≠ 1; paging viewer 1 removes it. Single-viewer pages — every item page today — never show it.
- Paging viewer K writes `page` and `viewer=K`. The last-paged viewer owns the URL.
- **Invalid `viewer`** (not a positive integer, or greater than the viewer count): **ignore the link entirely** — no viewer jumps, URL untouched. Applying the page to viewer 1 would be a confident wrong answer. *(Confirmed: deliberately different from absent → viewer 1.)*
- Accepted weakness: inserting a viewer above shifts the ordinals, as with any positional link.

Cost: about 20–30 lines of JS on top of §5.2; no PHP.

---

## 6. Implementation shape

### 6.1 Hook: a delegator on the `mirador` view helper

- `config/module.config.php` → `view_helpers.delegators['mirador'] = [Service\ViewHelper\MiradorPageLinksDelegator::class]`.
- The delegator returns `View\Helper\MiradorPageLinks`, a decorator. `__invoke(...$args)` calls the inner helper; if the returned HTML is non-empty, it appends `js/mirador-page-links.js` (deferred) to `headScript`, then returns the HTML unchanged. `setView()` forwards to the inner helper.
- **Why a delegator:** same reasoning as the S3 store override (STATUS §4.4). It is order-independent, inert when the Mirador module is absent (architecture §1.4 gating for free), and covers every call site in §3.6.
- **Why append after the inner call:** the inner call appends `mirador.js`, so ours lands later in defer order. It mutates config before boot, and can then find instances after.
- **Decorator is safe (verified 2026-09-14):** the helper's only public method is `__invoke`, and no caller type-hints the concrete class at runtime — the only references are `/** @var \Mirador\View\Helper\Mirador */` docblocks in AgileThemeTools and pitts3 `item-media.phtml` (IDE hints only).

### 6.2 Script: `asset/js/mirador-page-links.js`

Plain JS, no build step, no jQuery dependency.

- **Don't rely on ready-handler ordering.** jQuery runs ready handlers asynchronously, so a native `DOMContentLoaded` listener can run *before* `mirador.js` creates the instances. Wait for each instance by polling for `miradors[id].store` (give up after ~30 s).
- No-op unless `window.Mirador?.actions` and `window.miradors` exist. That covers Mirador 2 and pages without a viewer.

```js
const viewers = [...document.querySelectorAll('.mirador.viewer')].filter(el => miradors[el.id]);
const params  = new URLSearchParams(location.search);
const page    = parsePage(params.get('page'));        // §4.1 → integer or null
const target  = resolveTarget(params, viewers);       // §5 → element or null
if (page && target && !miradors[target.id].store) {
  miradors[target.id].windows[0].canvasIndex = page - 1;   // §4.2 step 2
}
viewers.forEach((el, i) => whenInstance(el.id).then(inst => {
  if (el === target && page) correctAfterSettle(inst, page - 1);  // §4.2 steps 5–6
  armWriteBack(inst, i + 1);                                       // §4.3
}));
```

### 6.3 Files

| File | Change |
|---|---|
| `src/Service/ViewHelper/MiradorPageLinksDelegator.php` | new |
| `src/View/Helper/MiradorPageLinks.php` | new — decorator |
| `asset/js/mirador-page-links.js` | new |
| `config/module.config.php` | add the view-helper delegator |
| `README.md`, `docs/STATUS.md` | usage + status |

No settings and no admin UI in v1.

---

## 7. Verification (2026-09-14, dev stack, Chrome)

Item 1379 (10 canvases) unless noted. PHP lint clean; `node --check` clean.

- [x] **Every row of the §4.4 table.** Page applied before boot (`canvasIndex: 3`, no correction dispatch); `page=99`, `03`, `0`, `abc` → p1 with the URL untouched; exact preservation of `?q=wesley%20x&property[0][text]=a+b&page=4#frag` → `…page=5#frag`.
- [x] **Write-back** via dispatched `setCanvas` and via Mirador's real *Next item* button; explicit `page=1` on returning to the first page.
- [x] **Reload lands on the written page** (fresh load of the written `?page=N`).
- [x] **Book view:** jumping to p5 shows spread p4–p5 and writes `page=5`; the next spread writes its left page (`page=6`).
- [~] **Manifest switch stops write-back** — simulated with `updateWindow` to another manifest; not tried on a real item-set page.
- [x] **Burst:** 150 page changes in one tick → **1** `replaceState` call, URL `?page=10`. Safari's throttle was not tested.
- [x] **Script tag** renders once, after `mirador.js`, on `digital-collections` and on `paradise-lost` (second site).
- [x] **Two viewers (§5)** on a throwaway static copy of a real item page with a second viewer (item 1378) added: `viewer=2&page=3` targets only viewer 2; paging each viewer writes/removes `viewer` correctly; `viewer=3` and `viewer=x` ignore the link. *The only real two-viewer page, `day1/exhibit1`, is private and was not tested.*
- [x] **No new console errors** in any of the above at a real viewport size (control page without the script compared).
- [x] **Viewer booting in a `display:none` container** (§4.2 step 7): p4 once shown, URL kept, write-back works; one Mirador error at load (§3.8).
- [ ] **Mirador module deactivated** — not tested (would change shared dev module state). Inert by construction: the delegated service is never created.
- [ ] **Mirador 2** — not tested; the script no-ops without `Mirador.actions`.

---

## 8. Deferred

- **"Copy link to this page" UI** (decision 4).
- **`?section=`** — manifests carry `structures` ranges; this would ride the same read path.
- **`?media=<id>`** stable links — canvas position ≠ media position (IiifServer indexes canvases per media type), so it needs a media→canvas map.
- **IIIF Content State (`iiif-content`) interop** — could be layered onto the same read path later.
- **A history entry per page turn** (`pushState`).
- **Mirador 2.**

## 9. Open questions

None. Resolved 2026-09-14: `viewer=K` in document order (§5.3); an invalid `viewer` ignores the link; the three spec-time calls in §2 stand.
