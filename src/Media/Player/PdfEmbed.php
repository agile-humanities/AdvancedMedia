<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * An in-page PDF reader using the BROWSER'S OWN viewer, via <object>.
 *
 * WHY THE BROWSER'S VIEWER RATHER THAN PDF.js. Every current desktop browser ships
 * a competent PDF reader, and reaching it costs one element and no JavaScript. The
 * decisive difference is CORS: <object>/<iframe> loads are not CORS-gated, so this
 * works with files served straight from S3 cross-origin. PDF.js fetches by XHR and
 * would require either the media proxy switched on or bucket CORS extended — a
 * deployment precondition for what should be a display choice. PDF.js remains a
 * sensible SECOND player registered alongside this one (that is what the registry is
 * for); it is not a prerequisite for reading a PDF.
 *
 * KNOWN LIMITATION, deliberately accepted: iOS Safari and some Android browsers do
 * not render PDFs inline. They show the first page, or nothing — and because they
 * "handle" the type, they do NOT fall through to the <object>'s fallback content.
 * So the fallback below is not merely a nicety for the unsupported case: the visible
 * download link outside the object is what those users get. Do not move it inside.
 */
class PdfEmbed extends AbstractPlayer
{
    protected $name = 'pdf-embed';
    protected $label = 'PDF reader (in the page)'; // @translate
    protected $mediaTypeCapabilities = ['application/pdf'];

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        $url = $media->originalUrl();
        if (!$url) {
            // No original (an externally-described media, or a failed ingest).
            // Nothing to embed and nothing to link to.
            return '';
        }

        $escape = $view->plugin('escapeHtml');
        $escapeAttr = $view->plugin('escapeHtmlAttr');
        $translate = $view->plugin('translate');
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()->appendStylesheet($assetUrl('css/pdf-embed.css', 'AdvancedMedia'));

        // NOT $media->displayTitle(): it falls back to the media's SOURCE, which for
        // this collection is the original ingest URL on a different bucket — putting
        // it in a public attribute leaks internal storage paths for no benefit. A
        // generic aria-label gives assistive tech the handle it needs instead.
        $label = $translate('PDF document');

        // #view=FitH is a PDF Open Parameter: fit the page width, which is what a
        // reader embedded in a column wants. Viewers that do not implement it
        // ignore the fragment, so it is safe everywhere.
        $embedUrl = $url . '#view=FitH';

        // Shown only by browsers that cannot display PDFs at all — see the class
        // note: the ones that display them badly never reach this.
        $thumbnail = $view->plugin('thumbnail');
        $poster = $media->thumbnailUrl('large') ? $thumbnail($media, 'large') : '';

        return sprintf(
            '<div class="advancedmedia-pdf">'
            . '<object class="advancedmedia-pdf-frame" data="%s" type="application/pdf" aria-label="%s">'
            . '<div class="advancedmedia-pdf-fallback">%s<p>%s</p></div>'
            . '</object>'
            . '<p class="advancedmedia-pdf-download"><a href="%s">%s</a></p>'
            . '</div>',
            $escapeAttr($embedUrl),
            $escapeAttr($label),
            $poster,
            $escape($translate('This browser cannot display PDFs in the page.')),
            $escapeAttr($url),
            $escape($translate('Open the PDF'))
        );
    }
}
