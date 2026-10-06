<?php
namespace AdvancedMedia\Service\Site\ResourcePageBlockLayout;

use AdvancedMedia\Media\DownloadResolver;
use AdvancedMedia\Site\ResourcePageBlockLayout\Downloads;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class DownloadsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Downloads($services->get(DownloadResolver::class));
    }
}
