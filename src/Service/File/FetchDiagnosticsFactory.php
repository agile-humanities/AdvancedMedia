<?php
namespace AdvancedMedia\Service\File;

use AdvancedMedia\File\FetchDiagnostics;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class FetchDiagnosticsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new FetchDiagnostics(
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Logger')
        );
    }
}
