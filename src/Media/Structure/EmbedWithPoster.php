<?php
namespace AdvancedMedia\Media\Structure;

use Omeka\Api\Representation\ItemRepresentation;

/**
 * An embedded third-party interactive viewer (a Matterport 3D tour, or any other
 * HTML/iframe embed) with an optional poster image.
 *
 * DECLARATIVE, VIA PRIMARY MEDIA (architecture §6.0.1). The earlier version of
 * this rule was a heuristic — "one html + one image + nothing else in the pool" —
 * which reverse-engineered from media counts what the curators had already stated
 * outright: every Interactive Resource item here has its HTML embed DESIGNATED as
 * the item's primary media, most of them at position 2, deliberately overriding
 * attachment order. Reading that designation says the same thing directly, and
 * says it in terms a curator can act on.
 *
 * Primary media is consulted, never required: primaryOrFirst() falls back to
 * attachment order, so embed-only items with no designation still match.
 */
class EmbedWithPoster extends AbstractStructure
{
    protected $name = 'embed-with-poster';
    protected $label = 'Primary media is an embed'; // @translate
    protected $description = 'An HTML embed (e.g. a Matterport 3D tour), optionally with a poster image.'; // @translate
    protected $priority = 90;

    protected $requirements = [
        "The item's primary media is an HTML media — an embed such as a Matterport iframe.", // @translate
        'If no primary media has been designated, the first attached media is used instead.', // @translate
        'Optionally, one image media may accompany the embed. It is treated as a poster and is not displayed.', // @translate
        'The poster is only recognised when the embed and that one image are the item\'s ONLY media. If there are other media (a PDF, say), the image is left alone and rendered as an image.', // @translate
        'Troubleshooting: if an embed item is not rendering as an Interactive Resource, designate the HTML media as the item\'s primary media.', // @translate
    ];

    public function detect(ItemRepresentation $item, array $remainingMedia): array
    {
        $embed = $this->primaryOrFirst($item);
        if (!$embed || !$this->isHtml($embed) || !$this->inPool($embed, $remainingMedia)) {
            return [];
        }

        $group = [$embed];

        // The poster is only unambiguous when the embed and a single image are all
        // the item has. With anything else in the pool the image cannot be assumed
        // to belong to the embed — in the pdf + image + html items, for instance,
        // the image is the document's thumbnail — so it is left to the image
        // structures.
        $images = [];
        foreach ($remainingMedia as $media) {
            if ($this->isImage($media)) {
                $images[] = $media;
            }
        }
        if (count($images) === 1 && count($remainingMedia) === 2) {
            $group[] = $images[0];
        }

        return [$group];
    }
}
