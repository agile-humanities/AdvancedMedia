<?php
namespace AdvancedMedia\View\Helper;

use AdvancedMedia\Media\Composer;
use Omeka\Api\Representation\ItemRepresentation;
use Laminas\View\Helper\AbstractHelper;

/**
 * Compose and render all of an item's media through the compound composer.
 *
 * Usage in a template: `echo $this->advancedMediaCompose($item);`
 */
class AdvancedMediaCompose extends AbstractHelper
{
    /** @var Composer */
    private $composer;

    public function __construct(Composer $composer)
    {
        $this->composer = $composer;
    }

    public function __invoke(ItemRepresentation $item, array $options = []): string
    {
        $view = $this->getView();
        $context = ['context' => $options['context'] ?? 'page'];

        $helpers = $view->getHelperPluginManager();
        if ($helpers->has('currentSite')) {
            $site = $view->currentSite();
            if ($site) {
                $context['site'] = $site->id();
            }
        }

        return $this->composer->compose($view, $item, $context);
    }
}
