<?php
namespace AdvancedMedia\Service\Controller;

use AdvancedMedia\Config\ConfigOptions;
use AdvancedMedia\Config\ConfigWriter;
use AdvancedMedia\Controller\SiteAdmin\IndexController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SiteAdminIndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new IndexController(
            $services->get(ConfigOptions::class),
            $services->get(ConfigWriter::class),
            $services->get('Omeka\Settings\Site')
        );
    }
}
