<?php
namespace AdvancedMedia\Media\Structure;

use Omeka\Api\Representation\ItemRepresentation;

/**
 * Two or more image media — a set, viewable as a gallery.
 *
 * Kept SEPARATE from SingleImage so the two cases are independently
 * configurable: "one image? just show it. A set? give me the viewer." Both ship
 * defaulted to Image Gallery, so parity holds and nothing regresses; an admin can
 * pull them apart (architecture §6.0.2).
 */
class MultipleImages extends AbstractStructure
{
    protected $name = 'multiple-images';
    protected $label = 'Two or more images'; // @translate
    protected $description = 'A set of images, viewable together as a gallery.'; // @translate
    protected $priority = 50;

    protected $requirements = [
        'The item has 2 or more image media (media type image/*, or media ingested via IIIF).', // @translate
        'All of the item\'s images are grouped together and rendered as one viewer.', // @translate
        'Images already claimed by a more specific structure — an embed\'s poster image, for instance — are not counted here.', // @translate
    ];

    public function detect(ItemRepresentation $item, array $remainingMedia): array
    {
        $images = [];
        foreach ($remainingMedia as $media) {
            if ($this->isImage($media)) {
                $images[] = $media;
            }
        }
        // A single group: the viewer paginates the whole set.
        return count($images) >= 2 ? [$images] : [];
    }
}
