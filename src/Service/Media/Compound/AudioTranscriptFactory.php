<?php
namespace AdvancedMedia\Service\Media\Compound;

use AdvancedMedia\Media\Compound\AudioTranscript;
use AdvancedMedia\Media\Player\Manager as PlayerManager;
use AdvancedMedia\Media\PlayerResolver;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AudioTranscriptFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new AudioTranscript(
            $services->get(PlayerManager::class),
            $services->get(PlayerResolver::class)
        );
    }
}
