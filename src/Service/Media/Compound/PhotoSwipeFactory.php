<?php
namespace AdvancedMedia\Service\Media\Compound;

use AdvancedMedia\Media\Compound\PhotoSwipe;
use AdvancedMedia\Media\Player\Manager as PlayerManager;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class PhotoSwipeFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        // No availability gate: the viewer is vendored with this module, so unlike
        // the Mirador and Universal Viewer adapters it cannot be missing.
        return new PhotoSwipe($services->get(PlayerManager::class));
    }
}
