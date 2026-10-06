<?php
namespace AdvancedMedia\Media\Structure;

use Omeka\Api\Representation\ItemRepresentation;

/**
 * Audio media paired with a VTT transcript, plus any images the item carries.
 *
 * v1 pairing is positional (audio[i] with vtt[i]) — faithful to the migrated
 * AgileThemeTools behavior. Role-based clustering (disambiguating multiple pairs
 * by association rather than order) is deferred (architecture §12).
 *
 * IMAGES RIDE ALONG for the same reason `av-with-images` exists: IiifServer builds
 * canvases from one media type only (Sound outranks Image), so on an item holding
 * audio, EVERY image is demoted out of the manifest into a download link. Left
 * unclaimed here, those images would fall to `single-image`/`multiple-images` and be
 * handed to Mirador, which would render the manifest, find only the audio, and play
 * the recording inside the image viewer. Claiming them keeps them displayable.
 *
 * They attach to the FIRST pair. With one audio+VTT pair — which is every such item
 * here — that is unambiguous. With several, "which pair does this photograph belong
 * to?" has no answer in the data, and attaching to the first is both arbitrary and
 * better than the alternative of a viewer that cannot show them at all. Same
 * pragmatism as the positional pairing above, and flagged in the requirements so an
 * admin sees it.
 */
class AudioWithTranscript extends AbstractStructure
{
    protected $name = 'audio-with-transcript';
    protected $label = 'Audio with a transcript'; // @translate
    protected $description = 'An audio file accompanied by a WebVTT transcript.'; // @translate
    protected $priority = 100;

    protected $requirements = [
        'The item has at least one audio media (media type audio/*).', // @translate
        'The item has at least one WebVTT media. A .vtt file is recognised by its extension even when Omeka stores it as text/plain.', // @translate
        'Audio and transcripts are paired in attachment order: the first audio with the first transcript, the second with the second, and so on.', // @translate
        'An audio file with no transcript is not part of this structure — it will be rendered on its own.', // @translate
        'Any images on the item are claimed too and shown beneath the transcript as plain images, because the IIIF manifest of an item holding audio excludes every image — an image viewer would show the recording instead. With more than one audio+transcript pair, the images attach to the first.', // @translate
    ];

    public function detect(ItemRepresentation $item, array $remainingMedia): array
    {
        $audios = [];
        $vtts = [];
        $images = [];
        foreach ($remainingMedia as $media) {
            if ($this->isAudio($media)) {
                $audios[] = $media;
            } elseif ($this->isVtt($media)) {
                $vtts[] = $media;
            } elseif ($this->isImage($media)) {
                $images[] = $media;
            }
        }

        $groups = [];
        $pairCount = min(count($audios), count($vtts));
        for ($i = 0; $i < $pairCount; $i++) {
            // Only a complete audio+VTT pair is claimed; unpaired audio or VTT is
            // left behind for individual rendering.
            $group = [$audios[$i], $vtts[$i]];
            if ($i === 0) {
                // See the class note: images ride with the first pair rather than
                // being left for an image viewer that cannot display them.
                $group = array_merge($group, $images);
            }
            $groups[] = $group;
        }
        return $groups;
    }
}
