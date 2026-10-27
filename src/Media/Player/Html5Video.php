<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Native HTML5 <video> player for video/* media.
 *
 * The frame, its proportion and the letterboxing are CSS (asset/css/html5-video.css):
 * the markup is deliberately just a wrapper and a <video>.
 */
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

        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()->appendStylesheet($assetUrl('css/html5-video.css', 'AdvancedMedia'));

        $escapeAttr = $view->plugin('escapeHtmlAttr');

        // ⚠️ A POSTER ONLY WHEN THE MEDIA HAS A REAL THUMBNAIL.
        //
        // thumbnailUrl() never returns nothing: for a media with no derivative of
        // its own, Omeka substitutes its stock per-type placeholder — for video, a
        // grey film-strip icon (application/asset/thumbnails/video.png). As a
        // poster that is worse than no poster: it is 200px square, so it both
        // looks like a broken image and dictates the size of the player. Omeka
        // makes no thumbnails for video unless something is installed to (ffmpeg),
        // so on most installs EVERY video would get it.
        //
        // Without a poster the browser shows the film's own first frame (that is
        // what preload="metadata" is for), on the dark frame the CSS provides.
        $posterAttr = '';
        if ($media->hasThumbnails()) {
            $poster = $media->thumbnailUrl('large');
            if ($poster) {
                $posterAttr = sprintf(' poster="%s"', $escapeAttr($poster));
            }
        }

        return sprintf(
            '<div class="advancedmedia-video"><video controls preload="metadata" playsinline src="%s"%s></video></div>',
            $escapeAttr($url),
            $posterAttr
        );
    }
}
