# Advanced Media Handling Module — Architecture

**Status:** Draft for review — planning only, no implementation yet.
**Target platform:** Omeka S 4.2 (verified against `codebase/application` source).
**Supersedes (media pieces of):** `AgileThemeTools` module + media logic embedded in the `pitts3` theme.

> This document is the outcome of a design conversation. It records *decisions and rationale*, not final code. Section 12 lists what is deliberately deferred. Section 13 is the appendix of verified core behavior the design rests on.

---

## 1. Purpose & scope

Omeka S's native media handling is primitive for our needs. This module adds a configurable, contextual layer for **choosing** and **composing** media viewers — without reimplementing the viewers themselves.

### The four client concerns this addresses

1. **No MIME→viewer mapping.** Core picks a viewer by a renderer-name string stamped at ingest; only audio/video are MIME-routed, everything else falls to a clickable thumbnail. There is no UI to say "render this media type with this viewer."
2. **Poor compound-object support.** The only first-class compound model is Mirador paginating images via an IIIF manifest. Audio+VTT, document+transcript, etc. have no native "these belong together" concept.
3. **No per-item / per-class media handling.** Nothing keys viewer behavior off the item, its resource class, or a per-item override. The client specifically wants "show as a download button, but only for certain items."
4. **Hardwired fallback thumbnails.** The "ugly undersized icon" is a small PNG chosen by `thumbnails.fallbacks` in PHP config — no admin UI, no per-site/per-theme control.

### In scope for v1

- A **player registry** that makes viewers discoverable and capability-described.
- A **display-time resolver** that maps media → player via a contextual cascade (global → site → per-resource exception), with a reserved `context` dimension.
- A **compound composer** with a small set of programmatic, themeable compound types (starting with Audio+VTT and Images→IIIF), an always-on structural-detection floor, and resource-class-driven preferences.
- A **download-as-player** plus an aggregate **Downloads** resource block.
- **Theme-settable fallback thumbnails.**
- **Two-tab configuration UI**, mounted globally (module config) and per-site (site settings).
- **Migration** of the reusable AgileThemeTools media pieces into this model.

### Deferred (see §12)

Media clustering/roles (multiple audio+VTT sets per item), non-`page` display contexts (cards, etc.), a user-configurable grouping framework, per-media-class signal reliance.

### 1.4 Module identity & dependencies

**Dependencies — soft discovery, no hard viewer deps.** Unlike `AgileThemeTools` (which hard-declares AdvancedSearch/IiifServer/ImageServer/UniversalViewer, and uses Mirador *without* declaring it), this module declares **no hard dependency on any viewer module**. Hard viewer deps would defeat the "adopt new viewers without lock-in" premise and make the module brittle.

Instead, **availability is a capability concern**: an adapter player advertises empty capabilities (and `supports() === false`) when its backing module/helper isn't installed, so it never appears in config dropdowns and is never resolved — no bootstrap gymnastics, no install-order coupling. Mirador, Universal Viewer, and IiifServer/ImageServer are therefore *optional*: present when installed, silently absent when not. The only candidate hard dependency is the shared `Common` library module (optional — decide during build, §14).

---

## 2. How Omeka handles media today (the gap we build on)

Verified against 4.1.1 — full citations in §13.

- **Viewer is chosen by a stored renderer-name**, set by the ingester at upload and never changed. `MediaRepresentation::render()` → `Media` view helper → `media_renderers` manager keyed by that name.
- **Two stacked renderer systems:** `media_renderers` (keyed by renderer name) and `file_renderers` (reached only when the name is `file`; this is the only place a MIME/extension→viewer alias table exists, and it only distinguishes `audio` / `video` / fallback-thumbnail).
- **Resource-page blocks** compose the item show page from named, resource-scoped, region-ordered blocks. **They are stateless** — the interface is only `getLabel()`, `getCompatibleResourceNames()`, `render($view, $resource)`. No per-instance settings.
- **Thumbnail fallbacks** resolve exact-MIME → top-level-type → `default.png` from `thumbnails.fallbacks`; assets live in `application/asset/thumbnails/`. Configurable in PHP only.

**Design consequence:** because viewer selection is ingest-bound and there is no MIME→viewer table, we introduce our own **display-time** selection layer. Because resource-page blocks can't hold config, our configuration lives in a **settings store** and the block is a pure consumer.

---

## 3. Architecture overview

Three layers, all reading from a central **Player Registry**:

```
            ┌─────────────────────────────────────────────┐
            │              PLAYER REGISTRY                 │
            │  players (native + adapters) + capabilities  │
            │  compound types                              │
            └───────────────┬─────────────────────────────┘
                            │ queried by
   ┌────────────┐   ┌───────┴────────┐   ┌────────────┐
   │  COMPOSE   │──▶│    RESOLVE     │──▶│   RENDER   │
   │ group the  │   │ pick a player  │   │ emit HTML  │
   │ item media │   │ per media/     │   │ into the   │
   │ (compounds)│   │ group (cascade)│   │ resource   │
   └────────────┘   └────────────────┘   │ block      │
                                         └────────────┘
```

- **Compose** (§6): given an item's media, decide grouping. Default = "each media on its own, in attachment order" (the *identity compound*). Compound types claim subsets.
- **Resolve** (§5): given a media (or a group) in a `(site, context)`, pick the player via the cascade.
- **Render**: the chosen player(s) emit HTML, surfaced through a resource-page block (§7).

**Guiding principle:** we *orchestrate* viewers, we don't rebuild them. Mirador, Universal Viewer, YouTube, oEmbed, and the core file renderers become registry entries via thin adapters. Only genuinely new viewers (e.g. the circular audio player) are implemented natively.

---

## 4. The Player Registry

The spine of the system. A **plugin manager** (mirroring core's `resource_page_block_layouts`) that modules contribute to via config — so a third-party module ships a player just by adding a config entry.

### 4.1 Player interface (sketch)

```php
interface MediaPlayerInterface
{
    public function getName(): string;               // machine id, e.g. 'circular-audio'
    public function getLabel(): string;              // human label for config dropdowns

    /** Media types / MIME this player can render — drives the config UI filtering. */
    public function getCapabilities(): CapabilitySpec;

    /** Runtime check for a specific media in a specific context (validation guardrail). */
    public function supports(MediaRepresentation $media, array $context): bool;

    /** Emit the player markup. Options carry label, compound context, etc. */
    public function render(PhpRenderer $view, MediaRepresentation $media, array $options): string;

    /** Optional per-player settings (e.g. Mirador/UV config); null if none. */
    public function getConfigForm(?SiteRepresentation $site): ?Fieldset;
}
```

### 4.2 Capability model

Players declare support **per exact MIME**, not per friendly group. The config UI groups MIME into friendly buckets (Video / Audio / Image / Document) for the human, but resolution and the "which players are available for this row" filter operate at MIME granularity. This means a player that supports `video/mp4` and `video/webm` but not `video/quicktime` can be assigned to the "Video" group; for a `.mov` file the resolver simply falls through to the next cascade tier and the UI warns.

### 4.3 Native vs. adapter players

- **Native:** implemented in this module (e.g. `circular-audio`, `download`).
- **Adapter:** wraps an existing viewer. Two adapter styles:
  - *Renderer adapters* over core `media_renderers` / `file_renderers` (thumbnail-link, HTML, oEmbed, YouTube…).
  - *View-helper adapters* over module-provided helpers (`$this->mirador()`, `$this->universalViewer()`).

The **Download player** is a native player with universal capability — it renders any media as a download link. This, combined with display-time resolution and the override cascade, is what satisfies client concern #3 ("download button for certain items") with no special-casing.

### 4.4 Initial player roster (v1)

**Native (implemented here):**
- `circular-audio` — the migrated circular audio player (audio/*); carrier for the Audio-with-Transcript compound.
- `download` — universal capability; renders any media as a download link (migrated from `TextRenderer` / `download.phtml`).
- `transcript` — renders a VTT as a synced/plain transcript; primarily an *internal* piece of the Audio-with-Transcript compound rather than a standalone dropdown choice.

**Adapters over core renderers (always available):**
- `html5-audio`, `html5-video` — wrap core `file_renderers` audio/video.
- `thumbnail-link` — wrap core's clickable-thumbnail fallback.
- `youtube`, `oembed`, `html`, `iiif` — wrap core `media_renderers`.

**Adapters over viewer modules (availability-gated, §1.4):**
- `mirador` — image viewing via the Mirador helper.
- `universal-viewer` — paged document/PDF via the UV helper.

Availability-gated adapters advertise empty capabilities when their module is absent, so they vanish from the UI rather than erroring.

---

## 5. The Resolver (display-time selection)

Given a media in a `(site, context)`, return the player to render it with. **Always at display time** — never ingest — so the same media can render differently per site/context, new players are adoptable without data migration, and nothing is locked in.

### 5.1 The cascade (most-specific wins)

```
1. Per-resource EXCEPTION   (resource_id, site, context)          ← §8 table
2. SITE mapping             (site, context, exact MIME → group)   ← SiteSettings
3. GLOBAL mapping           (context, exact MIME → group)          ← module Settings
4. SYSTEM fallback          (core renderer / thumbnail-link)
```

Every candidate is checked with `player->supports()` before use; a candidate that fails validation is skipped and resolution continues down the cascade. A resolved player that can't actually render the media never reaches the page.

### 5.2 Contextual inheritance (live, not copy-on-save)

Site config stores **only rows an admin explicitly overrode**; unset rows resolve to global at display time. This keeps global changes propagating to sites that haven't overridden a given row. The uniform rule for any key (media-type/MIME, or resource class):

> **site value if set → else global value → else system fallback.**

This single rule covers both the never-configured new site and the partially-overridden site.

**UI consequence:** each dropdown needs an explicit **"Use global default (currently: X)"** null state, visibly distinct from an actively-chosen value that happens to equal global. Otherwise "inheriting" and "coincidentally matching" are indistinguishable and propagation breaks.

### 5.3 The `context` dimension (reserved now, exposed later)

Every mapping row and the resolver API carry a `context` key (default `page`). v1 only exposes `page`; the resource block declares the context it renders in. Adding a `card` context later (Drupal Display-Modes style) is *data*, not migration.

```php
$player = $resolver->resolvePlayer($media, ['site' => $siteId, 'context' => 'page']);
```

Deferred: the context-fallback rule (does an unset `card` mapping fall back to `page`, or straight to system default?) — decided when a second context ships.

---

## 6. The Composer (compound objects)

> **REVISED 2026-07-14 (supersedes the original §6).** Detection is split out of compounds into a
> first-class **Structure** registry. Rationale and the migration are in §6.0. The precedence model,
> the validation gate, and the identity floor are unchanged.

### 6.0 Structures and compounds are separate things

Originally each compound owned its own detector: `ImageGallery::claim()` was simultaneously *"what an
image set is"* and *"how I render it."* That conflation blocks three things we need:

1. **A stable config key.** The config UI's structural band needs rows that exist independently of which
   viewer modules happen to be installed. If a compound defines the structure, disabling a module removes
   the *row*, not just an option.
2. **Two renderings of one pattern.** "Show image sets with Mirador, but with a slideshow on this site"
   is unsayable when the pattern is welded to its renderer.
3. **Turning a detector off.** There is currently no way to say "don't auto-group images on this site."

So:

| | Owns | Registry | Depends on installed modules? |
|---|---|---|---|
| **Structure** | detection **and partitioning** of an item's media into groups | `advancedmedia_structures` | **No** (pure data-side) |
| **Compound** | `render()` of a group, and a declaration of which structures it supports | `advancedmedia_compound_types` | Yes (availability-gated) |

Many-to-many: several compounds may support one structure, and the admin picks between them
(config-ui-spec §5). **The capability contract is:** *supporting a structure means being able to render
**any** group that structure produces* — exactly parallel to a player supporting a MIME type.

**This is a naming exercise, not an invention.** Every existing `claim()` already *is* a structure
detector with a renderer attached; the refactor lifts it out. It also relocates `priority` to where it
belongs: claim order is a property of a **pattern's specificity** (audio+VTT is more specific than
"has audio"), not of a renderer. The current `InteractiveResource` priority 90 vs `ImageGallery` 50 is
a fact about patterns competing for media that is presently, and confusingly, encoded on renderer classes.

```php
interface StructureInterface
{
    public function getName(): string;      // 'multiple-images'  (namespace third-party: 'vendor:thing')
    public function getLabel(): string;     // "Items with 2 or more images"  ← the Tab-1 row label
    public function getDescription(): string; // human predicate, so the UI can explain WHY a row matched
    public function getPriority(): int;     // claim order = specificity of the PATTERN

    /** @return MediaRepresentation[][] Occurrences; each group is rendered by one compound. */
    public function detect(ItemRepresentation $item, array $remainingMedia): array;
}

interface CompoundInterface   // (revised)
{
    public function getName(): string;
    public function getLabel(): string;              // "Mirador"
    public function getSupportedStructures(): array; // ['single-image', 'multiple-images']
    public function isAvailable(): bool;             // backing module present?
    public function render(PhpRenderer $view, array $mediaGroup, array $context): string;
}
```

### 6.0.1 Primary media — an *optional* predicate

Omeka's **primary media** designation is meaningful and deliberately used here, and structures may consult it.

- All 27 Interactive Resources have the **HTML embed as primary media**, 26 of them at *position 2* — a
  curator explicitly overrode attachment order to say *"the embed is the thing; the image is a poster."*
- **But it is sparse:** of 5,659 media-bearing items, only **809** have a primary media set. The entire
  bulk-imported collection (4,734 `Full Description (Item)`, 109 audio items) has none.

> **Rule: a structure MAY consult primary media; NO structure may REQUIRE it.** Requiring it would silently
> miss 86% of this collection — the same trap as keying on resource class.

All detection therefore goes through one shared accessor:

```php
primaryOrFirst($item)   // the designated primary media, else the first media by position
```

which degrades to attachment order (what every detector already implicitly assumed), while honouring an
explicit curatorial designation wherever one exists.

This makes the Interactive Resource rule **declarative** rather than heuristic. It was:
*"one html + one image + nothing else in the pool"* — reverse-engineering from media counts what the
curators had already stated outright. It becomes:

> **Embed with poster** — the item's primary media is an HTML embed, plus an optional single image.

Which also disposes of the `pdf + image + html` items for the *right* reason: their primary media is not
the embed, so the structure does not fire, and their thumbnail correctly stays with the image gallery.

### 6.0.2 The starter structure set

`single-image` and `multiple-images` are **separate structures**, both supported by Mirador and both
defaulting to it (so parity holds and nothing regresses). Splitting them costs nothing and hands curators a
distinction they cannot currently express — *"one image? just show it. A set? give me the viewer."*
Structures are allowed to be finer-grained than the shipped defaults need: the defaults collapse them, the
admin can pull them apart.

| Structure | Detects | Items here | Priority | Shipped default compound |
|---|---|---|---|---|
| `audio-with-transcript` | audio media paired with a VTT | 3+ | 100 | Audio with Transcript |
| `embed-with-poster` | **primary media** is an HTML embed (+ optional single image) | 27 | 90 | Interactive Resource |
| `multiple-images` | 2 or more image media | ~900 | 50 | Mirador |
| `single-image` | exactly 1 image media | 1,837 | 50 | Mirador |
| `paged-document` | a PDF *(stage c)* | 2,159 media | 40 | Paged Document (Universal Viewer) |
| *(none — the floor)* | whatever nobody claimed | — | `PHP_INT_MIN` | Identity (individual media) |

**Greedy claiming is retained and is a feature:** an item with 3 images *and* an audio+VTT pair matches two
structures and renders both compounds. A config row therefore means *"wherever this pattern occurs"*, **not**
*"for these items"* — the UI copy must say so, or admins will read rows as item-level rules. Mis-detection is
a real future risk; four levers exist before it becomes a problem (structure priority, the per-structure
`None`, template overrides outranking the structural band, and per-resource exceptions in stage (d)), so it
is deferred honestly rather than hopefully.

### 6.1 Model

Four precedence layers, all gated by validation:

```
CEILING   Per-resource override        (force/suppress a compound on this item)      ← stage (d)
   │      Template preference          (site/global template → compound mapping)     ← selector; class supported but not surfaced
   │      Structure default            (site/global structure → compound mapping)    ← always-on detection, configurable renderer
FLOOR     Identity compound            (each media → its resolved player, in order)
                    └── VALIDATION gates every layer ──┘
```

- The **identity compound** is the default and is itself a compound (lowest priority, claims whatever nobody else claimed). One mental model: *everything is a compound.*
- **Structural detectors** run always and need no config — e.g. "an audio media that has an associated VTT" → Audio+VTT compound. This is the correctness floor for institutions that don't classify.
- **Resource class is a signal and a config key, not a trigger.** A site maps `Podcast → podcast-layout`, `Sound → audio+vtt`, etc. Because compounds **validate against actual media**, an over-general class mapping self-corrects: a `Sound` item with no VTT fails the Audio+VTT validation and falls back to plain audio. Class nominates a compound; it never forces an impossible rendering.
- **Override** (top) forces or suppresses a compound for a specific resource.

### 6.2 Compound type interface (sketch)

```php
interface CompoundTypeInterface
{
    public function getName(): string;
    public function getLabel(): string;              // shown in the class→compound dropdown
    public function getPriority(): int;              // claim order

    /** Which of the item's remaining media this compound takes (or null). */
    public function claim(ItemRepresentation $item, MediaPool $pool): ?MediaGroup;

    /** Required pieces present & compatible? Gates rendering. */
    public function validate(MediaGroup $group): bool;

    public function render(PhpRenderer $view, MediaGroup $group, array $context): string;
}
```

### 6.3 Composition algorithm

```
1. Gather the item's media into a pool.
2. Assemble candidate compounds, in precedence order:
     a. override-forced compound (if any)
     b. class-preferred compound (resolved via §5 cascade on the item's resource class)
     c. structural auto-detectors (always-on)
3. For each candidate (highest precedence first):
     - claim() a subset of the pool
     - validate() it; if invalid, release the claim and skip
     - on success, remove claimed media from the pool
4. Remaining pool → identity compound (each media resolved via §5).
5. Render groups in order of their first-claimed media (or explicit priority).
```

Greedy claiming means one item can legitimately host several compounds (an image gallery + an audio-description + loose PDFs), each rendered correctly. Class *nominates* one compound; it doesn't monopolize the item.

### 6.4 The Downloads block vs. the Download player

- The **Download player** (§4.3) renders a single media as a download link inline.
- The **Downloads resource block** aggregates selected media into one download section.
- Coherency rule: the Downloads block has an include/exclude setting for media already rendered inline as Download players, so a file isn't offered twice.

### 6.5 Initial compound set (v1) — **SUPERSEDED by §6.0; kept for the record**

> **The naming rule below was REVERSED on 2026-07-14, and the reversal follows from §6.0.**
>
> The original rule — *name compounds for the experience, not the underlying viewer* — existed because
> the compound was the only name an admin ever saw, so it had to describe the outcome. Splitting
> detection into **Structures** changed exactly that: the STRUCTURE now carries the experience
> ("Two or more images") and the COMPOUND is the *implementation chosen for it*. A row reads:
>
> ```
> Two or more images   →   [ Mirador ▾ ]      (also: Slideshow · None)
> ```
>
> Naming that dropdown "Image Gallery" would be both redundant (the row already says what the
> experience is) and ambiguous (there are many kinds of image gallery; there is one Mirador). The
> client also simply *says* "Mirador". So `imageGallery` → **`mirador`**.
>
> The original rule's real concern — *the backing viewer can be swapped without renaming things* — is
> now served better than before: swapping the viewer means picking a **different compound** in the same
> structure row, which is a config change, not a rename.
>
> Open, by the same logic: **Paged Document** is still experience-named while its backing viewer is
> Universal Viewer. Renaming it `universalViewer` would be consistent — and doubly so once a native
> PDF compound exists as an alternative for the same `paged-document` structure (§6.0.2). Not done;
> awaiting a decision.

| Compound | Renders | Backed by | Structure(s) supported |
|---|---|---|---|
| **Audio with Transcript** | Audio player with a synced, click-to-seek transcript | `circular-audio` + `transcript` (native) | `audio-with-transcript` |
| **Mirador** | Paginated image viewing via IIIF | Mirador + IiifServer (availability-gated) | `single-image`, `multiple-images` |
| **Paged Document** | Paged PDF/document viewing | Universal Viewer (availability-gated) | `paged-document` |
| **Interactive Resource** | A third-party HTML embed, poster suppressed | core HTML renderer | `embed-with-poster` |
| *(None — Individual media)* | Each media rendered by its resolved player, in order | identity compound | `*` — the floor, and the per-structure opt-out |

---

## 7. Rendering integration & the two admin surfaces

Media reaches the page through a resource-page block this module registers (compatible with `items`). The block is a **pure consumer**: it calls the Composer + Resolver for `context = page` and echoes the result. It stores no config (core resource-page blocks can't — §13).

Two distinct, non-conflicting admin surfaces:

| Surface | Owner | What it does | Where |
|---|---|---|---|
| **Block placement** | Core | Drop our media block into an item-page region | Existing per-theme "resource pages" UI (`theme-resource-pages`) |
| **Mapping config** | This module | The two config tabs (below) | Module config (global) + Site settings (per-site) |

### 7.1 Configuration UI — two tabs, one component

A single reusable fieldset component, mounted in two places (module config form → global Settings; site settings form → SiteSettings):

- **Tab 1 — Compounds:** `resource class → compound`. Lists **all** registered compounds under every class (no affinity filter — config time has no media context, so validation does the filtering per-item at display time). Per-class default = the identity compound ("None — render each media with its resolved player"). Leans on clear compound names.
- **Tab 2 — Players:** `media type + MIME → player`. Friendly grouped rows (Video/Audio/Image/Document) with per-MIME exception rows underneath. Dropdowns filtered by player capability (§4.2).

Both tabs render the "Use global default (currently: X)" null state when mounted at site scope (§5.2).

---

## 8. Storage schema

Three tiers, each on its native Omeka mechanism:

| Tier | Holds | Mechanism |
|---|---|---|
| Global defaults | class→compound, MIME→player, per context | module **Settings** |
| Site overrides | sparse per-row overrides | **SiteSettings** (under our key) |
| Per-resource exceptions | the rare "this item/media, against type…" | dedicated **table** |

### 8.1 Settings shape (global & site)

```php
// Settings / SiteSettings, keyed by context:
'advmedia_players'   => [ 'page' => [ '<mime-or-group>' => '<player-name>', ... ], ... ],
'advmedia_compounds' => [ 'page' => [ '<resource_class_id>' => '<compound-name>', ... ], ... ],
```
Site entries are sparse — absence means "inherit global."

### 8.2 Exception table

```sql
CREATE TABLE advanced_media_override (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    resource_id    INT          NOT NULL,          -- items/media/item_sets share one `resource` table (globally-unique id + type discriminator)
    site_id        INT          NOT NULL,
    context        VARCHAR(190) NOT NULL DEFAULT 'page',
    override_type  VARCHAR(190) NOT NULL,          -- 'player' (media) | 'compound' (item)
    override_value VARCHAR(190) NOT NULL,          -- player/compound name, e.g. 'download'
    UNIQUE KEY uniq_override (resource_id, site_id, context),
    CONSTRAINT fk_ovr_resource FOREIGN KEY (resource_id) REFERENCES resource(id) ON DELETE CASCADE,
    CONSTRAINT fk_ovr_site     FOREIGN KEY (site_id)     REFERENCES site(id)     ON DELETE CASCADE
);
```

- A single `resource_id` FK is unambiguous (the shared `resource` table self-identifies item vs. media).
- `ON DELETE CASCADE` on both FKs so overrides can't outlive their target.
- Sparse — exceptions only; the site×context multiplier only ever materializes rows when explicitly set.

### 8.3 Exception editing UX

The override editor lives as a repeatable **"Display overrides"** fieldset on the item/media admin edit page (where editors already are). Each row is:

- **Site** — selected from the sites the resource belongs to; the editor's primary/current site preselected.
- **Context** — `page` only in v1 (written implicitly; not surfaced until a second context ships).
- **Override** — a compound (for an item) or a player (for a media), including "Download".

`site_id` stays **NOT NULL** with a clean FK — no nullable-FK / NULL-unique-key wrinkles. For the common "make this apply everywhere" case, an **"Apply to all this item's sites"** convenience expands to one concrete row per site on save.

**Accepted v1 limitation:** a resource later added to a *new* site does not inherit an existing override — it gets the normal site/global defaults. Reasonable (a new site inheriting an exception is rarely intended) and revisitable. If cross-site "all sites" overrides become common, a future enhancement is a nullable `site_id` sentinel meaning "any site," slotted into the cascade just below site-specific exceptions.

---

## 9. Thumbnails & fallbacks

- Themes declare per-type fallback assets (via theme settings); the module overrides core's `thumbnails.fallbacks` from them, with an optional site-level override on top.
- Keep fallback resolution **per type** (audio icon vs. video icon vs. default) — some types should always show an icon, never a file thumbnail.
- Part of the "ugly undersized" problem is sizing/CSS: allow SVG / properly-sized assets and control display size, not just the file swap.
- ROI: fixes cards, browse, and media lists in one move, because all of them fall through the same fallback resolution.

---

## 10. Extension points used (core hooks)

- Service/plugin managers: our new `media_players` + `compound_types` managers; core `media_renderers` / `file_renderers` (+ `.aliases`) wrapped by adapters; `resource_page_block_layouts` for our block.
- Config keys: `thumbnails.types`, `thumbnails.fallbacks`.
- Events: `view_helper.media.render_options` (per-render options), `view_helper.thumbnail.attribs` (thumbnail classes/srcset).
- Settings: global `Settings`, per-site `SiteSettings`.

---

## 11. Migration from AgileThemeTools & pitts3

### 11.1 Lift largely as-is (into registry players / compounds)

- `src/AgileVttParser.php` — self-contained VTT parser. Rename the misnamed `convertToMicroseconds` (it returns **milliseconds**).
- The three `FileRenderer` classes (`CircularAudioRenderer`, `VttRenderer`, `TextRenderer`) + their `file-renderer/*.phtml` templates → become native registry players (`circular-audio`, `vtt`, `download`/`text`).
- `asset/js/circularAudio.js` + `asset/css/circularAudio.css` — the transcript-sync binding. Clean up on the way: stray `console.log(cues)`, the broken `*/` comment in the CSS, and the dead `::cue` rules.

### 11.2 Rework (theme-coupled logic → module)

- The `ItemMedia` resource-page block's viewer decision (image⇒Mirador, pdf⇒UV, audio⇒circular, no-MIME⇒raw) → becomes the Composer + Resolver. Its own comments already ask for this ("should probably be controlled as a setting", "consider implementing a listener here").
- The pitts3 `item-media.phtml` re-branching + inline MIME sniffing + the `audioPrimary && PDF ⇒ download` special case → dissolves into a normal override / Download player.
- The download-button "split contract" (`download-item-block.phtml` + theme PDF special-case + `TextRenderer` + `omekaAgileMediaDownloadSupport.js` DOM re-parenting) → unify into the Download player + Downloads block.
- `ItemHeader` block is marked `@todo UNTESTED` and references an undefined `$keyTerms` — treat as reference logic, not a drop-in.

### 11.3 Known limitations to design out

- One VTT per item, matched positionally (should be role/association-based — but clustering is deferred, see §12).
- `srclang="en"` hardcoded.
- `TextRenderer` re-reads file contents over HTTP on every render (add caching).
- The VTT↔native-track sync keys on exact integer-ms match between the PHP parser and the browser's track parse — fragile; revisit during the port.

### 11.4 Theme end state

Theme templates return to being "dumb": render whatever the module resolves. The unwieldy `_show.phtml` machinery (hardcoded allow-lists, try/catch viewer probing, raw `<object>`/`<video>`, the dead `viewer-pdf` branch, "viewer handling is now done via CSS") is retired.

---

## 12. Deferred (explicitly out of v1)

- **Media clustering / roles** — multiple audio+VTT sets in one item; per-media role tags. We accept "one compound of a given type per item, structurally auto-detected" as the v1 ceiling and re-evaluate once the architecture proves expressive. (Media carry property values, so roles have a natural future home with no new storage.)
- **Non-`page` contexts** (cards, search results, etc.) — the `context` seam is threaded now; only `page` is exposed.
- **User-configurable grouping framework** — rejected as a v1 goal; too many programmatic viewer constraints to make arbitrary user-defined groupings safe. Compounds stay programmatic/pluggable.
- **Context-fallback rule** — decided when a second context ships.

---

## 13. Appendix — verified core behavior (Omeka S 4.1.1)

All paths under `codebase/`.

- **Version:** `application/Module.php:25` → `const VERSION = '4.1.1'`.
- **Viewer selection is by stored renderer name:** `application/src/Api/Representation/MediaRepresentation.php` `render()` → `application/src/View/Helper/Media.php:115-128` → `media_renderers` manager; `$media->renderer()` returns a persisted column set by the ingester. MIME only routes inside `file_renderers.aliases` (`application/config/module.config.php` ~L825-847), which only maps `audio`/`video`.
- **Resource-page blocks are stateless:** `application/src/Site/ResourcePageBlockLayout/ResourcePageBlockLayoutInterface.php:14-30` has only `getLabel()`, `getCompatibleResourceNames()`, `render($view, $resource)`. Contrast `application/src/Site/BlockLayout/BlockLayoutInterface.php:44-65` (site-page blocks) which has `form()` + `onHydrate(SitePageBlock)` + a block entity.
- **Resource-page config is a flat name list in site settings:** `application/src/Site/ResourcePageBlockLayout/Manager.php:114-138` (reads `siteSettings->get($theme->getSettingsKey())['resource_page_blocks']`); saved at `application/src/Controller/SiteAdmin/IndexController.php:454-456`; `standardizeResourcePageBlocks()` (`Manager.php:170-187`) runs values through `array_map('strval')`.
- **Media carry their own resource class + values:** `application/src/Entity/Media.php:15` (`Media extends Resource`); `application/src/Entity/Resource.php:47-133` (`resourceClass` ManyToOne, `getResourceClass()`). In practice the item carries the descriptive class; media class is usually unset — so class-as-signal keys off the item and only optionally consults media class.
- **Thumbnail fallbacks:** `thumbnails.fallbacks` in `application/config/module.config.php` (~L240); assets in `application/asset/thumbnails/{default,image,video,audio}.png`; resolved exact-MIME → top-level-type → `default` in `MediaRepresentation::thumbnailUrl()`. PHP-config only, no admin UI.

---

## 14. Open items for the next review pass

**Resolved since first draft** (folded into §1.4, §4.4, §6.5, §8.3): exception-editing UX, initial compound set + names, initial player roster, module identity & dependency stance.

**Genuinely remaining before implementation:**
1. **Confirm the module name** — `AgileMedia` vs `AdvancedMedia` (branding call).
2. **Confirm compound labels** — provisional in §6.5; user-facing and hard to change post-release.
3. **Decide the `Common` library dependency** — use it or stay standalone.
4. **Sequence the build** — suggested order: (a) registry + resolver + Tab 2 (players) — delivers MIME→viewer, the biggest gap; (b) identity compound + Audio-with-Transcript migration; (c) Tab 1 (class→compound) + Mirador/UV adapters; (d) exception table + editor; (e) theme-settable thumbnails. Each stage is independently shippable.
