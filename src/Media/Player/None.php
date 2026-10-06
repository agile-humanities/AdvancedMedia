<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Renders nothing. Mapping a media type to this player suppresses it entirely —
 * media of that type are not displayed on the item page.
 *
 * Universal capability, so it can be chosen for any media-type row in the config.
 * (Distinct from the structures column's "None — Individual media", which ungroups
 * a pattern; this hides the media outright.)
 */
class None extends AbstractPlayer
{
    protected $name = 'none';
    protected $label = 'None — do not display'; // @translate
    protected $mediaTypeCapabilities = ['*'];

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        return '';
    }
}
