<?php
namespace AdvancedMedia\Service\Media;

use AdvancedMedia\Media\DownloadResolver;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class DownloadResolverFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');

        return new DownloadResolver(
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Settings\Site'),
            $config['advancedmedia']['default_downloads'] ?? []
        );
    }
}
