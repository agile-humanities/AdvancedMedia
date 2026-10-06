<?php
namespace AdvancedMedia\Media\Compound;

use AdvancedMedia\Media\Player\Manager as PlayerManager;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Renders a single-image item with the PhotoSwipe player.
 *
 * This is the second compound offered for the `single-image` structure, and the
 * clearest illustration of why structures and compounds are separate things: one
 * pattern — "this item is a single image" — with two legitimate renderings, and an
 * admin choosing between them per site. Mirador brings IIIF deep zoom and its own
 * furniture; PhotoSwipe shows the picture and gets out of the way until asked.
 *
 * Not a whole-item viewer: it draws the media it is handed, so it can sit beside
 * anything else on the page without the duplication that two IIIF viewers cause.
 */
class PhotoSwipe extends AbstractCompound
{
    protected $name = 'photoswipe';
    protected $label = 'PhotoSwipe'; // @translate
    protected $supportedStructures = ['single-image'];

    /** @var PlayerManager */
    private $players;

    public function __construct(PlayerManager $players)
    {
        $this->players = $players;
    }

    public function render(PhpRenderer $view, array $mediaGroup, array $context): string
    {
        $player = $this->players->get('photoswipe');

        $output = '';
        foreach ($mediaGroup as $media) {
            $output .= $player->render($view, $media, []);
        }
        return $output;
    }
}
