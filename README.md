# Advanced Media (Omeka S module)

Configurable, contextual media-viewer selection and compound-object display for Omeka S.

Replaces the ingest-bound, MIME-blind viewer selection in Omeka core with a
**display-time** layer: a discoverable **player registry**, a **resolver** that
maps media → player through a contextual cascade, and (in later stages) a
**composer** for compound objects (Audio with Transcript, Image Gallery, …).

See [`docs/architecture.md`](docs/architecture.md) for the full design and rationale.

## Status

**Stage (a) — engine.** Implemented:

- **Player registry** (`advancedmedia_media_players` plugin manager) with players:
  `core-native`, `download`, `html5-audio`, `html5-video`, `thumbnail-link`.
- **Resolver** (`AdvancedMedia\Media\PlayerResolver`) — cascade:
  per-site → global → shipped default → `core-native`; within each tier
  exact MIME → top-level wildcard → `default`; every candidate validated via
  `PlayerInterface::supports()`.
- **View helper** `advancedMediaPlayer($media)` and a resource-page block
  **“Advanced media”** (items) that renders each media through the resolver.

Shipped default mapping (context `page`): `audio/* → html5-audio`,
`video/* → html5-video`, everything else `→ core-native`.

**Stage (b) — compounds.** Implemented:

- **Compound registry** (`advancedmedia_compound_types`) + **Composer** — greedy-claim,
  priority-ordered, groups rendered in first-claimed order, identity fallback.
- **Compound types:** *Individual media* (identity default) and **Audio with Transcript**
  (auto-detects audio+VTT pairs and renders a synced player + clickable transcript).
- **Migrated + cleaned** from AgileThemeTools: VTT parser (`Media\Vtt\VttParser`),
  `circular-audio` and `transcript` players, and `circular-audio.js`/`.css` — now
  progressively enhanced (native `<video controls>` works if JS/jQuery is absent) and
  multi-player-safe.
- The **Advanced media** block now composes an item's media through the Composer.

### Not yet built (see architecture doc §14)

- (c) Config UI (Tab 2 players, Tab 1 class→compound) + Mirador/UV adapters
- (d) Per-resource exception table + editor
- (e) Theme-settable fallback thumbnails

## Requirements

Omeka S `^4.0.0`. **No hard dependency on any viewer module** — viewer adapters
(Mirador, Universal Viewer) self-disable when their module is absent.

## Try it

1. Enable **Advanced Media** in Admin → Modules.
2. In a site’s **Theme → Resource pages**, add the **Advanced media** block to the
   item page (or replace **Media embeds** with it).
3. View an item with audio/video media — it renders through the HTML5 players;
   other media fall through to core rendering.

## Mirador page links

Every Mirador viewer — whichever template renders it — accepts a page in the URL and
keeps the URL in step as the visitor pages:

- `…/item/1379?page=4` opens on the 4th canvas (1-based).
- Paging rewrites `page` in the address bar (no new history entries), so the URL is
  always a bookmark to the current page. Other query parameters and the `#hash` are
  left exactly as they were.
- With several viewers on one page, `?page=3&viewer=2` targets the second one
  (document order). `viewer` is only ever written for viewers after the first.
- Invalid values are silent: a bad or out-of-range `page` opens page 1 and leaves the
  URL alone; an invalid `viewer` ignores the link.

No settings. Installed as a delegator on the Mirador module's `mirador` view helper, so
it is inert without that module. Design, and the Mirador 3.4.9 internals it relies on:
[`docs/mirador-page-links.md`](docs/mirador-page-links.md).

## Single images: the PhotoSwipe player

Single-image items can be rendered by **PhotoSwipe** instead of Mirador. The page
shows the image plainly; clicking it opens an immersive viewer with zoom, keyboard
and gesture control, and a fullscreen toggle.

Choose it on the **`Exactly one image`** row of the config page — `Mirador` and
`PhotoSwipe` are both offered, because one pattern can have more than one
legitimate rendering. The shipped default remains Mirador, so nothing changes on
an existing install until an admin says so.

**Why not just use Mirador.** Mirador is a IIIF viewer built for documents: a
manifest, an `info.json`, region tiles and a thumbnail strip before anything
appears. For one picture that is a lot of furniture, and on an install where
derivatives are generated on demand it is also a lot of waiting. PhotoSwipe asks
for one already-generated derivative. Mirador remains the right tool for page-image
sets — this is the right tool for a single image.

**What it requests.** The page gets Omeka's `large` derivative. The viewer opens
the ORIGINAL, straight from the file store rather than through the image server, so
zoom shows the real thing. On collections of very large scans that is a real
download; swapping `originalUrl()` for a sized IIIF URL in `Media\Player\PhotoSwipe`
is a one-line change.

**Progressive enhancement.** The markup is a plain link to the full image wrapping a
thumbnail. Without JavaScript the link still opens the image; the script only
intercepts the click. If the full image's dimensions are unknown (no IiifServer to
have cached them) the script measures the image on first click rather than
downloading it up front.

**Two things it deliberately does that the library does not:**

- **Registers a fullscreen button.** PhotoSwipe v5 ships zoom and close only; its
  "fullscreen" documentation means *opening* fullscreen, which is not what someone
  examining a manuscript wants. Ours toggles the Fullscreen API on the viewer, and
  logs rather than failing silently when a browser refuses.
- **Defends the viewer's chrome from the theme.** PhotoSwipe appends its UI to
  `<body>` as bare `<button>` elements, so they inherit whatever a theme does to
  buttons — on pitts3 that painted every control as a solid `#F2A900` block and
  then collapsed them to zero width. `asset/css/photoswipe-viewer.css` restores
  PhotoSwipe's own geometry. Do not remove those rules when restyling.

## Suppressing a PDF that duplicates an image set

Some items hold a digitised document twice: page images for the viewer, and a PDF
of the same document. By default both are shown — the gallery, then a PDF reader
below it. The **`A PDF attached to an image set`** structure exists so a site can
say otherwise: set that row to **Do not display** and the PDF viewer is suppressed
on those items while the gallery is untouched.

The file stays downloadable. The Downloads block walks the item's media directly
and never goes through the composer, so suppressing display has no effect on it —
subject as always to the per-type download settings.

Three boundaries worth knowing:

- **Two or more images**, not one. A single image beside a PDF is a cover or
  representative thumbnail for a document whose real content is the PDF, so
  suppressing it there would hide the item's substance. Those items stay with the
  `PDFs` row.
- **Only the PDF is claimed.** The images remain with the image structures, so the
  gallery keeps whatever the `Two or more images` row is set to. Gallery
  configuration is never split across two rows.
- **The shipped default is `identity`**, i.e. today's behaviour — the PDF renders.
  Suppression is a presentation choice a site opts into, not a default.

`Do not display` is a compound like any other and supports every structure, so any
row can now be turned off this way. That is deliberately different from the `None`
*player*, which hides a media type wherever it occurs — mapping `application/pdf`
to it would also hide every standalone PDF in the install.

## ePub reader

Uploaded ePub editions (`application/epub+zip`) are read in the page by the
**ePub reader** player, which uses this module's own vendored copy of
[epub.js](asset/vendor/README.md) — no dependency on the Universal Viewer, no
IIIF manifest, no build step.

**Why not the Universal Viewer.** UV can read ePub (its ebook extension is
epub.js underneath, so the two render the same), but reaching it needs a IIIF
manifest, and IiifServer will not put an ePub in one: it has no entry for the
media type, and `Iiif\Manifest` paints only Image, Video and Audio onto canvases.
Driving UV would mean synthesising a manifest purely to hand it a file it could
be given directly — and then keeping that synthetic manifest from colliding with
the real one that drives the image viewer on the same item.

**How an item with a cover image behaves.** The ePub is claimed by its own
`epub-edition` structure, which takes the ePub **and nothing else**: cover and
page images stay with the image structures and their viewer. An item can
therefore show an image viewer and a reader side by side, and no item ever ends
up with two whole-item viewers competing over it.

The structure ships mapped to `identity` ("None — Individual media"), which
renders each claimed ePub through its resolved player. That mapping is what makes
the reader appear even when the ePub is not the item's primary media, since
structure-claimed media are exempt from the "only display primary and grouped
media" policy.

**What the reader does.** Paginated reading with previous/next and arrow keys, a
table of contents built from the book's own navigation, and a download link that
is present before the reader loads and stays available after. The book is opened
only when the reader scrolls into view, so a visitor who never reaches it never
downloads it.

**Limitations, deliberately accepted.**

- An ePub must be downloaded **whole** before a page can be shown — the container
  is a zip. That is the format, not the library. Large image-heavy editions are
  slow to open; the download link is the honest alternative.
- Reflowable ePub is well supported; **fixed-layout ePub only as well as epub.js
  supports it**, which is less well.
- The reader gives up after 20 seconds and falls back to the download link.
  epub.js does intermittently hang on a page that is also booting an image
  viewer; the timeout is what keeps that from becoming a permanent spinner.
- An **exploded** ePub (a directory, package document
  `application/oebps-package+xml`) is out of scope: there is no single medium to
  attach a player to.

## Media URL proxying (optional)

When media lives on a remote store (S3), Omeka hands out **bucket URLs**. Turning on
**Media URL proxying** (module config → *Media URL proxying*) makes every media URL
point at *this site* instead — a single, structured `/files/…` path that your network
layer can govern. Turn it on **only** if you want to control bot/harvester access to
media with network rules (rate limits, user-agent filtering, a WAF); with no such
rules it gains nothing, and it is off by default.

Enabling it is **gated on a reachability check**: the server must be able to reach its
own address, or the module refuses to switch it on and tells you why. (Behind Cloudflare
or a reverse proxy, "its own address" must resolve from *inside* the server, not only
from the public internet.)

### ⚠️ Pin the Base URI before running background jobs

Proxying detects the base URI from the incoming web request. **Background jobs have no
request** — so the IIIF-manifest cache rebuild and the search (Solr) reindex, which both
write media URLs into what they store, will find no base URI, fall back to the **external
(S3) URLs**, and bake those into the cache/index.

Before running those jobs, set the **"Base URI (optional)"** field on the config page to
your public URL including `/files` (e.g. `https://example.org/files`) — the value shown as
the field's placeholder. Then rebuild the IIIF cache and reindex. (Interactive page views
are unaffected; only jobs need the pin.) If you rebuilt before pinning, pin and rebuild
again — the S3 URLs are frozen in the cache until regenerated.

### How bytes are served

A `/files/…` request is answered with a direct **200** — never a 302. (A redirect would
leak the bucket URL, defeating the point, and ImageServer's source fetch rejects any
first status line that is not `200 OK`, so a redirect breaks tiling.) Two serve modes,
**auto-detected on each config-page load** — no setting to choose:

- **X-Accel-Redirect (zero-copy)** — nginx proxies the object straight from the bucket;
  PHP never touches the bytes and the bucket URL is never exposed. **Recommended.**
  Requires the nginx snippet below.
- **Streaming (fallback)** — PHP pipes the object through in chunks. Works everywhere
  with no server config, but it occupies a worker for the transfer. Used automatically
  when X-Accel is not detected.

### Installing the nginx snippet (for zero-copy)

The config page shows this snippet **with your bucket and endpoint already filled in** —
copy it from there. Paste it into the `server {}` block of the nginx that fronts this
site and reload nginx; the module detects it automatically on the next config-page load.

```nginx
# Serves media: nginx proxies the object straight from the bucket.
location /__advancedmedia-s3/ {
    internal;
    proxy_pass https://<s3-endpoint>/<bucket>/;
    proxy_set_header Host <s3-host>;
    proxy_set_header Authorization "";
    proxy_ssl_server_name on;
    proxy_hide_header x-amz-id-2;
    proxy_hide_header x-amz-request-id;
    # The bucket's own Content-Type is not authoritative: objects written by bulk
    # loads or older tooling can carry application/octet-stream for a perfectly
    # good PDF, and Chrome DOWNLOADS an octet-stream subresource, so an embedded
    # PDF reader starts saving the file on page load. Hiding it here lets the
    # Content-Type the module sets (from Omeka's own media record) reach the
    # client. Without this line nginx keeps the bucket's value and the module's
    # is discarded.
    proxy_hide_header Content-Type;
}

# Lets the module detect that this snippet is installed.
location = /__advancedmedia-xaccel-check {
    internal;
    default_type text/plain;
    return 200 "advancedmedia-xaccel-ok";
}
```

**nginx in front of Apache** (or php-fpm behind nginx) is fine: `X-Accel-Redirect` is
processed by whichever nginx proxies the response to the client, whatever the app server
is. Put the snippet in that front nginx.

### Pure Apache (no nginx anywhere)

There is **no zero-copy equivalent** for remote (S3) media on a pure Apache stack.
Apache's `X-Sendfile` (`mod_xsendfile`) can only serve *local* files, so it cannot proxy
from a bucket. Such installs use the streaming fallback automatically — it works, it is
just heavier. For zero-copy, put nginx in front of Apache and add the snippet there.

## Licence

[CeCILL v2.1](LICENSE.txt), a GPL-compatible free software licence (see its article 5.3.4).
