<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * The OHMS viewer, through the OHMS Embed module (an adapter, architecture §4.3).
 *
 * OHMS Embed registers a file renderer for `.xml` media that iframes its bundled ohms.js viewer with the XML's
 * original URL; the viewer parses the XML and plays the interview's audio or video itself (YouTube, Vimeo,
 * Kaltura, Aviary, or a plain media URL — for media hosted in Omeka, the media's original URL). This player
 * delegates the markup to that renderer (so 1.0.0 and 1.0.1 render exactly as the module does, title attribute
 * included) and wraps it so the theme can size the panel.
 *
 * Available only while OHMS Embed is active: without it the player vanishes from the config dropdowns and the
 * resolver falls through to `core-native`, which is Omeka's thumbnail fallback for XML.
 */
class Ohms extends AbstractPlayer
{
    const RENDERER_CLASS = 'OhmsEmbed\\Media\\FileRenderer\\OhmsEmbedRenderer';

    protected $name = 'ohms';
    protected $label = 'OHMS viewer (OHMS Embed module)'; // @translate
    protected $mediaTypeCapabilities = ['text/xml', 'application/xml'];

    public function isAvailable(): bool
    {
        return class_exists(self::RENDERER_CLASS);
    }

    public function supports(MediaRepresentation $media, array $context = []): bool
    {
        // Only files OHMS Embed would render: XML by extension (Omeka types uploads as text/xml).
        return $this->isAvailable()
            && strtolower((string) $media->extension()) === 'xml'
            && (bool) $media->originalUrl();
    }

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        if (!$this->supports($media)) {
            return '';
        }
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()->appendStylesheet($assetUrl('css/ohms.css', 'AdvancedMedia'));
        $rendererClass = self::RENDERER_CLASS;
        $html = (new $rendererClass())->render($view, $media, $options);
        return '<div class="advancedmedia-ohms">' . $html . '</div>';
    }
}
