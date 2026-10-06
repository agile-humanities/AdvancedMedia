<?php
namespace AdvancedMedia\Service\Controller;

use AdvancedMedia\Controller\FileController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class FileControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        // Resolved through the alias, so this picks up whatever store is actually
        // configured — ours when AmazonS3 is present and we have decorated it,
        // Omeka's Local store otherwise. The controller checks which it got.
        return new FileController(
            $services->get('Omeka\Connection'),
            $services->get('Omeka\File\Store'),
            $services->get('Omeka\Settings')
        );
    }
}
