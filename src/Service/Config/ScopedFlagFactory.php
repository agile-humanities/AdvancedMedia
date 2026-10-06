<?php
namespace AdvancedMedia\Service\Config;

use AdvancedMedia\Config\ScopedFlag;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ScopedFlagFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ScopedFlag(
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Settings\Site')
        );
    }
}
