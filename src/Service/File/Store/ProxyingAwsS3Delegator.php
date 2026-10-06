<?php
namespace AdvancedMedia\Service\File\Store;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;

/**
 * Replaces AmazonS3's store with our proxying subclass — the ORDER-INDEPENDENT
 * way, superseding the runtime setFactory this used to do in Module::onBootstrap.
 *
 * WHY A DELEGATOR
 * ---------------
 * There is no module load-order weighting in Omeka: Module\Manager::sortModules()
 * is a plain ksort, so module config merges ALPHABETICALLY. That rules out two of
 * the three ways to override a service:
 *
 *   - config `factories`: 'AdvancedMedia' sorts before 'AmazonS3', so their entry
 *     merges last and wins. A config factory override silently does nothing.
 *   - runtime `setFactory` in onBootstrap: works ONLY because our onBootstrap
 *     happens to run before AmazonS3's (alphabetical) and therefore before
 *     AmazonS3 calls get(AwsS3::class) and caches the instance. That is luck, not
 *     a contract — reorder the modules and it breaks.
 *
 * A `delegators` entry is applied at CREATION time by whoever registered the
 * factory, regardless of merge order, and delegator lists are appended rather
 * than overwritten. AmazonS3 declares no delegators for this key, so the merge is
 * clean and nothing depends on who sorted first.
 *
 * WHY THE CONCRETE CLASS, NOT THE ALIAS
 * -------------------------------------
 * Registered against AmazonS3\File\Store\AwsS3::class in module.config.php.
 * ImageServer's TileInfoFactory resolves that concrete class DIRECTLY, bypassing
 * the 'Omeka\File\Store' alias; targeting the concrete class catches it too.
 * Resolving via the alias still triggers this delegator, because an alias resolves
 * to its canonical name before delegators are applied.
 *
 * WHY WE IGNORE $callback
 * -----------------------
 * $callback would build the stock AwsS3. We do not wrap it — ProxyingAwsS3 EXTENDS
 * AwsS3 and constructs itself from settings, so there is no instance to decorate.
 * We build ours from scratch instead. This is safe as the sole delegator on this
 * key; if another module ever chained a delegator here, this one would need to
 * consider $callback's result. It does not today.
 */
class ProxyingAwsS3Delegator implements DelegatorFactoryInterface
{
    public function __invoke(ContainerInterface $container, $name, callable $callback, ?array $options = null)
    {
        // Delegate to the factory that already knows how to assemble the proxying
        // store from settings, rather than duplicating that logic here.
        return (new ProxyingAwsS3Factory())($container, $name, $options);
    }
}
