<?php
namespace AdvancedMedia\Service\ViewHelper;

use AdvancedMedia\Media\PlayerResolver;
use AdvancedMedia\View\Helper\AdvancedMediaPlayer;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AdvancedMediaPlayerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new AdvancedMediaPlayer($services->get(PlayerResolver::class));
    }
}
