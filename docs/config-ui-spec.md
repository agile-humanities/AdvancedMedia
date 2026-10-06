# AdvancedMedia — Stage (c): Configuration UI — Specification

**Status:** Draft for review. No implementation yet.
**Depends on:** [`architecture.md`](architecture.md) §5 (resolver), §6 (composer), §7.1 (two tabs), §8 (storage).
**Prereq state:** stages (a) registry+resolver and (b) compounds are live; the module is now the site's media block.

> This spec covers **only** the configuration UI and the storage/inheritance mechanics it needs.
> The per-resource exception table is stage (d); thumbnails are stage (e). Neither is specified here.

---

## 1. What ships in this stage

1. **Two configuration surfaces**, sharing one component:
   - **Global** — the module config form (Modules → AdvancedMedia → Settings) → `Settings`.
   - **Per-site** — the site settings form (Site → Settings) → `SiteSettings`, sparse.
2. **Tab 1 — Compounds:** item signal → compound.
3. **Tab 2 — Players:** media type / MIME → player.
4. **Live inheritance** (site inherits global at *display* time, not copy-on-save), with an explicit
   "Use global (currently: X)" null state.
5. `module.ini`: `configurable = false` → `true`.

Not in this stage: per-resource exceptions (d), thumbnail fallbacks (e), any context other than `page`.

---

## 2. Verified core constraints (these shape the design — do not re-derive)

Checked against Omeka S 4.2 source in `codebase/application`.

### 2.1 Global (module config) — we own everything

`AbstractModule::getConfigForm(PhpRenderer)` returns **HTML we render ourselves**;
`handleConfigForm(AbstractController)` receives the POST and we write `Settings` ourselves
(`src/Module/AbstractModule.php:74,84`; called from `Controller/Admin/ModuleController.php:286,297`).

**Consequence:** at global scope we have full control of markup and save. Real tabs are trivial here.

### 2.2 Per-site — we are a *guest* inside core's form

Modules attach to `form.add_elements` on `Omeka\Form\SiteSettingsForm`
(`src/Form/SiteSettingsForm.php:457`). Core then saves with
(`src/Controller/SiteAdmin/IndexController.php` `editAction`):

```php
$settingsFormData = $settingsForm->getData();
foreach ($settingsFormData as $id => $value) {
    $this->siteSettings()->set($id, $value);
}
```

Three hard consequences:

| Consequence | Why | What it forces |
|---|---|---|
| **One top-level element name = one settings key**, value stored whole (arrays fine) | the `foreach` is over top-level `getData()` keys | our two tabs are **two top-level elements** holding array values |
| **Core writes our key on EVERY site-settings save** — even if the admin never opened our tab | the loop is unconditional | "inherit" must be represented by **row absence**, and empty rows must be pruned *before* `getData()` returns (an input filter via `form.add_input_filters`, `SiteSettingsForm.php:509`) — otherwise every unrelated save silently materialises empty overrides and **breaks inheritance** |
| **Nested `Fieldset`s get dropped** by the conventional module pattern | the established pattern (`ItemSetsTree/Module.php::onSiteSettingsFormAddElements`) registers an `element_group` and then does `foreach ($fieldset->getElements() as $element) $form->add($element)` — `getElements()` returns elements, **not** fieldsets | each tab must be a **single custom `Element`** (array-valued, rendered by our own view helper/partial), not a fieldset tree |

### 2.3 The chrome differs; the component does not

Core's site settings form is one long page with an `element_groups` sidebar — **it has no tabs.**
So "two tabs" is literal only at global scope. At site scope we register one element group
("Advanced media") containing the same two elements.

> **The shared thing is the element + its render partial + its inheritance logic — not the tab chrome.**
> Spec'ing this now avoids building a tab widget that cannot mount at site scope.

---

## 3. The Tab-1 key — a finding that must be settled before building

`architecture.md` §6 keys compound preference off **resource class**. The data says that key is inert here.

### 3.1 Evidence (this install, 5,886 items)

| Signal | Coverage | Notes |
|---|---|---|
| **Resource class** | **131 items (2.2%)** — 5,755 have **no class** | `dctype:Sound` 121, `dctype:Text` 7, `dctype:Image` 3 |
| **Resource template** | **5,878 items (99.9%)** — only 8 lack one | `Full Description (Item)` 5700, `Full Description (Audio item)` 121, `Weekly Workshop Event` 30, `Rural Churches (MATTERPORT ONLY)` 27 |
| **`dcterms:type`** (literal) | rich, but free-text | `Text` 3669, `Still image` 1945, `Artifact` 134, `Sound` 120, **`Interactive Resource` 27**, `Moving Image` 15, `Audio` 8, `sound recording-non musical` 3 |

The Interactive Resources make the point: all 27 carry `dcterms:type = "Interactive Resource"`,
**no resource class at all**, and sit in the dedicated template `Rural Churches (MATTERPORT ONLY)`.
A class→compound tab literally cannot address them. Built as specified, **Tab 1 would be a no-op for
98% of the collection** — every item would fall to the structural detectors regardless of what an
admin chose.

### 3.2 Candidate keys against the client's two constraints

The stated constraints: **(a) don't pollute metadata with display-oriented values; (b) don't require per-item settings.**

| Key | (a) No pollution | (b) No per-item work | Coverage | Verdict |
|---|---|---|---|---|
| Resource class | ✅ | ✅ | ✗ 2% | Correct in principle, empty in practice |
| **Resource template** | ✅ — a template is a *curatorial/structural* choice, not a descriptive value; routing on it adds **nothing** to any record | ✅ — set once per template, applies to every item using it | ✅ 99.9% | **Recommended primary key** |
| `dcterms:type` literal | ⚠️ — *reading* an existing descriptive value pollutes nothing (the pollution risk is **writing** display values); but display then depends on cataloguing prose | ✅ | ✅ high | Viable secondary; free-text drift (`Sound` / `Audio` / `sound recording-non musical`) makes it brittle as the sole key |
| Item set / collection | ✅ | ✅ | varies | Possible future selector kind |
| Structural detection (today) | ✅ | ✅ | 100% | The floor. Stays regardless — it is what makes over-general config safe |

Resource template is the only signal that is simultaneously **already populated, editorially meaningful,
and display-neutral.** It is not descriptive metadata, so mapping it to a compound cannot pollute a record.

### 3.3 ✅ DECIDED — generalise the *key*, keep the *design*

**Approved 2026-07-14.** Ship the **selector model**, with `template:` as the primary kind and `class:` retained
as a first-class alternative. Rationale, in the client's words: *templates give the best of both worlds — the
ability to create a controlled list of types that can map over displays*; class remains a sensible key for
curators who do classify.

`type:` (the `dcterms:type` literal) is **supported by the grammar but not shipped enabled** — the selector string
is open, so it can be turned on later with no migration. Its free-text drift (`Sound` / `Audio` /
`sound recording-non musical`) is exactly what a controlled template list avoids.

Do **not** hardcode "resource class" as the Tab-1 key. The key is a **qualified selector string**:

```
class:12                        # resource_class_id
template:4                      # resource_template_id
type:Interactive Resource       # dcterms:type literal   (if enabled)
```

Storage becomes `'<selector>' => '<compound-name>'` instead of `'<resource_class_id>' => '<compound>'`.

This is a **one-line change to the storage shape now** and a **schema migration later** — it costs
nothing today and preserves the architecture exactly as designed (class remains a first-class selector kind;
the cascade, validation, and precedence are untouched). Tab 1 then lists the selector kinds that are
actually populated, so the UI is useful on day one.

**Precedence when several selectors match one item** (most specific first):

```
1. template:  (a deliberate editorial grouping)
2. class:     (a semantic assertion)
3. type:      (a descriptive literal)      ← only if enabled
→ then structural detectors → identity
```

**Validation still self-corrects** (architecture §6.1): a selector only *nominates* a compound. A template
mapped to Audio-with-Transcript on an item with no VTT fails `claim()` and falls through. Nothing an admin
can pick in this UI can produce an impossible rendering.

A **controlled list of types that maps over displays** is precisely what a resource template already is — which is
why this key does the job that `dcterms:type` looks like it should do, without letting display depend on
cataloguing prose.

### 3.4 ✅ DECIDED — class is supported but NOT surfaced (2026-07-14)

Class and template are not two flavours of one signal. **Template answers *what shape is this item*** (a curator
authors one in response to a shape); **class answers *what is this thing about*** — a semantic assertion with no
reliable relationship to media shape. `dctype:Sound` covers sound+VTT, bare sound, and sound+PDF-libretto alike;
everything it could correctly say about *rendering* is already said better by MIME (Tab 2) or by structure
(the detectors). It is not a weaker predictor of shape than template — **it is not a predictor of shape at all.**

Two overlapping keys also impose a permanent cost: every *"why is this item rendering like that?"* question
requires holding a precedence rule in one's head, in exchange for a signal that does no work here.

**Therefore:** `class:` stays fully implemented in `CompoundResolver` (~10 lines, already built and tested) and
remains in the selector grammar — it is the *only* usable key in installs that run one generic template and lean
on classes. It is simply **not surfaced in the UI by default** (Band C toggle, §5). With one key kind visible,
there is no conflict-resolution logic to make scrutable. Nobody pays for flexibility they did not ask for.

---

## 4. Tab 2 — Players (media type → player)

### 4.1 Rows

Friendly groups, each with a wildcard row and optional exact-MIME rows beneath (architecture §4.2:
*group for the human, MIME for the machine*):

```
Audio        audio/*              [ html5-audio  ▾ ]
  └ audio/mpeg                    [ Use group default (currently: html5-audio) ▾ ]   ← 110 media
Image        image/*              [ (shipped default: core-native) ▾ ]
  └ image/tiff                    [ … ]                                              ← 3,602 media
  └ image/jpeg                    [ … ]                                              ← 79,319 media
Document     application/pdf      [ … ]                                              ← 2,159 media
Video        video/*              [ html5-video ▾ ]
…
Everything else   default         [ core-native ▾ ]
```

- **Seed the exact-MIME rows from what this install actually holds** (`SELECT DISTINCT media_type FROM media`,
  cached), not from an abstract MIME list. An admin can then only configure things that exist. Add an
  "add a media type…" control for the long tail.
- Real inventory today: `image/jpeg` 79,319 · `image/tiff` 3,602 · `application/pdf` 2,159 · html/no-MIME 383 ·
  `text/plain` 194 · `audio/mpeg` 110 · `video/mp4` 5 · plus `application/x-empty` / `inode/x-empty` strays.
- **`text/plain` is the VTT trap** (STATUS §9): VTTs are stored `text/plain` with a `.vtt` extension. A
  `text/plain → download` mapping would apply to VTTs too. The compound claims them first, so they never reach
  the resolver — but the row must carry a warning, because the interaction is non-obvious.

### 4.2 Dropdown contents

- Options = registered players whose **capabilities** include this MIME (architecture §4.2).
- **Availability-gated adapters simply do not appear** when their module is absent (§1.4) — no greyed-out rows,
  no errors.
- Always present: `core-native` (system fallback) and `download` (universal capability — this is what satisfies
  "show as a download button" with no special-casing).

### 4.3 Guardrails

- A stored player that is later **uninstalled or becomes incapable** must not break the page: the resolver already
  validates every candidate with `supports()` and skips it (§5.1). The UI surfaces such a row as a **warning**
  ("Mirador is no longer installed — this row is being ignored; effective: core-native") rather than silently
  rewriting the admin's stored choice. **Never auto-delete a stored preference** — the module may be reinstalled.

---

## 5. Tab 1 — Compounds — **three bands**

**Revised** (architecture §6.0). Detection is now a first-class **Structure** registry, so the detectors are
themselves configurable. Tab 1 has three bands, in precedence order (highest first):

### Band A — Template overrides *(the exception mechanism)*

For shapes a curator has deliberately named. Rows = resource templates in use, with item counts.

```
Rural Churches (MATTERPORT ONLY)   27 items   [ Interactive Resource ▾ ]
Full Description (Audio item)     121 items   [ Use global (currently: —) ▾ ]
Full Description (Item)         5,700 items   [ — none — ▾ ]
```

Options = **all** registered compounds, unfiltered — config time has no media context, so affinity filtering is
impossible; per-item validation does it at display time. Choosing **"None — Individual media"** (identity) here
suppresses the structural band entirely for that template.

### Band B — Structure defaults *(the broad, per-site rendering policy)*

**This is the band admins will touch most.** One row per registered structure; the row exists independently of
which viewer modules are installed.

```
Items with 2 or more images       ~900 items   [ Mirador ▾ ]   also: Slideshow · None
Items with exactly 1 image       1,837 items   [ Mirador ▾ ]
Audio with a transcript (VTT)        3 items   [ Audio with Transcript ▾ ]
Primary media is an embed           27 items   [ Interactive Resource ▾ ]
```

- Options = **available compounds declaring support for that structure**, plus **always `None — Individual media`**.
- `None` is the **per-site detector opt-out** — impossible today, and the reason this band exists at all.
- Each row shows the structure's `getDescription()` so an admin can see *why* it matches.
- **A row means "wherever this pattern occurs", NOT "for these items."** An item can match several structures and
  render several compounds (architecture §6.0.2). The UI must say this in so many words.

### Band C — Class overrides *(advanced; OFF by default)*

Behind an **"also key on resource class"** toggle, unchecked. Rationale (§3.4): with one selector kind visible
there is **no precedence rule to explain**, which is the whole scrutability objection. `class:` remains fully
supported in `CompoundResolver` — it costs ~10 lines and is the *only* usable key in installs that run one
generic template and lean on classes. It is simply not this install's key (2% coverage), so it is not shown.

### Degradation

If a compound's backing module is uninstalled, its **option** disappears; the **row survives** and falls back to
the next available compound, ultimately to identity. A row only disappears if a third-party module that *introduced
the structure* is disabled — its stored config row is then flagged as orphaned, **never auto-deleted**, and its
media falls to the remaining detectors.

---

## 6. Inheritance & the null state (the part most likely to be got wrong)

Cascade (architecture §5.1), unchanged:

```
per-resource exception (stage d) → SITE → GLOBAL → shipped default → core-native
```

### 6.1 Semantics

| Scope | Null state means | Label |
|---|---|---|
| Site | inherit global | **"Use global (currently: X)"** |
| Global | fall back to shipped default | **"Use shipped default (currently: X)"** |

- **Absence of a row = inheritance.** There is no "inherit" sentinel value in storage; the row simply is not there.
- The dropdown's null option must be **visibly distinct from an explicit choice that happens to equal the inherited
  value** — otherwise "inheriting" and "coincidentally matching" are indistinguishable and a later global change
  fails to propagate (architecture §5.2). Render inherited rows muted, with the effective value shown inline.
- Every explicitly-set row gets a **"Reset to inherited"** control (this is the only way back to absence).

### 6.2 Save semantics — sparse, and defensively so

```
on save (either scope):
  for each row:
      if value === INHERIT_SENTINEL ('')  → ensure key ABSENT from the array
      else                                → write key => player/compound name
  prune empty context arrays
  if the whole map is empty → store [] (not a map of empty strings)
```

At **site** scope this pruning **must** happen inside the form's input filter, before core's unconditional
`siteSettings()->set()` runs (§2.2). This is the single highest-risk mechanic in the stage: get it wrong and
every unrelated site-settings save quietly freezes that site's media config against future global changes.

**Verification for exactly this:** set a global mapping, leave a site unset, save the site settings form
*without touching our tab*, then change the global mapping — the site must follow. If it doesn't, inheritance is broken.

### 6.3 Defence in depth — make the trap impossible, *then* visible

A warning alone is the weakest available response: it fires on the normal path (so admins learn to ignore it),
it is unactionable for a site admin (the fault is ours, not theirs), and it leaves the failure mode live — it merely
narrates the breakage. Three layers instead, in this order:

| # | Layer | Where | What it buys |
|---|---|---|---|
| 1 | **Prevent** | input filter on write (§6.2) | inherit-rows never persist |
| 2 | **Immunise** | **the resolver / composer, on read** | **a stored `''`/`null` row is treated as ABSENT — i.e. as "inherit"** |
| 3 | **Surface** | site + global config UI | the anomaly is visible and repairable |

**Layer 2 is the load-bearing one.** Normalise on read:

```php
// PlayerResolver + Composer, when reading a tier's map:
$row = $map[$key] ?? null;
if ($row === null || $row === '') {
    continue;   // fall through to the next tier — an empty row is NOT an override
}
```

With this, a stray empty row cannot break inheritance **even if layer 1 regresses** — from a buggy build, a
hand-edited setting, a future refactor, or another module writing our key. The failure degrades from *"this site is
silently frozen against global changes, forever, and nothing looks wrong"* to *"there is harmless debris in a
settings blob."* That is the difference between a latent data-corruption bug and a cosmetic one, and it costs two lines.

**Layer 3 — the warning, now that it is hygiene rather than a safety net.** It must fire only on the *anomalous*
state, never on the normal path:

- **Site config page**, above our element group, *only if* stored empty/unknown/orphaned rows are found:

  > ⚠️ **3 stored display rows on this site are empty and are being ignored.**
  > They are not affecting how media renders — these rows inherit the global default as intended — but they are
  > leftover debris from an earlier save. **[Clear them]**

- **Global config page**, an **inheritance overview** across sites — this is what makes a frozen site *findable*
  rather than something you stumble on months later:

  | Site | Overrides | Empty rows |
  |---|---|---|
  | digital-collections | 3 of 14 | — |
  | some-other-site | 0 of 14 | ⚠️ 2 · **[Clear]** |

- Because layer 2 makes empty rows inert, **"Clear" is always safe** — it can never change how anything renders.
  Say so in the UI; an admin should never have to wonder whether cleanup is risky.

**What is deliberately NOT warned about:** the normal act of saving site settings. No banner, no interstitial.
If layers 1 and 2 hold, there is nothing for the admin to know.

### 6.4 The inheritance panel (the everyday version of the same idea)

The same information, minus the alarm, is what admins need routinely. At the top of our element group at site scope:

> **This site overrides 3 of 14 display rows.** The other 11 follow the global default. **[Reset all to global]**

Each explicitly-set row renders as an override (with **Reset to inherited**); each inherited row renders muted with
its effective value inline (§6.1). This makes the *inheriting vs. coincidentally-matching* distinction visible at a
glance — which is the whole point of the null state, and the thing that silently rots if we get any of this wrong.

---

## 7. Storage

Per architecture §8.1, keyed by context (`page` only in v1):

```php
// Settings (global) and SiteSettings (site, sparse)
'advmedia_players'   => ['page' => ['image/tiff' => 'mirador', 'audio/*' => 'html5-audio']],
'advmedia_compounds' => ['page' => ['template:4' => 'interactiveResource']],   // ← §3.3 selector key
```

Both keys are read by the existing `PlayerResolver` (already reads site → global → shipped default) and by the
`Composer` (which today runs detectors only — this stage adds the selector lookup ahead of them).

---

## 8. Degradation & edge cases

| Case | Behaviour |
|---|---|
| Player/compound in storage no longer registered | Ignored at resolve time; row flagged in UI; **value preserved** |
| Selector target deleted (template/class removed) | Row flagged as orphaned; offer "remove"; never auto-remove |
| Site never configured | Zero site rows; everything resolves to global — the common case, must be the cheapest path |
| Module config never touched | Zero global rows; shipped defaults apply; the site behaves exactly as it does today |
| Two compounds claim the same media | Existing greedy-claim ordering by priority (Composer) — unchanged by this stage |

---

## 9. Acceptance criteria

There is no test suite; verification follows the STATUS §5 pattern (throwaway in-container harness scripts +
real-browser checks against known items).

1. Global tab sets `image/tiff → mirador`; item **716** renders Mirador. ✅ already true via detector — must stay true.
2. Site override sets `image/tiff → download`; item 716 renders a download link **on that site only**.
3. Clearing the site row returns item 716 to the global value — **without** re-saving global.
4. Saving site settings **without touching our tab** leaves inheritance intact (§6.2) — *layer 1*.
4b. **Inject the bug deliberately:** hand-write `['page' => ['image/tiff' => '']]` into a site's `SiteSettings`,
    then change the global `image/tiff` mapping. **The site must still follow global** (§6.3 layer 2), and the
    config page must show the "empty rows" warning with a working **[Clear]** — *this is the test that the trap is
    survivable, and it is the most important one in this stage.*
5. Item **99295** (audio+VTT) still renders Audio with Transcript; item **2722** still renders the Matterport
   embed with the poster suppressed; item **104466** (pdf+image+html) still renders both its compounds.
6. With Mirador uninstalled, no `mirador` option appears in any dropdown, and stored `mirador` rows are ignored
   with a warning rather than fataling.
7. `module.ini` `configurable = true`; the module config form renders and round-trips.

---

## 10. Build order within stage (c)

1. Storage + read path (extend `PlayerResolver` / `Composer` to consult the selector map) — headless, verifiable.
2. The shared array-valued **element + render partial + inheritance logic**.
3. Mount at **global** (module config) — full control, easiest.
4. Mount at **site** (`form.add_elements` + `form.add_input_filters`) — the sparse-save trap lives here.
5. `mirador` / `universal-viewer` player adapters + the **Paged Document** compound (the remaining parity gap).

---

## 11. Open decisions

### 11.1 ✅ The Tab-1 key — **decided**, see §3.3

Selector model: `template:` primary, `class:` alternative, `type:` grammar-supported but not enabled.

### 11.5 Tier-outer vs. selector-outer (surfaced during step 1 — worth a decision)

When a site and global map *different* keys onto the same item, which wins?

- **Tier-outer (implemented):** site beats global, always. A site's broad `class:` row therefore beats a global
  narrow `template:` row. Simple to explain: *"site overrides global, period."*
- **Selector-outer:** the most specific *signal* wins, and site-beats-global applies within that signal. A narrow
  global `template:` row would beat a broad site `class:` row.

**Implemented tier-outer**, because the shipped `PlayerResolver` already behaves that way (a site `default` row
beats a global exact-MIME row) and divergence between the two resolvers would be worse than either rule. Flagged
because it is a real semantic choice, it is easy to change while both maps are empty, and it is very hard to change
once sites have saved overrides.

### 11.2 Expose the `default` (catch-all) row in Tab 2?

Powerful and dangerous — it re-points *everything* unmatched. Recommend showing it, clearly separated and warned.

### 11.3 Per-site Tab-2 visibility

Site admins seeing the full MIME matrix may be more rope than they need. Option: site scope shows only the
friendly group rows, with exact-MIME rows global-only. **Recommend deferring** — start with parity between scopes.

### 11.4 Do compounds get per-player settings here?

`PlayerInterface::getConfigForm()` is sketched in architecture §4.1 (e.g. Mirador config). **Recommend deferring**
to a later stage — it is a third axis and this stage is already the biggest one.
