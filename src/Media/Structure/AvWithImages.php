<?php
namespace AdvancedMedia\Media\Structure;

use Omeka\Api\Representation\ItemRepresentation;

/**
 * A recording (audio or video) accompanied by one or more images — a lecture with
 * a photograph, a podcast with cover art.
 *
 * WHY THIS EXISTS, and why it must claim the IMAGES and not just the recording:
 *
 * IiifServer builds a manifest's canvases from EXACTLY ONE media type, chosen by a
 * hardcoded priority chain (IiifServer\Iiif\TraitMediaInfo):
 *
 *     Model  >  Video  >  Sound  >  Image  >  Text
 *
 * — on the stated assumption that a recording plus an image "indicates an
 * audiovisual document like a movie or a podcast with a cover image". So on any
 * item holding a recording, EVERY image is demoted out of the canvases and into the
 * manifest's `rendering` array (a download link). Verified on item 104462: one
 * `Sound` canvas, the JPEG and PDF both in `rendering`.
 *
 * The consequence is that the image structures must not touch these images. Left
 * alone, `single-image` / `multiple-images` claim them and hand them to Mirador —
 * which renders the item manifest, finds only the recording, and plays the AUDIO in
 * the image viewer while the image itself appears nowhere. That is the visible bug
 * this structure removes.
 *
 * ONE OR MORE IMAGES, deliberately, not exactly one. The IiifServer rule suppresses
 * ALL image canvases whenever a recording is present, so an item with two images is
 * broken in exactly the same way — and a strict single-image test would let it fall
 * through to `multiple-images` and silently reproduce the bug. Every affected item
 * here happens to hold exactly one image today; claiming any number costs nothing
 * and means a second photograph never quietly reopens this.
 *
 * VIDEO AS WELL AS AUDIO for the same reason: Video sits ABOVE Sound in that chain,
 * so video+image breaks identically. There are no such items here yet — this is the
 * cheap half of the fix, taken now rather than rediscovered later.
 *
 * Priority 95: below `audio-with-transcript` (100), which claims the richer
 * audio+VTT pattern first and carries any images itself; above the image structures
 * (50), so the images are claimed before Mirador can be handed them.
 */
class AvWithImages extends AbstractStructure
{
    protected $name = 'av-with-images';
    protected $label = 'Audio or video with images'; // @translate
    protected $description = 'A recording accompanied by a cover or illustrative image.'; // @translate
    protected $priority = 95;

    protected $requirements = [
        'The item has at least one audio or video media.', // @translate
        'The item has at least one image media.', // @translate
        'The images are claimed by this structure and shown beside the recording as plain images. This is deliberate: the IIIF manifest of an item that holds a recording excludes every image, so an image viewer (Mirador, Universal Viewer) cannot display them — it would show the recording instead.', // @translate
        'Audio paired with a WebVTT transcript is claimed first by “Audio with a transcript”, which carries any images itself.', // @translate
    ];

    public function detect(ItemRepresentation $item, array $remainingMedia): array
    {
        $recordings = [];
        $images = [];
        foreach ($remainingMedia as $media) {
            if ($this->isAudio($media) || $this->isVideo($media)) {
                $recordings[] = $media;
            } elseif ($this->isImage($media)) {
                $images[] = $media;
            }
        }

        // Both halves are required: a lone recording is rendered perfectly well by
        // its own player, and lone images belong to the image structures.
        if (!$recordings || !$images) {
            return [];
        }

        // ONE group, not one per recording. The pattern is "this item is a
        // recording, and here are its images" — splitting it would raise the
        // unanswerable question of which recording each image belongs to.
        return [array_merge($recordings, $images)];
    }

    private function isVideo($media): bool
    {
        return strpos((string) $media->mediaType(), 'video/') === 0;
    }
}
