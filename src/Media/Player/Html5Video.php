<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/** Native HTML5 <video> player for video/* media. */
class Html5Video extends AbstractPlayer
{
    protected $name = 'html5-video';
    protected $label = 'Video player (HTML5)'; // @translate
    protected $mediaTypeCapabilities = ['video/*'];

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        $url = $media->originalUrl();
        if (!$url) {
            return '';
        }
        $escapeAttr = $view->plugin('escapeHtmlAttr');
        $poster = $media->thumbnailUrl('large');
        $posterAttr = $poster ? sprintf(' poster="%s"', $escapeAttr($poster)) : '';
        return sprintf(
            '<div class="advancedmedia-video"><video controls preload="metadata" src="%s"%s></video></div>',
            $escapeAttr($url),
            $posterAttr
        );
    }
}
