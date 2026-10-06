<?php
namespace AdvancedMedia\Service\ViewHelper;

use AdvancedMedia\View\Helper\MiradorPageLinks;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;

/**
 * Wraps the Mirador module's `mirador` view helper so every viewer it renders gets
 * `?page=N` deep links and URL write-back (docs/mirador-page-links.md).
 *
 * WHY THE HELPER, NOT A CALLER
 * ----------------------------
 * The helper is invoked from at least six templates across four packages — our
 * Mirador compound, AgileThemeTools, IiifServer, and the pitts3 theme — plus the
 * Mirador module's own blocks. Hooking any one caller would leave the others
 * silently without page links.
 *
 * WHY A DELEGATOR
 * ---------------
 * Same reasoning as ProxyingAwsS3Delegator: it applies at creation time regardless
 * of alphabetical config-merge order, and it is inert when the Mirador module is
 * absent (the service is never created), so the availability gating of
 * architecture §1.4 comes for free.
 *
 * Unlike the S3 delegator, this one DOES decorate $callback's result: the stock
 * helper does all the rendering; we only add a script tag after it.
 */
class MiradorPageLinksDelegator implements DelegatorFactoryInterface
{
    public function __invoke(ContainerInterface $container, $name, callable $callback, ?array $options = null)
    {
        return new MiradorPageLinks($callback());
    }
}
