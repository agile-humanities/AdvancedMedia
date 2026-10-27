<?php
namespace AdvancedMedia\Service\Media\Compound;

use AdvancedMedia\Media\Compound\OhmsInterview;
use AdvancedMedia\Media\Player\Manager as PlayerManager;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class OhmsInterviewFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new OhmsInterview($services->get(PlayerManager::class));
    }
}
