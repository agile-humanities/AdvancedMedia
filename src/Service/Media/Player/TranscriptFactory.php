<?php
namespace AdvancedMedia\Service\Media\Player;

use AdvancedMedia\File\FetchDiagnostics;
use AdvancedMedia\Media\Player\Transcript;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * The transcript player is the one player that performs a SERVER-SIDE fetch (it
 * reads the VTT to build the cue list), so it needs the store — to turn the
 * browser-facing URL into a fetchable one — and the diagnostics recorder.
 */
class TranscriptFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Transcript(
            $services->get('Omeka\File\Store'),
            $services->get(FetchDiagnostics::class)
        );
    }
}
