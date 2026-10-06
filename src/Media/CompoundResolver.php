<?php
namespace AdvancedMedia\Media;

use AdvancedMedia\Media\Compound\CompoundInterface;
use AdvancedMedia\Media\Compound\Manager as CompoundManager;
use AdvancedMedia\Media\Structure\StructureInterface;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Settings\Settings;
use Omeka\Settings\SiteSettings;

/**
 * Picks the compound that renders a given STRUCTURE's group, at display time.
 *
 * The bands, most specific first (architecture §6.1, config-ui-spec §5):
 *
 *   A. TEMPLATE / CLASS override   'advancedmedia_compounds'            selector → compound
 *   B. STRUCTURE default           'advancedmedia_structure_compounds'  structure → compound
 *   C. SHIPPED default             module config
 *   —. else null                → the Identity floor (individual media)
 *
 * and within each band: SITE beats GLOBAL, always (settled: "site should always
 * beat global").
 *
 * BAND A APPLIES ONLY WHERE IT CAN. A template override nominates a compound for
 * the whole item, but a compound can only render structures it supports. So the
 * override is honoured for a given structure only if the nominated compound
 * declares support for it; otherwise that structure falls through to band B. This
 * is what makes an over-general mapping self-correcting rather than broken:
 *
 *   - template → Identity  suppresses EVERY structure for those items (Identity
 *     supports '*'), which is exactly what "None — Individual media" should mean.
 *   - template → Audio with Transcript on an item that has no audio simply never
 *     matches the audio structure, so it changes nothing.
 *
 * SELECTORS (config-ui-spec §3.3): the band-A row key is a qualified selector
 * string, not a bare id:
 *
 *     template:11                resource template   ← primary; 99.9% populated here
 *     class:12                   resource class      ← supported, NOT surfaced in the UI (§3.4)
 *     type:Interactive Resource  dcterms:type        ← grammar-supported, not enabled
 */
class CompoundResolver
{
    const DEFAULT_CONTEXT = 'page';
    const SELECTOR_KEY = 'advancedmedia_compounds';
    const STRUCTURE_KEY = 'advancedmedia_structure_compounds';

    /** @var CompoundManager */
    private $compounds;

    /** @var Settings */
    private $settings;

    /** @var SiteSettings */
    private $siteSettings;

    /** @var array Shipped structure → compound defaults, keyed by context. */
    private $defaultMap;

    public function __construct(
        CompoundManager $compounds,
        Settings $settings,
        SiteSettings $siteSettings,
        array $defaultMap
    ) {
        $this->compounds = $compounds;
        $this->settings = $settings;
        $this->siteSettings = $siteSettings;
        $this->defaultMap = $defaultMap;
    }

    /**
     * The compound to render this structure's group with, or null to leave the
     * media to the Identity floor.
     */
    public function resolve(
        ItemRepresentation $item,
        StructureInterface $structure,
        array $context = []
    ): ?CompoundInterface {
        $contextName = $context['context'] ?? self::DEFAULT_CONTEXT;
        $structureName = $structure->getName();

        foreach ($this->candidateNames($item, $structureName, $contextName, $context) as $name) {
            $compound = $this->usableCompound($name, $structureName);
            if ($compound) {
                return $compound;
            }
        }
        return null;
    }

    /**
     * Candidate compound names for this (item, structure), highest band first.
     *
     * @return string[]
     */
    private function candidateNames(
        ItemRepresentation $item,
        string $structureName,
        string $contextName,
        array $context
    ): array {
        $names = [];

        // Band A — template / class override (site, then global).
        $selectors = $this->selectorsFor($item);
        if ($selectors) {
            foreach ($this->maps(self::SELECTOR_KEY, $contextName, $context) as $map) {
                foreach ($selectors as $selector) {
                    $value = $map[$selector] ?? null;
                    if ($this->isSetRow($value)) {
                        $names[] = (string) $value;
                    }
                }
            }
        }

        // Band B — structure default (site, then global).
        foreach ($this->maps(self::STRUCTURE_KEY, $contextName, $context) as $map) {
            $value = $map[$structureName] ?? null;
            if ($this->isSetRow($value)) {
                $names[] = (string) $value;
            }
        }

        // Band C — shipped default.
        $shipped = $this->defaultMap[$contextName][$structureName] ?? null;
        if ($this->isSetRow($shipped)) {
            $names[] = (string) $shipped;
        }

        return array_values(array_unique($names));
    }

    /**
     * The item's selectors, most specific first.
     *
     * @return string[]
     */
    public function selectorsFor(ItemRepresentation $item): array
    {
        $selectors = [];
        $template = $item->resourceTemplate();
        if ($template) {
            $selectors[] = 'template:' . $template->id();
        }
        $class = $item->resourceClass();
        if ($class) {
            $selectors[] = 'class:' . $class->id();
        }
        return $selectors;
    }

    /**
     * A compound is usable for a structure only if it is registered, available in
     * this install, and declares support for that structure.
     *
     * An unknown or unavailable compound is IGNORED — never rewritten in storage,
     * because the backing module may be reinstalled (config-ui-spec §8).
     */
    private function usableCompound(string $name, string $structureName): ?CompoundInterface
    {
        try {
            $compound = $this->compounds->get($name);
        } catch (\Exception $e) {
            return null;
        }
        if (!$compound->isAvailable()) {
            return null;
        }
        $supported = $compound->getSupportedStructures();
        if (!in_array('*', $supported, true) && !in_array($structureName, $supported, true)) {
            return null;
        }
        return $compound;
    }

    /**
     * The settings tiers for a key, highest priority first: SITE, then GLOBAL.
     *
     * @return array[]
     */
    private function maps(string $settingKey, string $contextName, array $context): array
    {
        $maps = [];
        if (!empty($context['site'])) {
            try {
                $siteMap = $this->siteSettings->get($settingKey, []);
            } catch (\Exception $e) {
                $siteMap = []; // No site in scope.
            }
            if (is_array($siteMap)) {
                $maps[] = $siteMap[$contextName] ?? [];
            }
        }
        $globalMap = $this->settings->get($settingKey, []);
        $maps[] = is_array($globalMap) ? ($globalMap[$contextName] ?? []) : [];

        return array_filter($maps, 'is_array');
    }

    /**
     * Is this row an actual override?
     *
     * LAYER 2 IMMUNITY (config-ui-spec §6.3). An empty/null row is NOT an override
     * — it means "inherit", and inheritance is expressed as row ABSENCE. Treating
     * an empty row as absent *on read* means a stray empty value (a buggy save, a
     * hand-edited setting, another module writing our key) can never freeze a site
     * against global changes: resolution simply continues to the next tier. It
     * degrades a silent, permanent, invisible-from-the-UI inheritance break into
     * harmless debris. Do not "simplify" this away.
     */
    private function isSetRow($value): bool
    {
        return $value !== null && $value !== '';
    }
}
