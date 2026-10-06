<?php
namespace AdvancedMedia\Media\Structure;

use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * A PDF attached to an item that also holds a set of page images.
 *
 * The pattern is a digitised document held twice: page images for the viewer, and
 * a PDF of the same document. The PDF is then a duplicate of what the gallery
 * already shows, and a site may reasonably want it offered as a download rather
 * than opened in a second reader below the viewer. This structure exists so that
 * choice can be made in one place, per site, instead of being hard-coded.
 *
 * ⚠️ IT CLAIMS THE PDF AND NOTHING ELSE. The images stay with the image
 * structures, so the gallery keeps whatever compound the `multiple-images` row is
 * set to. Claiming the images here as well would split gallery configuration
 * across two rows, and an admin who later changed `multiple-images` would find
 * these items silently not following it.
 *
 * ⚠️ TWO OR MORE IMAGES, NOT ONE. A single image beside a PDF is a different
 * thing — a cover, poster or representative thumbnail for a document whose real
 * content is the PDF — so suppressing the PDF there would hide the item's
 * substance. Those items are deliberately left to `paged-document`.
 *
 * ⚠️ THE IMAGE COUNT COMES FROM $item->media(), NOT $remainingMedia. By the time
 * this runs (priority 45) the image structures at 50 have already claimed every
 * image, so the remaining pool holds none and a pool-based test would never fire.
 * The same mistake was caught in testing once before, in paged-document's guard.
 *
 * Priority sits between the image structures (50) and paged-document (40): after
 * the images are claimed so their presence can be read, before paged-document so
 * this more specific pattern takes the PDF first.
 */
class PdfWithImageSet extends AbstractStructure
{
    /** An item needs at least this many images before its PDF is treated as a duplicate. */
    const MINIMUM_IMAGES = 2;

    protected $name = 'pdf-with-image-set';
    protected $label = 'A PDF attached to an image set'; // @translate
    protected $description = 'An item with two or more images and a PDF of the same document.'; // @translate
    protected $priority = 45;

    protected $requirements = [
        'The item has two or more image media.', // @translate
        'The item also has at least one PDF.', // @translate
        'Only the PDF is claimed here. The images are rendered by the image structures as usual, so the gallery is unaffected.', // @translate
        'An item with just ONE image and a PDF is not this pattern — that image is usually a cover or representative thumbnail — and its PDF is handled by the "PDFs" row instead.', // @translate
        'Set this row to "Do not display" to suppress the PDF viewer on these items. The file remains downloadable wherever the Downloads block and its per-type settings allow it.', // @translate
    ];

    public function detect(ItemRepresentation $item, array $remainingMedia): array
    {
        if ($this->imageCount($item) < self::MINIMUM_IMAGES) {
            return [];
        }

        $pdfs = [];
        foreach ($remainingMedia as $media) {
            if ($this->isPdf($media)) {
                $pdfs[] = $media;
            }
        }

        // One group holding every PDF on the item: they share a single fate here,
        // and a row that could hide one PDF but not its neighbour would be a
        // setting no curator asked for.
        return $pdfs ? [$pdfs] : [];
    }

    /** Counted across the item, not the unclaimed pool — see the class note. */
    private function imageCount(ItemRepresentation $item): int
    {
        $count = 0;
        foreach ($item->media() as $media) {
            if ($this->isImage($media)) {
                $count++;
            }
        }
        return $count;
    }

    private function isPdf(MediaRepresentation $media): bool
    {
        if (strpos((string) $media->mediaType(), 'application/pdf') === 0) {
            return true;
        }
        return strtolower((string) $media->extension()) === 'pdf';
    }
}
