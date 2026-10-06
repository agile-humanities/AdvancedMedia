<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * A media player: something that can render a media as HTML.
 *
 * Players are registered in the `advancedmedia_media_players` plugin manager
 * and discovered by capability. Most players are thin adapters over existing
 * core/module viewers; a few (e.g. the circular audio player) are native.
 */
interface PlayerInterface
{
    /** Machine name; must match the player's registration key. */
    public function getName(): string;

    /** Human-readable label for config dropdowns. */
    public function getLabel(): string;

    /**
     * Media-type patterns this player can render, e.g. ['audio/*'],
     * ['application/pdf'], or ['*'] for any. Drives config-UI filtering.
     *
     * @return string[]
     */
    public function getMediaTypeCapabilities(): array;

    /**
     * Whether this player is usable in this install (e.g. its backing module
     * is present). Unavailable players are hidden from config and never resolved.
     */
    public function isAvailable(): bool;

    /** Runtime check that this player can render this specific media. */
    public function supports(MediaRepresentation $media, array $context = []): bool;

    /** Render the player markup. */
    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string;
}
