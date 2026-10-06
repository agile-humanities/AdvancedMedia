<?php
namespace AdvancedMedia\Media\Compound;

use AdvancedMedia\Media\Player\Manager as PlayerManager;
use AdvancedMedia\Media\PlayerResolver;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Renders a recording-with-images group: the recording first, its images beneath,
 * each as a plain image.
 *
 * AUDIO GOES THROUGH THE CIRCULAR PLAYER DIRECTLY, mirroring AudioTranscript. This
 * is the one place a compound overrides the players band on purpose: the pattern IS
 * "a recording presented with its artwork", and the circular player is the house
 * style for that. It also means these items get the circular player without an
 * admin having to remap `audio/*` globally — a remap that would also catch every
 * loose audio file in the install, which is a much larger decision than this
 * pattern warrants.
 *
 * Everything else in the group — video, and the images — goes through the RESOLVED
 * player, so the players band still governs them and video picks up html5-video
 * without this class knowing anything about video.
 *
 * NOT a whole-item viewer (inherited default): it renders exactly the group it is
 * handed, which is the entire point — the images in this group are precisely the
 * ones the item's IIIF manifest omits.
 */
class AvWithImages extends AbstractCompound
{
    protected $name = 'avWithImages';
    protected $label = 'Recording with images'; // @translate
    protected $supportedStructures = ['av-with-images'];

    /** @var PlayerManager */
    private $players;

    /** @var PlayerResolver */
    private $resolver;

    public function __construct(PlayerManager $players, PlayerResolver $resolver)
    {
        $this->players = $players;
        $this->resolver = $resolver;
    }

    public function render(PhpRenderer $view, array $mediaGroup, array $context): string
    {
        $recordingHtml = '';
        $imageHtml = '';
        foreach ($mediaGroup as $media) {
            if ($this->isImage($media)) {
                $imageHtml .= $this->resolver->resolve($media, $context)->render($view, $media, []);
            } else {
                $recordingHtml .= $this->renderRecording($view, $media, $context);
            }
        }

        return $view->partial('common/compound/av-with-images', [
            'recordingHtml' => $recordingHtml,
            'imageHtml' => $imageHtml,
        ]);
    }

    /**
     * Audio through the circular player; anything else (video) through its resolved
     * player. The player manager is asked rather than assumed — a build without the
     * circular player registered falls back to the mapping rather than fataling.
     */
    private function renderRecording(PhpRenderer $view, MediaRepresentation $media, array $context): string
    {
        $isAudio = strpos((string) $media->mediaType(), 'audio/') === 0;
        if ($isAudio && $this->players->has('circular-audio')) {
            return $this->players->get('circular-audio')->render($view, $media, []);
        }
        return $this->resolver->resolve($media, $context)->render($view, $media, []);
    }

    private function isImage(MediaRepresentation $media): bool
    {
        return strpos((string) $media->mediaType(), 'image/') === 0
            || $media->ingester() === 'iiif';
    }
}
