<?php
namespace AdvancedMedia\Media\Structure;

use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * A paged document (PDF), viewable in a paged viewer.
 *
 * PDF-SPECIFIC BY DESIGN, not by accident (architecture.md §6.0, the structure
 * registry table: "Detects: a PDF"). Paginated *image* viewing is a different pair
 * of rows — `single-image` and `multiple-images`, both backed by Mirador over the
 * IIIF manifest. This row exists so that PDFs, which no image structure will ever
 * claim, have a renderer of their own that an admin can choose.
 *
 * THIS DETECTOR USED TO REFUSE ITEMS THAT ALSO HELD IMAGES. That rule was stated at
 * the wrong altitude and has been removed. Its real subject was never PDFs: it was
 * that UV and Mirador are WHOLE-ITEM viewers (UV resolves any media back to its
 * parent item's manifest; Mirador renders the item manifest), so two of them on one
 * page draw the item's contents twice. Encoding that as "no PDFs alongside images"
 *
 *   - stayed in force even when this row was mapped to a per-media renderer, which
 *     has no such constraint — the reason mixed items showed no PDF at all; and
 *   - only ever covered the PDF-plus-gallery case, not the general one.
 *
 * The constraint now lives on the COMPOUND (`isWholeItemViewer()`) and is enforced
 * once, in the Composer. Map this row back to Universal Viewer and the protection
 * re-arms automatically, because the flag travels with the compound rather than
 * being re-guessed here.
 */
class PagedDocument extends AbstractStructure
{
    protected $name = 'paged-document';
    protected $label = 'A PDF document'; // @translate
    protected $description = 'A PDF, viewable page by page.'; // @translate
    protected $priority = 40;

    protected $requirements = [
        'The item has at least one PDF media. All of the item’s PDFs are treated as one document.', // @translate
        'Images are not affected: they are claimed first by the image structures, so an item holding both shows its gallery AND its PDF.', // @translate
        'Universal Viewer displays the whole item, so it cannot appear alongside another whole-item viewer. On an item that also has an image gallery, this row falls back to displaying the PDFs individually — through whichever player the PDF media type is mapped to.', // @translate
    ];

    public function detect(ItemRepresentation $item, array $remainingMedia): array
    {
        $pdfs = [];
        foreach ($remainingMedia as $media) {
            if ($this->isPdf($media)) {
                $pdfs[] = $media;
            }
        }
        // A single group: the viewer paginates the whole document.
        return $pdfs ? [$pdfs] : [];
    }

    private function isPdf(MediaRepresentation $media): bool
    {
        if ((string) $media->mediaType() === 'application/pdf') {
            return true;
        }
        return strtolower((string) $media->extension()) === 'pdf';
    }
}
