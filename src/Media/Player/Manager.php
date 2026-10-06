<?php
namespace AdvancedMedia\Media\Player;

use Omeka\ServiceManager\AbstractPluginManager;

/**
 * The player registry.
 *
 * A standard Omeka plugin manager keyed by player name. Modules contribute
 * players by adding entries under the `advancedmedia_media_players` config key.
 */
class Manager extends AbstractPluginManager
{
    protected $instanceOf = PlayerInterface::class;

    /**
     * Names of all registered players that are currently available.
     *
     * @return string[]
     */
    public function getAvailableNames(): array
    {
        $names = [];
        foreach ($this->getRegisteredNames() as $name) {
            $player = $this->get($name);
            if ($player->isAvailable()) {
                $names[] = $name;
            }
        }
        return $names;
    }
}
