<?php
namespace AdvancedMedia\Service\Media\Compound;

use AdvancedMedia\Media\Compound\Mirador;
use AdvancedMedia\Media\PlayerResolver;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class MiradorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        // Availability is a capability concern (architecture §1.4): Mirador needs its
        // own view helper to render and IiifServer's to build the manifest. If either
        // is missing, the compound reports unavailable and its images fall back to
        // individual rendering.
        $viewHelpers = $services->get('ViewHelperManager');
        $available = $viewHelpers->has('mirador') && $viewHelpers->has('iiifUrl');

        return new Mirador($available, $services->get(PlayerResolver::class));
    }
}
