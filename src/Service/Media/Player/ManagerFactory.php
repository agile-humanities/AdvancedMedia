<?php
namespace AdvancedMedia\Service\Media\Player;

use AdvancedMedia\Media\Player\Manager;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');
        return new Manager($services, $config['advancedmedia_media_players'] ?? []);
    }
}
