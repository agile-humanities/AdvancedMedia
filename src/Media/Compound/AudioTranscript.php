<?php
namespace AdvancedMedia\Media\Compound;

use AdvancedMedia\Media\Player\Manager as PlayerManager;
use AdvancedMedia\Media\PlayerResolver;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Renders an audio+VTT group as a synced circular audio player with a clickable
 * transcript, followed by any images the structure attached.
 *
 * Detection lives in the `audio-with-transcript` structure; this class only
 * renders the group it is handed.
 *
 * MEMBERS ARE SELECTED BY TYPE, not by position. The group used to be exactly
 * [audio, vtt] and was destructured as such — which silently ignored anything the
 * structure added. Now that images ride along (see the structure's note), a
 * positional read would drop them without a symptom, so the roles are identified
 * explicitly and anything unrecognised still renders through its resolved player
 * rather than vanishing.
 */
class AudioTranscript extends AbstractCompound
{
    protected $name = 'audioTranscript';
    protected $label = 'Audio with Transcript'; // @translate
    protected $supportedStructures = ['audio-with-transcript'];

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
        [$audio, $vtt, $extras] = $this->partition($mediaGroup);

        // The pair is what this compound is for. Without it there is nothing to
        // sync, so fall back to rendering whatever we were handed rather than
        // fataling on a null.
        if (!$audio || !$vtt) {
            $html = '';
            foreach ($mediaGroup as $media) {
                $html .= $this->resolver->resolve($media, $context)->render($view, $media, []);
            }
            return $html;
        }

        $transcriptId = 'advmedia-transcript-' . $vtt->id();

        $audioHtml = $this->players->get('circular-audio')->render($view, $audio, [
            'transcriptUrl' => $vtt->originalUrl(),
            'transcriptId' => $transcriptId,
        ]);
        $transcriptHtml = $this->players->get('transcript')->render($view, $vtt, [
            'transcriptId' => $transcriptId,
        ]);

        $extraHtml = '';
        foreach ($extras as $media) {
            $extraHtml .= $this->resolver->resolve($media, $context)->render($view, $media, []);
        }

        return $view->partial('common/compound/audio-transcript', [
            'audioHtml' => $audioHtml,
            'transcriptHtml' => $transcriptHtml,
            'extraHtml' => $extraHtml,
        ]);
    }

    /**
     * Split the group into its roles: the first audio, the first VTT, and everything
     * else (images, in practice).
     *
     * @return array{0: ?MediaRepresentation, 1: ?MediaRepresentation, 2: MediaRepresentation[]}
     */
    private function partition(array $mediaGroup): array
    {
        $audio = null;
        $vtt = null;
        $extras = [];
        foreach ($mediaGroup as $media) {
            if (!$audio && strpos((string) $media->mediaType(), 'audio/') === 0) {
                $audio = $media;
            } elseif (!$vtt && $this->isVtt($media)) {
                $vtt = $media;
            } else {
                $extras[] = $media;
            }
        }
        return [$audio, $vtt, $extras];
    }

    /**
     * Mirrors AbstractStructure::isVtt — Omeka mis-sniffs VTTs as text/plain, so the
     * extension fallback is not optional.
     */
    private function isVtt(MediaRepresentation $media): bool
    {
        if (strpos(strtolower((string) $media->mediaType()), 'vtt') !== false) {
            return true;
        }
        return strtolower((string) $media->extension()) === 'vtt';
    }
}
