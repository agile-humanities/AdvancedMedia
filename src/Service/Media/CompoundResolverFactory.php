<?php
namespace AdvancedMedia\Service\Media;

use AdvancedMedia\Media\Compound\Manager as CompoundManager;
use AdvancedMedia\Media\CompoundResolver;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class CompoundResolverFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');
        return new CompoundResolver(
            $services->get(CompoundManager::class),
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Settings\Site'),
            $config['advancedmedia']['default_structure_compounds'] ?? []
        );
    }
}
