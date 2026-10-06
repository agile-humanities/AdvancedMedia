<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/** Native HTML5 <audio> player for audio/* media. */
class Html5Audio extends AbstractPlayer
{
    protected $name = 'html5-audio';
    protected $label = 'Audio player (HTML5)'; // @translate
    protected $mediaTypeCapabilities = ['audio/*'];

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        $url = $media->originalUrl();
        if (!$url) {
            return '';
        }
        $escapeAttr = $view->plugin('escapeHtmlAttr');
        return sprintf(
            '<div class="advancedmedia-audio"><audio controls preload="metadata" src="%s"></audio></div>',
            $escapeAttr($url)
        );
    }
}
