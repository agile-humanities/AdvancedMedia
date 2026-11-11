<?php
namespace AdvancedMedia\Service\File;

use AdvancedMedia\File\IntegrationChecks;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IntegrationChecksFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $isActive = function (string $name) use ($services): bool {
            $module = $services->get('Omeka\ModuleManager')->getModule($name);
            return $module && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
        };
        // ImageServer stores its tile data in media data ("tile"), read by its plugin tileMediaInfo.
        $hasTiles = function (int $mediaId) use ($services): bool {
            $plugins = $services->get('ControllerPluginManager');
            if (!$plugins->has('tileMediaInfo')) {
                return false;
            }
            try {
                $media = $services->get('Omeka\ApiManager')->read('media', $mediaId)->getContent();
                return (bool) $plugins->get('tileMediaInfo')($media);
            } catch (\Throwable $e) {
                return false;
            }
        };
        return new IntegrationChecks(
            $services->get('Omeka\Connection'),
            $services->get('Omeka\Settings'),
            $services->get('Omeka\File\Store'),
            $isActive,
            $hasTiles
        );
    }
}
