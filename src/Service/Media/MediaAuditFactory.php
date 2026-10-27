<?php
namespace AdvancedMedia\Service\Media;

use AdvancedMedia\Media\MediaAudit;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class MediaAuditFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new MediaAudit($services->get('Omeka\Connection'));
    }
}
