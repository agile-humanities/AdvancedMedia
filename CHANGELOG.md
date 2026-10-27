# Changelog

All notable changes to Advanced Media are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [1.0.0] — unreleased

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
  audio with transcript, audio/video with images, OHMS interview, paged document, PhotoSwipe
  gallery, Mirador and Universal Viewer image sets, interactive resources), with structure
  detection kept separate from rendering.
- **Configuration UI** at global and per-site scope: players by media type, compounds by
  resource class.
- **Resource page blocks:** *Advanced media* (an item's media through the composer) and
  *Downloads* (per-media-type download buttons with a count threshold).
- **Mirador page links:** `?page=N` deep links and URL write-back for every Mirador viewer.
- **Duplicate PDF suppression** for items whose PDF repeats an image set.
- **Media URL proxying** (optional, off by default): serves `/files` through this host with a
  direct 200, by nginx `X-Accel-Redirect` or PHP streaming, gated on a reachability probe.
- **Media audit** on the configuration page: lists media whose file is the problem (hidden
  system files, empty files, images with no thumbnails, undetected types) for an editor to
  delete or replace.

### Notes

- Requires Omeka S 4. No viewer module is required: the Mirador and Universal Viewer adapters
  turn themselves off when their module is absent.
- Licensed under CeCILL v2.1.
