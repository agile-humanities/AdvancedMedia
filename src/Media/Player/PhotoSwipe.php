<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * A single image, presented plainly in the page and opening into PhotoSwipe —
 * pinch/scroll zoom, native fullscreen, keyboard and gesture navigation.
 *
 * WHY THIS EXISTS ALONGSIDE MIRADOR. Mirador is a IIIF viewer built for documents:
 * it fetches a manifest, an info.json, region tiles and a thumbnail strip before it
 * can show anything. For ONE image that is a lot of viewer furniture and, on this
 * install, a lot of waiting — every derivative is generated on demand at roughly two
 * seconds a request, uncached. This player asks for one already-existing derivative
 * and shows it. Mirador remains the right tool for page-image sets; this is the right
 * tool for a single picture.
 *
 * PROGRESSIVE ENHANCEMENT. The markup is a plain link to the full-size image wrapping
 * a thumbnail. With no JavaScript that link still works — it opens the image. The
 * script only intercepts the click.
 *
 * WHICH IMAGE, WHERE. The page gets Omeka's `large` derivative (small, already
 * generated). PhotoSwipe opens the ORIGINAL, straight from the file store rather than
 * through the image server, so the zoom view is the real thing and costs one request
 * that never touches a derivation. On a collection of very large scans that is a real
 * download — swapping `originalUrl()` for a sized IIIF URL is a one-line change if it
 * ever needs to be lighter.
 */
class PhotoSwipe extends AbstractPlayer
{
    protected $name = 'photoswipe';
    protected $label = 'PhotoSwipe (zoom and fullscreen)'; // @translate
    protected $mediaTypeCapabilities = ['image/*'];

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        $full = $media->originalUrl();
        if (!$full) {
            return '';
        }

        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('vendor/photoswipe/photoswipe.css', 'AdvancedMedia'))
            ->appendStylesheet($assetUrl('css/photoswipe-viewer.css', 'AdvancedMedia'));

        // Core first, then the lightbox, then our initialiser — see asset/vendor/README.md.
        $view->headScript()
            ->appendFile($assetUrl('vendor/photoswipe/photoswipe.umd.min.js', 'AdvancedMedia'))
            ->appendFile($assetUrl('vendor/photoswipe/photoswipe-lightbox.umd.min.js', 'AdvancedMedia'))
            ->appendFile($assetUrl('js/photoswipe-viewer.js', 'AdvancedMedia'));

        // PhotoSwipe wants the full image's dimensions up front so it can size the
        // viewer before the image arrives. IiifServer caches them on the media; when
        // they are absent (no IiifServer, or a media it never measured) we omit the
        // attributes and the script measures the image itself.
        $dimensions = $media->mediaData()['dimensions']['original'] ?? [];
        $width = isset($dimensions['width']) ? (int) $dimensions['width'] : null;
        $height = isset($dimensions['height']) ? (int) $dimensions['height'] : null;

        // NOT $media->displayTitle(): it falls back to the media's SOURCE, which on
        // this collection is an ingest URL on another bucket — an internal storage
        // path is not alt text. The item's title is public metadata and actually
        // describes what the image shows.
        $item = $media->item();
        $label = $media->title() ?: ($item ? $item->displayTitle() : '');

        return $view->partial('common/player/photoswipe', [
            'fullUrl' => $full,
            'displayUrl' => $media->thumbnailUrl('large') ?: $full,
            'width' => $width,
            'height' => $height,
            'label' => (string) $label,
        ]);
    }
}
