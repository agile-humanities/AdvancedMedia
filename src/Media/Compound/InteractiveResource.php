<?php
namespace AdvancedMedia\Media\Compound;

use AdvancedMedia\Media\Player\Manager as PlayerManager;
use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Renders an embed+poster group: the third-party interactive viewer (a Matterport
 * 3D tour, or any HTML/iframe embed).
 *
 * Parity today: render the embed, suppress the poster. Because the structure hands
 * us BOTH media, reinstating the poster after design review (e.g. as a
 * click-to-load placeholder) is a change to the partial alone.
 *
 * Detection lives in the `embed-with-poster` structure.
 */
class InteractiveResource extends AbstractCompound
{
    protected $name = 'interactiveResource';
    protected $label = 'Interactive Resource'; // @translate
    protected $supportedStructures = ['embed-with-poster'];

    /** @var PlayerManager */
    private $players;

    public function __construct(PlayerManager $players)
    {
        $this->players = $players;
    }

    public function render(PhpRenderer $view, array $mediaGroup, array $context): string
    {
        $embed = null;
        $poster = null;
        foreach ($mediaGroup as $media) {
            if ($this->isHtml($media)) {
                $embed = $media;
            } elseif ($this->isImage($media)) {
                $poster = $media;
            }
        }
        if (!$embed) {
            return '';
        }

        // core-native reproduces Omeka's own HTML-media rendering, which is what
        // carries the third-party embed markup.
        $embedHtml = $this->players->get('core-native')->render($view, $embed, []);

        return $view->partial('common/compound/interactive-resource', [
            'embedHtml' => $embedHtml,
            'embed' => $embed,
            'poster' => $poster,
        ]);
    }

    private function isHtml(MediaRepresentation $media): bool
    {
        return $media->ingester() === 'html' || $media->renderer() === 'html';
    }

    private function isImage(MediaRepresentation $media): bool
    {
        return strpos((string) $media->mediaType(), 'image/') === 0;
    }
}
