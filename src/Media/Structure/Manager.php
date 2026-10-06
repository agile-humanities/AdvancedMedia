<?php
namespace AdvancedMedia\Media\Structure;

use Omeka\ServiceManager\AbstractPluginManager;

/**
 * The structure registry. A standard Omeka plugin manager keyed by structure
 * name; modules contribute structures via the `advancedmedia_structures` config
 * key.
 */
class Manager extends AbstractPluginManager
{
    protected $instanceOf = StructureInterface::class;

    /**
     * All registered structures, highest priority first.
     *
     * @return StructureInterface[]
     */
    public function getOrdered(): array
    {
        $structures = [];
        foreach ($this->getRegisteredNames() as $name) {
            $structures[] = $this->get($name);
        }
        usort($structures, function ($a, $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
        return $structures;
    }
}
