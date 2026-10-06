<?php
namespace AdvancedMedia\Media\Compound;

use Omeka\ServiceManager\AbstractPluginManager;

/**
 * The compound-type registry. A standard Omeka plugin manager keyed by compound
 * name; modules contribute compounds via the `advancedmedia_compound_types`
 * config key.
 */
class Manager extends AbstractPluginManager
{
    protected $instanceOf = CompoundInterface::class;
}
