# Changelog

All notable changes to Advanced Media are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [1.1.0] — unreleased

### Added

- Integration checks on the configuration page (`File\IntegrationChecks`): live requests to the site, as an anonymous
  visitor, of what the Agile patches to IIIF Server and Image Server protect: an original on the external store
  processed by Image Server (a region request), a cacheable manifest, no fast tile rule in `.htaccess` with an
  external store, the IIIF media identifier, no tile grid for untiled images. Kept for 10 minutes ("Check again"
  reruns them); also logged by Agile Theme Tools' Easy Admin check. A job run without a request (cron) reports the
  HTTP checks as not checked.

## [1.0.1] — 2026-11-10

### Fixed

- The media URL proxy's reachability check also fetches real media files (a small original, then a large
  derivative) after the token path. Checking the token path alone passed on a production site whose CDN bot rule
  (AWS WAF on CloudFront) refused the server's own requests for media files, so every IIIF image failed while the
  check said all was well. The check now reports such a refusal, naming the responding server when it says who it
  is. This fix was made in the Pitts installation on 2026-10-19 and missed 1.0.0.

## [1.0.0] — 2026-10-27

First public release. Advanced Media was developed inside Agile Theme Tools and the Pitts
digital collections site, and is released here as a standalone module.

### Added

- **Display-time viewer selection.** A player registry and a resolver that chooses a player for
  each media at render time through a cascade (per site → global → shipped default → core), by
  exact MIME type, then top-level wildcard, then default. Each candidate is checked against the
  media before it is used.
- **Players:** core native, download, HTML5 audio, HTML5 video, circular audio, transcript,
  thumbnail link, PDF embed, PhotoSwipe (single images), ePub reader and OHMS.
- **Compound objects.** A composer that groups an item's media into compounds (individual media,
  audio with transcript, audio/video with images, OHMS interview, PhotoSwipe single image,
  Mirador for image sets, Universal Viewer for paged documents, interactive resources, do not
  display), with structure detection kept separate from rendering.
- **Configuration UI** at global scope (module configuration) and per site (the site's *Media*
  page): players and downloads by media type, a compound for each media structure, and
  overrides by resource template.
- **Resource page blocks:** *Advanced media* (an item's media through the composer) and
  *Downloads* (per-media-type download links with editable labels, a count threshold and an optional
  metadata download).
- **Mirador page links:** `?page=N` deep links and URL write-back for every Mirador viewer, Mirador 3 and 4.
- **Duplicate PDF handling:** a PDF on an item that also has an image set is detected as its own
  structure, so it can be shown separately or not displayed.
- **Media URL proxying** (optional, off by default): serves `/files` through this host with a
  direct 200, by nginx `X-Accel-Redirect` or PHP streaming, gated on a reachability probe.
- **Media audit** on the configuration page: lists media whose file is the problem (hidden
  system files, empty files, images with no thumbnails, undetected types) for an editor to
  delete or replace.

### Notes

- Requires Omeka S 4. No viewer module is required: the Mirador and Universal Viewer adapters
  turn themselves off when their module is absent.
- Licensed under CeCILL v2.1.

[1.0.0]: https://github.com/agile-humanities/AdvancedMedia/releases/tag/1.0.0
