<?php
namespace AdvancedMedia\Media\Compound;

abstract class AbstractCompound implements CompoundInterface
{
    /** @var string */
    protected $name = '';

    /** @var string */
    protected $label = '';

    /** @var string[] */
    protected $supportedStructures = [];

    /**
     * @var bool Whether this compound draws the whole item (see CompoundInterface).
     *           Defaults to false: a compound renders the group it is handed unless
     *           it says otherwise, so a new per-media compound is safe by default.
     */
    protected $wholeItemViewer = false;

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getSupportedStructures(): array
    {
        return $this->supportedStructures;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function isWholeItemViewer(): bool
    {
        return $this->wholeItemViewer;
    }

    /** Does this compound support the named structure? '*' means all of them. */
    public function supportsStructure(string $structureName): bool
    {
        $supported = $this->getSupportedStructures();
        return in_array('*', $supported, true) || in_array($structureName, $supported, true);
    }
}
