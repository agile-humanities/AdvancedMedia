<?php
namespace AdvancedMedia\Service\File;

use AdvancedMedia\File\ReachabilityProbe;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ReachabilityProbeFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        // The connection lets the probe pick real media files to test with.
        return new ReachabilityProbe($services->get('Omeka\Connection'));
    }
}
