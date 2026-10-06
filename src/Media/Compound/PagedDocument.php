<?php
namespace AdvancedMedia\Media\Compound;

use AdvancedMedia\Media\PlayerResolver;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Renders a PDF group through Universal Viewer as a paged document.
 *
 * An adapter, not a viewer: we orchestrate UV, we do not rebuild it (architecture
 * §3). UV renders from the item's IIIF manifest — it resolves any media handed to
 * it back to the parent item — so it is invoked with the item.
 *
 * Availability-gated (architecture §1.4): UV needs its own view helper and
 * IiifServer's to build the manifest. If either is absent the compound reports
 * unavailable, disappears from the config dropdowns, and the paged-document row
 * falls back to individual rendering rather than erroring.
 */
class PagedDocument extends AbstractCompound
{
    protected $name = 'pagedDocument';
    protected $label = 'Paged Document'; // @translate
    protected $supportedStructures = ['paged-document'];

    /** UV resolves any media back to the parent item's manifest — see the class note. */
    protected $wholeItemViewer = true;

    /** @var bool Whether the UniversalViewer + IiifServer view helpers are present. */
    private $available;

    /** @var PlayerResolver */
    private $resolver;

    public function __construct(bool $available, PlayerResolver $resolver)
    {
        $this->available = $available;
        $this->resolver = $resolver;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function render(PhpRenderer $view, array $mediaGroup, array $context): string
    {
        $item = $mediaGroup[0]->item();

        // UV returns '' when it cannot build a manifest (no IiifServer, an
        // unsupported resource). Fall back so the document never vanishes.
        $universalViewer = $view->plugin('universalViewer');
        $viewerHtml = trim((string) $universalViewer($item));
        if ($viewerHtml !== '') {
            return $view->partial('common/compound/paged-document', [
                'viewerHtml' => $viewerHtml,
                'item' => $item,
            ]);
        }

        $fallback = '';
        foreach ($mediaGroup as $media) {
            $fallback .= $this->resolver->resolve($media, $context)->render($view, $media, []);
        }
        return $fallback;
    }
}
