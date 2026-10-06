<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Renders any media as a download link. Universal capability — this is the
 * player an item/media is mapped to (globally, per-site, or as an exception)
 * when it should appear as a download rather than an inline viewer.
 */
class Download extends AbstractPlayer
{
    protected $name = 'download';
    protected $label = 'Download link'; // @translate
    protected $mediaTypeCapabilities = ['*'];

    public function supports(MediaRepresentation $media, array $context = []): bool
    {
        // Only meaningful when there is an original file to download.
        return parent::supports($media, $context) && (bool) $media->originalUrl();
    }

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        $url = $media->originalUrl();
        if (!$url) {
            return '';
        }
        $escapeAttr = $view->plugin('escapeHtmlAttr');
        $escape = $view->plugin('escapeHtml');
        $filename = $options['filename'] ?? $media->filename();
        $label = $options['label'] ?? $media->displayTitle();
        return sprintf(
            '<div class="advancedmedia-download"><a class="download" href="%s" download="%s">%s</a></div>',
            $escapeAttr($url),
            $escapeAttr((string) $filename),
            $escape((string) ($label !== '' ? $label : 'Download')) // @translate
        );
    }
}
