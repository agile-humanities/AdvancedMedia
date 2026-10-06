<?php
namespace AdvancedMedia\Service\Config;

use AdvancedMedia\Config\ConfigWriter;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ConfigWriterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ConfigWriter($services->get('Omeka\Settings'));
    }
}
