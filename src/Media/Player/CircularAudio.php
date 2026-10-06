<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Circular SVG audio player (migrated from AgileThemeTools). Progressive
 * enhancement: renders a native <video controls> that JS hides once it upgrades
 * the container, so audio still plays if JS/jQuery is unavailable.
 *
 * Recognised options: 'transcriptUrl' (VTT url for a caption track),
 * 'transcriptId' (shared id linking the track to a transcript element).
 */
class CircularAudio extends AbstractPlayer
{
    protected $name = 'circular-audio';
    protected $label = 'Circular audio player'; // @translate
    protected $mediaTypeCapabilities = ['audio/*'];

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        $url = $media->originalUrl();
        if (!$url) {
            return '';
        }
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()->appendStylesheet($assetUrl('css/circular-audio.css', 'AdvancedMedia'));
        $view->headScript()->appendFile($assetUrl('js/circular-audio.js', 'AdvancedMedia'));

        return $view->partial('common/player/circular-audio', [
            'audioSrc' => $url,
            'transcriptSrc' => $options['transcriptUrl'] ?? null,
            'transcriptId' => $options['transcriptId'] ?? null,
        ]);
    }
}
