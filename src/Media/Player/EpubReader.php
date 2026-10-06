<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * An in-page ePub reader, rendered by our own vendored epub.js.
 *
 * WHY OUR OWN COPY RATHER THAN THE UNIVERSAL VIEWER. UV can read ePub — its ebook
 * extension is epub.js under the hood, so the two render identically — but reaching
 * it costs a IIIF manifest, and IiifServer will not put an ePub in one: its type map
 * has no entry for application/epub+zip, and Iiif\Manifest paints only Image, Video
 * and Audio onto canvases. We would therefore have to synthesise a manifest purely to
 * hand UV a file it could have been given directly, and then keep that synthetic
 * manifest from colliding with the REAL manifest that drives Mirador for the same
 * item's images. Rendering the file ourselves removes the manifest from the problem
 * entirely: no IIIF, no UV, no second whole-item viewer.
 *
 * SCOPE: this is a PLAYER — it renders one medium, in place. It never claims the
 * item, so an item holding both an ePub and page images keeps its image viewer
 * untouched; the two sit side by side as separate blocks.
 *
 * KNOWN LIMITATION, deliberately accepted: epub.js reads the whole OCF archive
 * before it can paginate, so the reader downloads the entire file up front. That is
 * inherent to the format (the container is a zip) and not specific to this library.
 * For reflowable editions it is unremarkable; a large image-heavy edition will be
 * slow to open, and the fallback download link below is the honest alternative.
 * Fixed-layout ePub is supported only as well as epub.js supports it, which is less
 * well than reflowable.
 */
class EpubReader extends AbstractPlayer
{
    protected $name = 'epub-reader';
    protected $label = 'ePub reader (in the page)'; // @translate

    /**
     * application/epub+zip is the IANA-registered type and the only one an uploaded
     * ePub will carry: the format's container IS a zip, so there is no unpacked
     * variant of a single file to account for. (An EXPLODED ePub — a directory whose
     * package document is application/oebps-package+xml — is a different shape
     * entirely; it has no single medium to attach a player to, so it is out of scope
     * here rather than silently half-supported.)
     */
    protected $mediaTypeCapabilities = ['application/epub+zip'];

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        $url = $media->originalUrl();
        if (!$url) {
            // No original (externally-described media, or a failed ingest): nothing
            // to read and nothing to link to.
            return '';
        }

        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()->appendStylesheet($assetUrl('css/epub-reader.css', 'AdvancedMedia'));

        // JSZip first: epub.js's UMD build resolves it from the global scope at load
        // time to read the container.
        $view->headScript()
            ->appendFile($assetUrl('vendor/jszip/jszip.min.js', 'AdvancedMedia'))
            ->appendFile($assetUrl('vendor/epubjs/epub.min.js', 'AdvancedMedia'))
            ->appendFile($assetUrl('js/epub-reader.js', 'AdvancedMedia'));

        return $view->partial('common/player/epub-reader', [
            'src' => $url,
            'media' => $media,
        ]);
    }
}
