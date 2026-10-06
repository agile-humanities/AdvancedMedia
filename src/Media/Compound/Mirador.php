<?php
namespace AdvancedMedia\Media\Compound;

use AdvancedMedia\Media\PlayerResolver;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Renders an image group through Mirador as a single IIIF viewer over the item's
 * manifest — matching the legacy AgileThemeTools behavior.
 *
 * NAMED FOR THE VIEWER, DELIBERATELY. The original design named compounds for the
 * *experience* ("Image Gallery") rather than the viewer, because the compound was the
 * only name an admin ever saw. The structures refactor (architecture §6.0) changed
 * that: the STRUCTURE now carries the experience ("Two or more images") and the
 * compound is the *implementation* chosen for it. So the row reads
 *
 *     Two or more images   →   [ Mirador ▾ ]     (also: Slideshow · None)
 *
 * which is both accurate and what this client actually says out loud. "Image Gallery"
 * was a leftover of the earlier model, and ambiguous besides — there are many kinds of
 * image gallery, and only one Mirador.
 *
 * Supports BOTH image structures. They ship mapped to this same compound, so a
 * single-image item and an image set behave identically until an admin separates them.
 *
 * Availability-gated (architecture §1.4): if the Mirador or IiifServer view helpers are
 * absent, the compound reports unavailable, disappears from the config dropdowns, and
 * its structure rows fall back to individual rendering rather than erroring.
 */
class Mirador extends AbstractCompound
{
    protected $name = 'mirador';
    protected $label = 'Mirador'; // @translate
    protected $supportedStructures = ['single-image', 'multiple-images'];

    /** Renders the item's IIIF manifest — every canvas, not just the group. */
    protected $wholeItemViewer = true;

    /** @var bool Whether the Mirador + IiifServer view helpers are present. */
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

        // Mirador renders from the item's IIIF manifest. It returns '' when it cannot
        // build one; in that case fall back so the images never vanish.
        $mirador = $view->plugin('mirador');
        $galleryHtml = trim((string) $mirador($item));
        if ($galleryHtml !== '') {
            return $view->partial('common/compound/mirador', [
                'galleryHtml' => $galleryHtml,
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
