<?php
namespace AdvancedMedia\Service\Media\Compound;

use AdvancedMedia\Config\ScopedFlag;
use AdvancedMedia\Media\Compound\Identity;
use AdvancedMedia\Media\PlayerResolver;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IdentityFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Identity(
            $services->get(PlayerResolver::class),
            $services->get(ScopedFlag::class)
        );
    }
}
