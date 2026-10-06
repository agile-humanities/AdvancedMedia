# Media routing — serving S3 files from /files/*

**Status:** built and verified 2026-07-20 (stage f, phase 1)
**Opt-in:** `advancedmedia_proxy_files` (global setting, default off, gated on a reachability probe)

## Read this first: two features, one of which is optional

| | Downloads | Media URL proxying |
|---|---|---|
| What it does | Download buttons that actually download | Routes *all* media URLs through this host |
| Needs | nothing beyond the module | the server must be able to reach its own base URI |
| Works everywhere? | **yes** | **no** — see below |
| Default | on (place the block) | **off** |

They were built together and share the `/files/download` route and the presigning
helper, but **they are not coupled**: the Downloads block works with proxying off, on any
install. Do not reintroduce a dependency between them — an early draft gated the download URL
on `isProxyEnabled()`, which meant downloads silently failed to download unless an unrelated
advanced setting happened to be on.

**Proxying is a nice-to-have.** It earns its keep only where an admin wants network-level
control over bots and harvesters reaching remote media — rate limits, user-agent rules, a WAF —
because routing media through this host gives those rules a stable path to match. With no such
rules it buys nothing and costs a round trip per server-side read.

## The problem

Two independent needs converged on one solution.

1. **Bot control.** Rich media should sit behind a stable, structured path so network-level
   policy (rate limits, UA rules, WAF) can be written against it. With originals on S3 the
   URLs point at a bucket, so there is nothing local to write a rule about.
2. **Downloads that download.** Browsers ignore the `download` attribute on a *cross-origin*
   URL, and S3 sends no `Content-Disposition` for an ordinary object GET. So a "Download PDF"
   button pointing at S3 opens the PDF in a tab instead of saving it.

Both dissolve if S3-backed media are addressed on this host.

## The key insight: local Omeka already solved this

`Omeka\File\Store\Local::getUri()` is `{baseUri}/{storagePath}`, so a local install already
serves media from a structured, same-origin, query-string-free path segmented by derivative
type:

```
/files/original/…   /files/large/…   /files/medium/…   /files/square/…   /files/tile/…
```

That is exactly the shape we wanted. **The problem was never Omeka's routing — it was that the
S3 module is the one place that ignores it.**

So we do not invent a route vocabulary. We make S3 emit *Omeka's own* shape. Consequences:

- No query string, so the several places that string-append to a store URL keep working
  (`IiifManifest2`'s `originalUrl() . '/element/e0'`, ImageServer's `explode('?')` on tile bases).
- Code that **hardcodes** `/files` — IiifServer's alto link, IiifManifest2's derivative base —
  starts producing *correct* URLs instead of broken ones. Two latent bugs fix themselves.
- Network rules are written once and hold for local and S3 installs alike.
- The API needs no special handling: `o:original_url` returns what a local install returns.
- Third-party modules keep working by assumption rather than by patch.

## How it is wired

| Piece | Location |
|---|---|
| Store subclass (overrides `getUri()` only) | `src/File/Store/ProxyingAwsS3.php` |
| Factory (settings + base-URI resolution) | `src/Service/File/Store/ProxyingAwsS3Factory.php` |
| Controller for `/files/*` and `/files/download/*` | `src/Controller/FileController.php` |
| Routes (explicit priorities) | `config/module.config.php` |
| Registration | `Module::decorateFileStore()` |

**Serving model: a direct 200, by one of two modes — NOT a 302.** An earlier version 302'd the
client to the bucket. That was replaced because a redirect fails on two counts: it hands out the
real S3 URL (so a bot bypasses the very rules this feature enforces), and ImageServer's
server-side source fetch checks only that the FIRST status line is `200 OK`
(`AbstractImager::_loadImageResource`), so a 302 makes tiling 500 even though the file is
perfectly fetchable. Both `/files/*` actions therefore answer 200:

- **`MODE_XACCEL`** — emit `X-Accel-Redirect: /__advancedmedia-s3/<path>` and let the front nginx
  proxy the bytes from the bucket. Zero-copy through PHP, and the bucket URL never reaches the
  client. Requires the nginx snippet (shown on the config page, bucket pre-filled).
- **`MODE_STREAM`** — pump the S3 object through PHP in chunks (256 KiB, buffers drained, same
  shape ImageServer uses to return derivatives). Works everywhere with no server config, but
  occupies a worker for the transfer. The universal fallback.

The mode is **auto-detected** (`ServeModeDetector`, run in `Module::getConfigForm` on every
config-page load) by self-probing whether nginx handles `X-Accel-Redirect`, and cached in
`advancedmedia_serve_mode`. **Default is stream**, so an unconfigured/misconfigured nginx
degrades to "slower", never to "broken" — which, given the 500 that motivated this, is the
failure mode that matters. No zero-copy equivalent exists for a pure Apache stack
(`mod_xsendfile` serves local files only); put nginx in front for zero-copy.

**Downloads fold into the same chokepoint.** `/files/download/*` serves via the same X-Accel /
stream path, with a `Content-Disposition: attachment` we set ourselves — no more presigned-URL
302. One serving mechanism, one place bytes flow, one URL a bot can't route around.

**No Apache changes were needed.** Omeka's stock `.htaccess` already serves any path that
exists on disk and rewrites everything else to `index.php` — the `try_files` pattern, for free.
Local files (IIIF cache, theme assets, legacy uploads) are untouched; only S3-backed paths
reach the controller.

## Why `setFactory` and not module config

`Module\Manager::sortModules()` is a plain `ksort`, so module config merges **alphabetically**
and there is no load-order weighting anywhere in Omeka. `AdvancedMedia` sorts before
`AmazonS3`, so a config-level override of their factory merges first and *loses silently*.

We register against the **concrete class** `AmazonS3\File\Store\AwsS3::class`, not the
`Omeka\File\Store` alias, because ImageServer's `TileInfoFactory` resolves the concrete class
directly and would otherwise bypass us — and tiles are the highest-volume media traffic.

> ⚠️ **Known fragility.** The current runtime `setFactory` works only because our `onBootstrap`
> runs before AmazonS3's, which calls `get(AwsS3::class)` and caches the instance. That is
> alphabetical luck, not a guarantee. A `delegators` entry keyed on the concrete class is
> order-independent and is the correct Laminas idiom; AmazonS3 declares no delegators for that
> key, so the merge is clean. **Tracked as a follow-up.**

## Gotchas

### Base URI is empty under CLI

`ServerUrl` has no host outside a request, so the computed base URI becomes `http:///files/…`
— not a URL. A malformed URL baked into a cached IIIF manifest or a Solr document outlives the
process that produced it, which is far worse than an S3 URL.

**The factory therefore refuses to proxy when the base URI is not absolute**, logs a warning,
and falls back to stock behaviour. Degrading to an S3 URL is always safe; emitting a broken one
never is.

`application/data/scripts/perform-job.php` passes `--server-url` and sets it on the helper, so
real background jobs are fine. Pin `advancedmedia_files_base_uri` for anything else.

### Caches hold frozen absolute URLs

Flipping the opt-in is **not purely runtime**:

- **IIIF manifests** (`iiifserver_manifest_cache = 1`) are written lazily on read, so deleting
  `files/iiif/` is sufficient — no rebuild job. `rebaseIiifCache()` only rewrites the *Omeka*
  host, so S3 URLs inside manifest bodies survive a rebase untouched.
- **Solr** stores absolute `url_original` / `url_thumbnail_*`; existing documents keep old URLs
  until reindexed.

Observed in dev: manifests cached 2026-07-14 pointed at `omekacloud-main` while the install was
configured for `omekacloud-dev` — stale across *buckets*, independent of this feature. Production
deployment already regenerates the IIIF cache, which heads this off.

### ⚠️ Browser-facing URLs vs server-side reads — RESOLVED, and the core correction

**This is the most important thing on this page.** The original premise — "downstream code should
not be able to tell the difference between this install and a local-storage one" — is *wrong for
one class of consumer*, and getting it wrong broke the site.

On a **local** install, server-side code reads a **filesystem path**. Only on S3 does it read an
**HTTP URL** — and that URL must be reachable **from the server**, which the public URL often is
not: reverse proxies, TLS terminators, split-horizon DNS, and container port mappings all break
it. In this stack Apache listens on **port 80** while the public base URI is
`http://localhost:8080` (a host-side Docker mapping), so from inside the container:

```
http://localhost:8080/  ->  000  (connection refused — does not exist here)
http://localhost/       ->  302
https://s3…             ->  200
```

The failure is not a slow self-request, as first predicted. **The connection never establishes.**

Observed symptoms when proxying was first switched on:

| Consumer | Symptom |
|---|---|
| ImageServer imager (`AbstractImager::_loadImageResource`) | `get_headers()` false → **HTTP 500** on every derivative |
| `VttParser` via the transcript player | **273 → 0 cues, silently** (`@file_get_contents`) |
| `TileInfo` DZI/Zoomify metadata reads | tiling appears absent |

Note the imager failure was loud and the transcript failure was invisible. The second was found
only by accident while chasing the first.

### Whose problem is this? Ours.

Worth being precise, because it bounds the whole thing. `originalUrl()` does carry a latent
conflation — one value serving both a browser and the server — but **no stock configuration ever
exposes it**:

| Configuration | `originalUrl()` returns | What server-side code gets |
|---|---|---|
| Local store | `https://site/files/original/…` | never used — `ImageController::mediaPath()` finds the **filesystem path** first |
| S3 store | `https://s3…/bucket/…` | the S3 URL, publicly fetchable from anywhere |
| **Proxying on** | `https://site/files/original/…` | our URL — which the server may not reach |

Those third-party call sites are not buggy. They are correct against every configuration that
existed before ours. **We create the condition, so the blast radius is exactly "installs that
turn proxying on"** — which is what makes an opt-in plus a probe a proportionate answer, rather
than an ecosystem-wide hazard needing patches.

### Resolution: probe, do not patch

An earlier attempt patched the three known consumers (`AbstractImager`, `TileInfo`, our
transcript player) to call `ProxyingAwsS3::toFetchableUri()`, which maps a public `/files/…` URL
back to the bucket URL. **That approach was reverted.** Reasons, in order of weight:

1. It patches *other people's correct code* to accommodate our change.
2. It does not scale — the audit found four more consumers (`IiifServer/MediaDimension`,
   `ImageServer/ImageSize`, `AgileThemeTools/TextRenderer`, `IiifServer/TraitXml`) and cannot
   cover modules that do not exist yet.
3. Patches to third-party modules must be reapplied on every upgrade, and this module is
   intended for public release, where shipping patches is not an option.

`toFetchableUri()` remains in `ProxyingAwsS3` — it is correct and cheap — but nothing depends on
it. Treat it as available for a measured hot path, not as a mechanism.

Instead the precondition is **checked at the moment the admin enables the setting**
(`src/File/ReachabilityProbe.php`). It fetches `/files/__advancedmedia-probe` — an ungated route
returning a fixed token — from the server, and refuses to enable if it cannot, distinguishing:

| Reason | Meaning |
|---|---|
| `invalid-base-uri` | not an absolute URL; nothing can be built from it |
| `unreachable` | connection refused, DNS, timeout, firewall |
| `tls-failure` | https to itself failed — usually an untrusted cert or empty CA store |
| `unexpected-status` | something answered, but not with 200 |
| `unexpected-body` | 200 from something that is not this Omeka (a proxy intercepting) |

Verified against this deployment, which is *itself* an unsupported configuration:

```
http://localhost:8080/files   REFUSE [unreachable]     <- the real dev base URI
http://omeka-s/files          PASS                     <- docker service name
http://localhost/files        PASS                     <- container port
(empty)                       REFUSE [invalid-base-uri]
https://example.com/files     REFUSE [unexpected-status]
```

> Note: `ImageController::mediaPath()` falls back to `$media->originalUrl()`, so the source
> arrives at the imager as a public URL. Injecting a different store into the imagers would
> **not** have fixed it — hence the probe rather than any wiring change.

**A pass is not a promise of efficiency.** Where the public URI resolves publicly, the
server-side request leaves the host and comes back through the proxy: it works, but costs a
round trip and a worker per fetch, and is subject to the very bot rules that motivated the
feature. That is the trade, and it is why the setting is off by default.

### Silent failures are now recorded

`src/File/FetchDiagnostics.php` logs every server-side fetch failure and keeps the last 10 in
`advancedmedia_fetch_failures`, surfaced as an alert on the **global** config page with a clear
button. Graceful degradation is preserved — a missing transcript must not take the page down —
but the failure is no longer invisible, which is precisely how this bug survived.

### `media.source` is often a full URL

Sideloaded imports store e.g. `https://pittspublic.s3.../P_RG057_B069_F01_I01.pdf` in
`media.source`. `FileController::downloadFilename()` runs `basename()` over it, which is correct
for a plain path but would produce junk for a source URL carrying a query string.

## Verified (2026-07-20, Omeka 4.2 / PHP 8.3.20, real S3)

- Alias and concrete class both resolve to `ProxyingAwsS3`, same shared instance.
- Opt-in off → byte-identical to stock AmazonS3.
- Item page emits zero S3 URLs.
- `/files/…` → 302 → S3. `/files/download/…` → 302 → presigned; S3 answers `200` with
  `Content-Disposition: attachment` and the correct filename.
- Files present on disk still served statically by Apache.
- Path traversal (`../`, absolute, dotfiles) → 404.
- ImageServer `info.json` 200; image requests redirect to `/files/large/…`.
- Purging a cached manifest regenerates it with `/files/medium/…`.
