<?php
namespace AdvancedMedia\Service\Config;

use AdvancedMedia\Config\ConfigOptions;
use AdvancedMedia\Media\Compound\Manager as CompoundManager;
use AdvancedMedia\Media\Player\Manager as PlayerManager;
use AdvancedMedia\Media\Structure\Manager as StructureManager;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ConfigOptionsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');
        return new ConfigOptions(
            $services->get(StructureManager::class),
            $services->get(CompoundManager::class),
            $services->get(PlayerManager::class),
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Settings\Site'),
            $services->get('Omeka\Connection'),
            $config['advancedmedia'] ?? [],
            $services->get('Omeka\File\Store')
        );
    }
}
