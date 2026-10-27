<?php
namespace AdvancedMedia\Media\Compound;

use AdvancedMedia\Media\Player\Manager as PlayerManager;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Renders an OHMS interview group: the OHMS viewer for the XML; the grouped audio/video file is not rendered
 * (the viewer plays it). Detection lives in the `ohms-interview` structure.
 */
class OhmsInterview extends AbstractCompound
{
    protected $name = 'ohmsInterview';
    protected $label = 'OHMS viewer'; // @translate
    protected $supportedStructures = ['ohms-interview'];

    /** @var PlayerManager */
    private $players;

    public function __construct(PlayerManager $players)
    {
        $this->players = $players;
    }

    public function isAvailable(): bool
    {
        return $this->players->get('ohms')->isAvailable();
    }

    public function render(PhpRenderer $view, array $mediaGroup, array $context): string
    {
        foreach ($mediaGroup as $media) {
            if (strtolower((string) $media->extension()) === 'xml') {
                $player = $this->players->get('ohms');
                if ($player->supports($media, $context)) {
                    return $player->render($view, $media, []);
                }
                return $this->players->get('core-native')->render($view, $media, []);
            }
        }
        return '';
    }
}
