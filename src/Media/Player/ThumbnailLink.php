<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Renders a large thumbnail linking to the original file — the graceful
 * fallback for media without a richer viewer.
 */
class ThumbnailLink extends AbstractPlayer
{
    protected $name = 'thumbnail-link';
    protected $label = 'Thumbnail link'; // @translate
    protected $mediaTypeCapabilities = ['*'];

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        $thumbnail = $view->plugin('thumbnail');
        $img = $thumbnail($media, 'large');
        $url = $media->originalUrl();
        if ($url) {
            $escapeAttr = $view->plugin('escapeHtmlAttr');
            return sprintf(
                '<div class="advancedmedia-thumbnail"><a href="%s">%s</a></div>',
                $escapeAttr($url),
                $img
            );
        }
        return sprintf('<div class="advancedmedia-thumbnail">%s</div>', $img);
    }
}
