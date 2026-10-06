<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Delegates to Omeka core's own rendering for the media. This is the guaranteed
 * system fallback — it reproduces default Omeka behavior for any media,
 * including non-file media (YouTube, oEmbed, HTML, IIIF).
 */
class CoreNative extends AbstractPlayer
{
    protected $name = 'core-native';
    protected $label = 'Omeka default'; // @translate
    protected $mediaTypeCapabilities = ['*'];

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        return $media->render($options);
    }
}
