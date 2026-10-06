# AdvancedMedia — project status / handoff

**Last updated:** 2026-07-21
**Branch:** `development` (in the `codebase` sub-repo), aligned with `origin/development`; tip `cce2a884`
**Module version:** 0.1.0 — active, **live on site 1** (`digital-collections`), and the media-URL
proxy is **verified working on the remote dev server**. Baseline build; now being put through its paces.

Read this first to resume work. Design rationale lives in
[`architecture.md`](architecture.md); the media-routing / download design is in
[`media-routing.md`](media-routing.md); the surrounding environment/third-party bugs are in
[`../../../_media_iiif_stack_README.md`](../../../_media_iiif_stack_README.md).

---

## 1. TL;DR

Stages **(a)**, **(b)**, **(c)** of the build sequence are **done, verified, committed, and
live**. Two further deliverables have landed on top:

- **(a)** Player registry + display-time resolver + a resource-page block.
- **(b)** Compound registry + composer + the **Audio with Transcript** compound (full migration
  of AgileThemeTools' circular audio player, VTT parser, transcript sync).
- **(c)** The **Structure** registry (detection split out of compounds), Mirador + UV /
  Interactive-Resource compounds, and the **full config UI at both global and per-site scope**.
- **Downloads block** — a placeable resource block offering per-media-type download buttons,
  with a tri-state config column and a per-type count threshold.
- **Media URL proxying** — an *optional, off-by-default* capability that routes media URLs
  through this host so bot/harvester access can be governed by network rules. Serves `/files`
  with a direct **200** (never a 302 — a redirect broke ImageServer tiling and leaked the bucket
  URL) by an auto-detected mode: **X-Accel-Redirect** (nginx zero-copy) or **PHP streaming**
  (fallback). Enabling is gated on a server-side reachability probe. See [`media-routing.md`](media-routing.md).
- **Mirador page links** (2026-09-14, uncommitted) — `?page=N` deep links and URL write-back for
  every Mirador viewer. See [`mirador-page-links.md`](mirador-page-links.md).

**The block is live.** Site 1 renders through `advancedMedia` (see §3). Remaining: (d) the
per-resource exception table + editor; (e) theme-settable fallback thumbnails.

---

## 2. Recent commits

Now on `development` (aligned with `origin/development`). Most recent:

```
cce2a884  Media proxy: serve /files via X-Accel-Redirect or streaming   ← current tip
dee2c8c4  Merging development-advanced-media into development (Beanstalk)
84317601  Configuration form instructions and reorganization
…          (advanced-media handler / compounds / config UI / downloads history below)
```

⚠️ **Git history diverges from remote under different hashes** (Bill rebases/deploys via
Beanstalk). Judge "ahead of remote" by CONTENT (`git diff --stat origin/development HEAD`), not
commit count, and after any merge scan for markers with `git diff --check` (and `--cached`), not
`grep`. The media-proxy work landed as the single clean commit `cce2a884` on top of the prior tip.

---

## 3. Live status (was: "deliberately not live yet")

The old warning here — *do not swap `itemMedia` → `advancedMedia`* — is **obsolete**. Stage (c)
shipped the Mirador/UV adapters, so image items no longer regress, and the swap has been made:

- Site 1 (`digital-collections`): `theme_settings_pitts3.resource_page_blocks.items.above`
  now includes `advancedMedia` (and `advancedMediaDownloads`).

The block is a site setting, so the swap is reversible from the theme's resource-page-block
configuration without code.

---

## 4. What is implemented

### Stage (a) — engine

| Piece | Location |
|---|---|
| Player interface / base | `src/Media/Player/PlayerInterface.php`, `AbstractPlayer.php` |
| Player registry | `src/Media/Player/Manager.php`, config key `advancedmedia_media_players` |
| Resolver (display-time cascade) | `src/Media/PlayerResolver.php` |
| View helper (single media) | `src/View/Helper/AdvancedMediaPlayer.php` → `advancedMediaPlayer($media)` |
| Resource-page block | `src/Site/ResourcePageBlockLayout/AdvancedMedia.php` |

**Resolver cascade:** per-site → global → shipped default → `core-native`. Within each tier:
exact MIME → top-level wildcard (`audio/*`) → `default`. Every candidate is validated via
`supports()` before use.

**Players:** `core-native`, `download`, `html5-audio`, `html5-video`, `thumbnail-link`,
`circular-audio`, `transcript`.

### Stage (b) — compounds + (c) — structures

Detection was refactored OUT of compounds into a first-class **Structure** registry (many-to-many:
a structure can be rendered by several compounds; structures never depend on an installed viewer,
which is what keeps a config row stable). Compounds RENDER a group a structure hands them.

| Piece | Location |
|---|---|
| Structure registry | `src/Media/Structure/Manager.php`, key `advancedmedia_structures` |
| Structures | `AudioWithTranscript`, `EmbedWithPoster`, `MultipleImages`, `SingleImage`, `PagedDocument` |
| Compound registry | `src/Media/Compound/Manager.php`, key `advancedmedia_compound_types` |
| Compounds | `Identity`, `AudioTranscript`, `InteractiveResource`, `Mirador`, `PagedDocument` |
| Composer | `src/Media/Composer.php` |
| Compound resolver | `src/Media/CompoundResolver.php` |
| Whole-item helper | `AdvancedMediaCompose` → `advancedMediaCompose($item)` |

**Composer algorithm:** compounds run highest-priority-first, greedy-claiming from the pool of
not-yet-claimed media; `identity` (priority `PHP_INT_MIN`) mops up the rest. Groups render in
first-claimed order, so an item can host several compounds at once.

⚠️ **Paged Document defaults to `identity`, not `pagedDocument`.** The UV adapter works, but the
IIIF stack serves a PDF canvas with no image service, so UV renders a black canvas — worse than
the download link PDFs get now. `pagedDocument` stays registered and selectable; flip the default
the moment the IIIF stack serves PDF page images. See the comment in `module.config.php`.

### (c) — configuration UI

| Piece | Location |
|---|---|
| Options assembler (both scopes) | `src/Config/ConfigOptions.php` |
| Writer (prune + merge-preserving) | `src/Config/ConfigWriter.php` |
| Global form | `Module::getConfigForm()` → partial `common/config/advanced-media-config` |
| Per-site page (own admin route) | `src/Controller/SiteAdmin/IndexController.php` |

**Live inheritance, NOT copy-on-save.** Sites store only rows they explicitly override; the rest
resolve to global at display time. Every row carries both its stored value (`''` = inherit) and
its inherited value, so the null state can name what it falls back to — losing that distinction
silently freezes a site against future global changes.

**The prune is load-bearing.** Inheritance is expressed as row *absence*; `''` is the form's
inherit marker and must be stripped before write. `mergePreserving()` keeps rows the form never
showed (a media type whose last file was deleted, a structure from a disabled module), so a save
never destroys configuration it did not display.

The per-site config is a **first-class admin page**, deliberately NOT a section of core's
`SiteSettingsForm` — which removed the sparse-save trap (core's unconditional site-settings save
loop no longer touches our keys) at the source.

#### Display policies (booleans, not maps)

Two on/off settings sit under **General settings** at both scopes. Each is a plain checkbox at
global scope (the base — it has nothing to inherit) and a tri-state select at site scope
(`'1'` | `'0'` | `''` = inherit, stored as row absence, same as every map row).

| Setting | Key | Default | Effect |
|---|---|---|---|
| Only display primary and grouped media | `advancedmedia_primary_and_compound_only` | **on** | *Whether* a file appears: a loose media that is neither primary nor claimed by a compound is hidden. Enforced in the identity compound. |
| Display primary media first | `advancedmedia_primary_first` | **off** | *Where* it appears: hoists the group holding the primary media to the front; everything else keeps attachment order. Enforced in `Composer::applyPrimaryFirst()`. |

Both resolve through **`src/Config/ScopedFlag.php`** (site → global → coded default) — one
implementation, so a second policy cannot drift from the first, and neither can read a stored
`''` as "off".

Primary-first is **off by default on purpose**: attachment order is curatorial, not accidental —
Omeka gives editors a drag-and-drop ordering UI on the item, and an editor who put a contextual
image ahead of the primary video meant it. It moves the whole *group*, never the media, so a
primary that is one image of a Mirador set or one page of a document does not get torn out of its
viewer. (Client request, returned from review 2026-08-12.)

#### PDF handling + the whole-item-viewer invariant (2026-08-12)

Omeka core registers file renderers for **audio and video MIME types only**. `application/pdf`
has no entry, so `Media\Renderer\File` falls through media type → extension → **`thumbnail`**:
a poster image linking to the original. *There is no PDF reader anywhere in core* — "Omeka
default" on a PDF was never going to be one.

| Piece | Location |
|---|---|
| Native PDF reader (player) | `src/Media/Player/PdfEmbed.php` (`pdf-embed`) + `asset/css/pdf-embed.css` |
| Shipped mapping | `application/pdf` → `pdf-embed` in `default_players` |
| Whole-item-viewer flag | `CompoundInterface::isWholeItemViewer()`, enforced in `Composer::compose()` |

The player uses the **browser's own viewer** via `<object>`, not PDF.js. Decisive reason:
`<object>`/`<iframe>` loads are not CORS-gated, so it works against cross-origin S3 files as-is,
whereas PDF.js fetches by XHR and would make a display choice depend on the media proxy being on
or bucket CORS being extended. PDF.js remains a sensible *second* registered player. Known
limitation: iOS Safari renders PDFs inline badly and, because it claims the type, never shows the
`<object>` fallback — hence the download link sits **outside** the object.

**`paged-document` is PDF-specific by design** (architecture.md §6.0), not a misfiled IIIF-image
row; paginated *image* viewing is `single-image` / `multiple-images` → Mirador. What was
misfiled was its "refuse items that also have images" rule. That rule's real subject was that
**UV and Mirador draw the whole item regardless of the group handed to them**, so two on a page
render the item twice. Stated as a PDF-vs-image test it stayed in force even when the row was
mapped to a per-media renderer — which is why mixed PDF+image items showed no PDF at all.

The constraint now lives on the compound (`isWholeItemViewer()`: true for Mirador and the
UV-backed Paged Document) and is enforced once, in the Composer: **at most one whole-item viewer
per page**, highest-priority structure wins, later ones degrade to individual rendering. Map the
row back to UV and the protection re-arms by itself. Verified both ways on items 104486 (mixed)
and 58 (PDF-only).

**Related fix — the identity gate.** `Identity` is both the floor *and* the renderer for any
structure row set to "None". The display policy was gating both, so structure-claimed media were
hidden even though the policy is named "…primary **and grouped** media". The Composer now marks
claimed groups and the gate applies only to the floor. Today this changes only `paged-document`
(the one structure whose shipped compound is `identity`); ~83 previously-hidden PDFs on site 1
become visible, which is the intended outcome.

#### Recordings with images — `av-with-images` (2026-08-12)

`IiifServer\Iiif\TraitMediaInfo` builds a manifest's canvases from **exactly one** media type,
by a hardcoded priority chain:

```
Model  >  Video  >  Sound  >  Image  >  Text
```

on the stated assumption that a recording plus an image is "a podcast with a cover image". So on
**any** item holding audio or video, every image is demoted out of the canvases into the
manifest's `rendering` array. Verified on item 104462: one `Sound` canvas, JPEG and PDF both in
`rendering`. No setting gates the chain.

The visible symptom: `single-image` claimed the image, handed it to Mirador, and Mirador — which
renders the whole item manifest — found only the audio and played the **recording** inside the
image viewer, while the image appeared nowhere.

| Piece | Location |
|---|---|
| Structure (priority 95) | `src/Media/Structure/AvWithImages.php` (`av-with-images`) |
| Compound | `src/Media/Compound/AvWithImages.php` (`avWithImages`) + `view/common/compound/av-with-images.phtml` |

Claims **audio or video plus one or more images** as a single group, so the images never reach an
image viewer that structurally cannot show them. Both breadths are deliberate: the IIIF rule
suppresses *all* image canvases (so a strict single-image test would let a two-image item fall
through and silently reproduce the bug), and Video sits *above* Sound in the chain (so video+image
breaks identically — no such items exist here yet).

The compound sends **audio through `circular-audio` directly**, mirroring `AudioTranscript`. That
is a deliberate override of the players band: it gives these items the house audio player without
an admin remapping `audio/*` globally, which would also catch every loose audio file in the
install. Video and images go through their **resolved** players, so the band still governs them.

`audio-with-transcript` (priority 100) now also claims any images, attaching them to the first
audio+VTT pair — otherwise a transcript item's image would fall through to Mirador in exactly the
same way. Currently affects **0** items (no item has audio + a real `.vtt` + an image); it is
preventive. `AudioTranscript::render()` consequently selects group members **by type** rather than
by position — it previously destructured `[$audio, $vtt]`, which would have silently dropped the
images.

Affected today: **7 items** on site 1, all 1 audio + 1 image, five also holding a PDF. All seven
verified rendering circular player + image + PDF reader, with no Mirador.

> **Data note:** the collection holds 94 real `.vtt` files but also ~100 **`.txt`** transcripts.
> `audio-with-transcript` requires WebVTT (the sync needs cue timings), so `.txt` transcripts are
> claimed by nothing and are currently hidden by the display policy. Two of the seven items above
> have one. Not addressed — flagged for a product decision.

### Downloads block (stage — first pitts-2026 deliverable)

| Piece | Location |
|---|---|
| Block | `src/Site/ResourcePageBlockLayout/Downloads.php` (`advancedMediaDownloads`) |
| Template | `view/common/resource-page-block-layout/downloads.phtml` |
| Resolver | `src/Media/DownloadResolver.php` |
| Labels + sizes | `src/Media/MediaTypeLabel.php` |
| Download URL helper | `src/View/Helper/AdvancedMediaDownloadUrl.php` → `advancedMediaDownloadUrl($media)` |

Independent of the compound/player pipeline: the block walks `$item->media()` directly, so
compounds do not affect it, and a media can show a viewer AND appear here. Config lives on the
same page as the players — a **tri-state select** (`Use global` / `Yes` / `No`) per media type,
keyed `advancedmedia_downloads` (`'1'`/`'0'` strings so `'0'` survives prune/merge).

**A checkbox cannot be used** — an unchecked box submits nothing, so the row is absent from the
POST and read as "the form never showed this, keep it", making the switch un-turn-off-able. The
select is the fix.

Threshold `advancedmedia_download_threshold` (global-or-site, `0` = unlimited, default `10`)
suppresses a media type when an item holds more than N of it — counted PER TYPE, so a 600-page
image item still offers its one PDF. Shipped default is **opt-in** (`default => '0'`): nothing is
downloadable until an admin says so, which keeps VTT sidecars and derivatives out.

Filters: `hasOriginal()` + `size > 0` (drops the 24 zero-byte `x-empty` records), attachment
order.

### Media URL proxying (optional, off by default) — see [`media-routing.md`](media-routing.md)

| Piece | Location |
|---|---|
| Proxying store (overrides `getUri()`) | `src/File/Store/ProxyingAwsS3.php` |
| Factory | `src/Service/File/Store/ProxyingAwsS3Factory.php` |
| Delegator (registration) | `src/Service/File/Store/ProxyingAwsS3Delegator.php` |
| `/files/*` controller | `src/Controller/FileController.php` |
| Reachability probe | `src/File/ReachabilityProbe.php` |
| Serve-mode detector | `src/File/ServeModeDetector.php` |
| Fetch-failure diagnostics | `src/File/FetchDiagnostics.php` |

Makes S3-backed media resolve to Omeka's own canonical `/files/{storagePath}` shape on THIS host
instead of a bucket URL, so bot/harvester access can be governed by network rules. `/files/*` is
served by the stock `.htaccess` try_files pattern (disk if present, else our controller).

**Serving: a direct 200, NEVER a 302.** A redirect fails twice — it leaks the bucket URL (a bot
bypasses the rules this feature enforces), and ImageServer's source fetch accepts only a first
status line of `200 OK`, so a 302 makes tiling 500. Both `/files/*` actions (inline and
`/files/download/*`, folded into one chokepoint) answer 200 by an auto-detected mode:
**X-Accel-Redirect** (nginx zero-copy, needs the snippet on the config page) or **PHP streaming**
(256 KiB chunks, universal fallback). `ServeModeDetector` self-probes on each config-page load
whether nginx handles X-Accel and caches the mode (`advancedmedia_serve_mode`); **default is
stream**, so a missing snippet degrades to slower, never broken. Full detail: [`media-routing.md`](media-routing.md).

**Off by default, and gated.** Enabling runs a server-side reachability probe and refuses if the
server cannot reach its own base URI — because when it can't, the symptoms surface far from the
cause (ImageServer 500s, transcripts silently empty). The download route and URL helper are
**NOT** gated on the proxy flag: downloads work on any install, proxying on or off.

### (§4.4) Store override is a delegator, not runtime setFactory

`ProxyingAwsS3` replaces `AmazonS3\File\Store\AwsS3` via a `delegators` entry in
`module.config.php` (targeting the **concrete class**, so ImageServer's direct
`get(AwsS3::class)` in `TileInfoFactory` is caught too). This is the only **order-independent**
override: config `factories` lose to alphabetical merge, and a runtime `setFactory` worked only
by the luck of `AdvancedMedia` sorting before `AmazonS3`. Verified: both the `Omeka\File\Store`
alias and the concrete class resolve to the same `ProxyingAwsS3` instance.

### Mirador page links (2026-09-14, uncommitted) — see [`mirador-page-links.md`](mirador-page-links.md)

`?page=N` (1-based) opens any Mirador viewer on that canvas; paging rewrites the URL with
`replaceState`; `&viewer=K` targets the K-th viewer on the page. A delegator on the Mirador
module's `mirador` view helper (`MiradorPageLinksDelegator` → `View\Helper\MiradorPageLinks`)
appends `asset/js/mirador-page-links.js` after Mirador's own `mirador.js`. That ordering lets the
script set `windows[0].canvasIndex` before Mirador boots — no plugin, no bundle rebuild, no edits to
the Mirador module or theme. No settings. Verification record: spec §7.

### CORS design (important, non-obvious — unchanged)

A cross-origin `<track>` requires the media element's `crossorigin` attribute — **but that
attribute also puts the MEDIA into CORS mode**, so on a host without CORS headers the *audio*
dies, not just the captions. `circular-audio.js` renders **no** `crossorigin` and a `<track>`
with `data-src`, probes the host with a CORS fetch (`cache: 'reload'` — pre-CORS cached responses
carry no `ACAO` and poison the check), and only upgrades to `crossOrigin = 'anonymous'` if the
probe passes, reverting if the media then fails. Captions fall back to server-parsed cues, so
they work with **zero CORS**.

---

## 5. How it is verified

No test suite. Verification uses **throwaway harness scripts** run inside the container
(`docker exec pitts-2026-omeka php ...`), then deleted. Bootstrap with
`Omeka\Mvc\Application::init(...)` (NOT `Laminas\Mvc\Application` — only the Omeka one loads user
modules), then resolve real services and drive them against the live `pittsviv_os1` DB and real
S3. This session verified, green on **Omeka 4.2 / PHP 8.3.20**:

- The store swap (alias + concrete class → one `ProxyingAwsS3`); opt-in off is byte-identical to
  stock AmazonS3.
- Downloads resolver: opt-in default, exact-MIME-beats-wildcard cascade, threshold (10 and 0),
  VTT/empty exclusion; the tri-state save round-trip including the `'0'`-survives-save case.
- The probe discriminating all five failure modes, and the enable-gate refusing/allowing
  correctly (this dev env is itself an unsupported config — the probe correctly refuses).
- `/files/*` inline + download routes, presigned `Content-Disposition`, path-traversal 404s.
- Fetch-failure diagnostics record/persist/dedupe/surface/clear.

**DB note:** the live database is `pittsviv_os1`, not the compose default `omeka_default` — see
`codebase/config/database.ini`. Media `size` is fully populated (0 nulls / 85k rows); `source`
is often a full S3 URL on sideloaded imports (basename() handles it).

---

## 6. Decisions already locked (don't relitigate)

- **Name:** `AdvancedMedia`.
- **Standalone** — no dependency on the `Common` library module.
- **No hard viewer dependencies** — availability is a capability concern; an adapter whose module
  is absent vanishes from the config dropdowns.
- **Display-time resolution**, never ingest-time.
- **Config keys off resource TEMPLATE, not class** — 98% of items have no class; templates encode
  the editorial distinctions.
- **Downloads and proxying are separate features** — never recouple the download URL to the
  proxy opt-in.
- **Proxying is a nice-to-have**, off by default, only worthwhile where an admin wants
  network-level bot control; it does not work in every environment and carries a per-fetch cost.
- **Third-party modules are not patched** — the proxy's server-side-URL problem is gated by the
  probe, not solved by editing ImageServer/IiifServer (which would break on every upgrade).
- Build sequence: (a) → (b) → (c) → (d) → (e).

---

## 7. Next

- **(d)** Per-resource exception table `(resource_id, site_id, context)` + its editor. Sits above
  the per-site tier in the resolver cascade.
- **(e)** Theme-settable fallback thumbnails.
- **Flip `pagedDocument`** default the moment the IIIF stack serves PDF page images (§4).
- **Config label bug (known, deferred):** the tri-state "Use default (currently: X)" null-state
  label computes its inherited value from the SHIPPED map only, ignoring the admin's own
  same-scope broad rows (`image/*` wildcard, "Everything else") — even though the resolvers DO
  cascade to them at display time. Proven mismatch (set global download `image/*`=Yes, leave
  `image/tiff` on inherit → label says "No" but display offers it). Affects all three columns
  (shared `expandPlayerInheritance`). Fix = fold same-scope broad rows into the inherited value;
  keep the tri-state, just make the label honest.
- **Production validation of proxying** (dev is confirmed working): with proxying on, streaming
  restores tiling immediately; installing the nginx snippet flips to zero-copy on the next
  config-page load. A reachability *pass* still hairpins through the proxy in stream mode —
  measure the worker/latency cost, and whether the public base URI should be made internally
  resolvable (Docker `extra_hosts` / nginx upstream) so unknown third-party server-side fetchers
  degrade rather than fail.

---

## 8. Known limitations carried into v1 (deliberate)

- **Positional** audio↔VTT pairing, not role-based (multiple audio+VTT sets per item deferred).
- `VttParser` fetches the VTT **over HTTP on every render** — no caching. On the hot path.
- The circular player still **depends on jQuery**.
- `srclang="en"` hardcoded on the caption track.
- Only the `page` context is exposed (the resolver/schema thread a `context` key; adding `card`
  later is data, not migration).
- Downloads render in a flex row (theme `.download`); many buttons would wrap. Fine at current
  counts (PDF/audio ≤ 2 per item); revisit if image downloads are enabled.
- **Mirador viewers that boot hidden** (closed tab / accordion / modal) have a non-functional
  thumbnail strip — a Mirador 3.4.9 bug, independent of this module. With `?page=N`, its one console
  error fires at load instead of at the first page turn (`mirador-page-links.md` §3.8).

---

## 9. Environment gotchas that bite this module

Full detail in [`../../../_media_iiif_stack_README.md`](../../../_media_iiif_stack_README.md) and
[`media-routing.md`](media-routing.md). The ones specific to AdvancedMedia:

- **Container CA trust store** must be initialised (`update-ca-certificates`), or *every* HTTPS
  fetch from PHP fails — including `VttParser` loading a VTT from S3, and the reachability probe
  over https. Tell: `get_headers()` returns `false` rather than a status array.
- **The server may not be able to reach its own public base URI** (Docker port mapping, split DNS,
  egress rules, TLS). This is what makes media proxying environment-dependent; the probe exists to
  surface it. In THIS dev stack Apache listens on :80 while the public URL is `localhost:8080`, so
  the server cannot reach itself — proxying's probe correctly refuses here.
- **S3 CORS** must include the exact origin. Without it the native caption track can't load (the
  server-parsed fallback still captions).
- **VTT files are commonly `text/plain` with a `.vtt` extension** — VTT detection must fall back
  to the file extension (`AudioWithTranscript` / `AudioTranscript` do this; not optional).
- **`originalUrl()` is a remote (S3) URL, and server-side fetchers assume it is reachable.** Stock
  Omeka never exposes the conflation; media proxying does. See [`media-routing.md`](media-routing.md).

---

## 10. Open items

- **Delegator refactor is uncommitted** (§2, §4.4).
- **Mirador page links are uncommitted** (§4) — 4 new files + `module.config.php`, `README.md`, docs.
- **`php.ini` decision (infra repo):** `config/php/php.ini` is repo-tracked with a dev-tuned
  memory limit that may be too high for some servers; the sample-file split is deferred, not
  forgotten.
- Environment/third-party patches (IiifServer manifest cache, ImageServer tiler) live in the same
  sub-repo but are unrelated — keep them out of AdvancedMedia commits.
