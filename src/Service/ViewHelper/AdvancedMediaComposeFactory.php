<?php
namespace AdvancedMedia\Service\ViewHelper;

use AdvancedMedia\Media\Composer;
use AdvancedMedia\View\Helper\AdvancedMediaCompose;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AdvancedMediaComposeFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new AdvancedMediaCompose($services->get(Composer::class));
    }
}
