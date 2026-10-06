<?php
namespace AdvancedMedia\Service\Media;

use AdvancedMedia\Config\ScopedFlag;
use AdvancedMedia\Media\Compound\Manager as CompoundManager;
use AdvancedMedia\Media\Composer;
use AdvancedMedia\Media\CompoundResolver;
use AdvancedMedia\Media\Structure\Manager as StructureManager;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ComposerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Composer(
            $services->get(StructureManager::class),
            $services->get(CompoundManager::class),
            $services->get(CompoundResolver::class),
            $services->get(ScopedFlag::class)
        );
    }
}
