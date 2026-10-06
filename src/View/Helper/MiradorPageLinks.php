<?php
namespace AdvancedMedia\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Laminas\View\Helper\HelperInterface;
use Laminas\View\Renderer\RendererInterface as Renderer;

/**
 * Decorates the Mirador module's `mirador` helper: renders exactly as before, then
 * adds the page-links script when a viewer was actually rendered
 * (docs/mirador-page-links.md). Installed by MiradorPageLinksDelegator.
 *
 * Callers are unaffected. The stock helper's only public method is __invoke, and the
 * only references to its concrete class are `@var` docblocks (AgileThemeTools and
 * pitts3 item-media.phtml), which are IDE hints with no runtime effect.
 */
class MiradorPageLinks extends AbstractHelper
{
    /** @var HelperInterface|callable The stock Mirador helper. */
    private $inner;

    public function __construct(HelperInterface $inner)
    {
        $this->inner = $inner;
    }

    /**
     * Arguments pass straight through, so the stock signature
     * `($resource, $options = [])` is preserved whatever it becomes.
     */
    public function __invoke(...$args)
    {
        $html = ($this->inner)(...$args);

        // The stock helper returns '' when it cannot build a viewer; add nothing then.
        if (trim((string) $html) !== '') {
            $view = $this->getView();
            // Appended AFTER the inner call, which is what places this file after the
            // Mirador module's js/mirador.js in defer order: the script must run after the
            // inline `miradors[id] = {config}` but before Mirador boots on jQuery ready.
            // HeadScript dedupes by src, so rendering several viewers adds one tag.
            $view->headScript()->appendFile(
                $view->assetUrl('js/mirador-page-links.js', 'AdvancedMedia'),
                'text/javascript',
                ['defer' => 'defer']
            );
        }

        return $html;
    }

    /**
     * The helper manager injects the view into what the delegator returned (this
     * object), never into the helper it wraps — so forward it, or the stock helper
     * renders with no view.
     */
    public function setView(Renderer $view)
    {
        parent::setView($view);
        $this->inner->setView($view);
        return $this;
    }
}
