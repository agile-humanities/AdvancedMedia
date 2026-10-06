<?php
namespace AdvancedMedia\Media\Structure;

use Omeka\Api\Representation\ItemRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * A STRUCTURE is a named pattern in an item's media: it owns DETECTION and
 * PARTITIONING, and nothing else. It does not render.
 *
 * Structures are peers of compounds, not properties of them (architecture §6.0):
 *
 *   - A structure is pure data-side. It never depends on an installed viewer
 *     module, so a configuration row keyed on it is STABLE — disabling Mirador
 *     removes an *option* from a row, never the row itself.
 *   - A compound declares which structures it can render
 *     (CompoundInterface::getSupportedStructures()). Several compounds may
 *     support one structure, which is what lets an admin choose between them
 *     (e.g. Image Gallery vs. a future Slideshow).
 *
 * The capability contract is exactly parallel to a player supporting a MIME
 * type: **supporting a structure means being able to render ANY group that
 * structure produces.**
 *
 * Structures compete for media greedily, highest priority first; each sees only
 * the media no higher-priority structure claimed. Priority is therefore a
 * property of the PATTERN's specificity (audio+VTT is more specific than "has
 * audio"), not of any renderer.
 */
interface StructureInterface
{
    /** Machine name. Third-party structures should namespace: 'vendor:thing'. */
    public function getName(): string;

    /** Human label — this is the row label in the config UI. */
    public function getLabel(): string;

    /** One-line predicate, shown under the row label. */
    public function getDescription(): string;

    /**
     * The conditions this structure looks for, in curator-actionable terms.
     *
     * Rendered as help text on the config form (config-ui-spec §5, Band B). This
     * is deliberately part of the INTERFACE rather than UI copy: the help text is
     * the structure's own declaration of what it detects, so it cannot drift away
     * from what the detector actually does.
     *
     * It serves two audiences: a curator troubleshooting why an item did not
     * render as expected, and a curator who wants to CONFORM their data to the
     * expectation (e.g. "designate the HTML as the item's primary media").
     *
     * @return string[]
     */
    public function getRequirements(): array;

    /** Higher detects (claims) first. Specificity of the pattern. */
    public function getPriority(): int;

    /**
     * Detect every occurrence of this pattern among the media not yet claimed.
     *
     * Each returned group is handed, whole, to one compound. Return only groups
     * that are complete and valid — an incomplete group must not be claimed, so
     * that its media stay available to lower-priority structures and, ultimately,
     * to individual rendering.
     *
     * @param \Omeka\Api\Representation\MediaRepresentation[] $remainingMedia
     * @return \Omeka\Api\Representation\MediaRepresentation[][]
     */
    public function detect(ItemRepresentation $item, array $remainingMedia): array;
}
