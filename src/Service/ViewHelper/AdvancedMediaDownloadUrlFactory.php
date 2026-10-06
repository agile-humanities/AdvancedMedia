<?php
namespace AdvancedMedia\Service\ViewHelper;

use AdvancedMedia\View\Helper\AdvancedMediaDownloadUrl;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AdvancedMediaDownloadUrlFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new AdvancedMediaDownloadUrl($services->get('Omeka\File\Store'));
    }
}
