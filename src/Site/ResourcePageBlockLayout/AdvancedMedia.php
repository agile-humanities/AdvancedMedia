<?php
namespace AdvancedMedia\Site\ResourcePageBlockLayout;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Item-page block that renders each of the item's media through the resolver.
 *
 * Stage (a): this is effectively the identity composition (each media on its
 * own, in attachment order). Stage (b) replaces the loop with the Composer so
 * compound types (Audio with Transcript, Image Gallery, …) can claim media.
 */
class AdvancedMedia implements ResourcePageBlockLayoutInterface
{
    public function getLabel(): string
    {
        return 'Advanced media'; // @translate
    }

    public function getCompatibleResourceNames(): array
    {
        return ['items'];
    }

    public function render(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): string
    {
        return $view->partial(
            'common/resource-page-block-layout/advanced-media',
            ['item' => $resource]
        );
    }
}
