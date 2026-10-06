# Vendored libraries

Third-party JavaScript shipped with this module, so that the ePub reader depends
on nothing but itself — no CDN at runtime, no build step, and no reliance on
another module's bundle being present or correctly built.

| Library | Version | Licence | Source |
|---|---|---|---|
| epub.js | 0.3.93 | BSD-2-Clause | https://github.com/futurepress/epub.js |
| JSZip   | 3.10.1  | MIT (dual MIT/GPLv3) | https://github.com/Stuk/jszip |
| PhotoSwipe | 5.4.4 | MIT | https://github.com/dimsemenov/PhotoSwipe |

epub.js requires JSZip to read the OCF (zip) container, so both must load, JSZip
first. Neither is minified further or modified; upgrade by replacing the file and
updating the version above.

**Why these are committed rather than fetched by a build.** A viewer whose bundle
has to be rebuilt is a viewer that can silently ship broken — that is exactly how
Mirador 3.4.17 blanked the viewer on this install. These are plain UMD files that
work as downloaded.


## PhotoSwipe

Two UMD files plus a stylesheet. `photoswipe-lightbox.umd.min.js` is the part that
binds to the page; `photoswipe.umd.min.js` is the viewer it opens. In ES-module use
the lightbox imports the viewer itself — in UMD it cannot, so the viewer must be
loaded as a global and handed over as `pswpModule`. Load order therefore matters:
core, then lightbox, then our own script.
