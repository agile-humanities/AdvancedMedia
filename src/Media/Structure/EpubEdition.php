<?php
namespace AdvancedMedia\Media\Structure;

use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * An ePub edition attached to an item — one group per ePub.
 *
 * WHY THIS STRUCTURE EXISTS AT ALL, when the reader is just a player. Without it an
 * ePub sitting beside a cover image is INVISIBLE: the cover is claimed by
 * single-image and drawn by Mirador, the ePub is claimed by nothing, and the
 * "only display primary and grouped media" policy (on by default) hides loose media
 * that are not the item's primary. Claiming the file is what makes it *grouped*, and
 * grouped media are always rendered. Item 104317 here is exactly that shape, and
 * showed no ePub at all before this.
 *
 * ⚠️ IT CLAIMS THE ePub AND NOTHING ELSE — no cover, no page images. That is the
 * whole point, and it is what keeps ePubs out of the IIIF path: the item's images
 * stay with the image structures and their manifest-driven viewer, the ePub renders
 * as a separate block, and no item ever ends up with two whole-item viewers
 * competing over it (the duplication that `paged-document` has to guard against by
 * excluding items that hold images). An "ePub with cover" structure could be added
 * later at a higher priority if a curator ever wants the pair treated as one thing;
 * nothing here forecloses it.
 *
 * Priority is uncontended: no other structure looks at application/epub+zip. 60
 * merely leaves room above for that future pairing structure without renumbering.
 */
class EpubEdition extends AbstractStructure
{
    protected $name = 'epub-edition';
    protected $label = 'An ePub edition'; // @translate
    protected $description = 'An item with one or more ePub files.'; // @translate
    protected $priority = 60;

    protected $requirements = [
        'The item has at least one media of type application/epub+zip.', // @translate
        'Each ePub forms its own group: an item may hold several editions, and each is rendered on its own.', // @translate
        'Only the ePub file is claimed. Cover images and page images are left to the image structures, so an item can show both a viewer and a reader.', // @translate
    ];

    public function detect(ItemRepresentation $item, array $remainingMedia): array
    {
        $groups = [];
        foreach ($remainingMedia as $media) {
            if ($this->isEpub($media)) {
                $groups[] = [$media];
            }
        }
        return $groups;
    }

    private function isEpub(MediaRepresentation $media): bool
    {
        return (string) $media->mediaType() === 'application/epub+zip';
    }
}
