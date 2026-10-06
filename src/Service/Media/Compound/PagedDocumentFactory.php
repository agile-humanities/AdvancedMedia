<?php
namespace AdvancedMedia\Service\Media\Compound;

use AdvancedMedia\Media\Compound\PagedDocument;
use AdvancedMedia\Media\PlayerResolver;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class PagedDocumentFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        // Availability is a capability concern (architecture §1.4): Paged Document
        // needs UniversalViewer to render and IiifServer to build the manifest.
        // Both surface as view helpers; if either is missing, the compound reports
        // unavailable and its PDFs fall back to individual rendering.
        $viewHelpers = $services->get('ViewHelperManager');
        $available = $viewHelpers->has('universalViewer') && $viewHelpers->has('iiifUrl');

        return new PagedDocument($available, $services->get(PlayerResolver::class));
    }
}
