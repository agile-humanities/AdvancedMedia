<?php
namespace AdvancedMedia\Service\Media\Compound;

use AdvancedMedia\Media\Compound\InteractiveResource;
use AdvancedMedia\Media\Player\Manager as PlayerManager;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class InteractiveResourceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        // No availability gate: the embed is rendered by Omeka core's own HTML
        // media renderer, which is always present.
        return new InteractiveResource($services->get(PlayerManager::class));
    }
}
