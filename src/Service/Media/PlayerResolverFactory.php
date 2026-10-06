<?php
namespace AdvancedMedia\Service\Media;

use AdvancedMedia\Media\Player\Manager as PlayerManager;
use AdvancedMedia\Media\PlayerResolver;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class PlayerResolverFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');
        return new PlayerResolver(
            $services->get(PlayerManager::class),
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Settings\Site'),
            $config['advancedmedia']['default_players'] ?? []
        );
    }
}
