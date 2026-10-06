<?php
namespace AdvancedMedia\View\Helper;

use AdvancedMedia\Media\PlayerResolver;
use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Helper\AbstractHelper;

/**
 * Render a single media through the resolved player.
 *
 * Usage in a template: `echo $this->advancedMediaPlayer($media);`
 * Also the seam theme templates use instead of hand-rolled media logic.
 */
class AdvancedMediaPlayer extends AbstractHelper
{
    /** @var PlayerResolver */
    protected $resolver;

    public function __construct(PlayerResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function __invoke(MediaRepresentation $media, array $options = []): string
    {
        $view = $this->getView();

        $context = ['context' => $options['context'] ?? PlayerResolver::DEFAULT_CONTEXT];

        $helpers = $view->getHelperPluginManager();
        if ($helpers->has('currentSite')) {
            $site = $view->currentSite();
            if ($site) {
                $context['site'] = $site->id();
            }
        }

        return $this->resolver->resolve($media, $context)->render($view, $media, $options);
    }
}
