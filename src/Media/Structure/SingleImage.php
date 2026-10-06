<?php
namespace AdvancedMedia\Media\Structure;

use Omeka\Api\Representation\ItemRepresentation;

/**
 * Exactly one image medium.
 *
 * This structure exists so that single-image items remain configurable
 * independently of image SETS. 1,837 items here have exactly one image; if only
 * "two or more images" were a structure, every one of them would fall to
 * individual rendering — a regression from the IIIF viewer they get today
 * (architecture §6.0.2). Ships defaulted to the same compound as MultipleImages,
 * so behaviour is unchanged until an admin deliberately separates them.
 */
class SingleImage extends AbstractStructure
{
    protected $name = 'single-image';
    protected $label = 'Exactly one image'; // @translate
    protected $description = 'An item whose media is a single image.'; // @translate
    protected $priority = 50;

    protected $requirements = [
        'The item has exactly 1 image media (media type image/*, or media ingested via IIIF).', // @translate
        'An image already claimed by a more specific structure — an embed\'s poster image, for instance — is not counted here.', // @translate
    ];

    public function detect(ItemRepresentation $item, array $remainingMedia): array
    {
        $images = [];
        foreach ($remainingMedia as $media) {
            if ($this->isImage($media)) {
                $images[] = $media;
            }
        }
        return count($images) === 1 ? [$images] : [];
    }
}
