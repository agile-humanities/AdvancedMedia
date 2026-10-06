<?php
namespace AdvancedMedia\Media\Compound;

use Laminas\View\Renderer\PhpRenderer;

/**
 * A COMPOUND renders a group of media as one unit. It does NOT detect.
 *
 * Detection and partitioning belong to Structures (architecture §6.0). A compound
 * declares which structures it can render and is handed finished groups.
 *
 * THE CAPABILITY CONTRACT — exactly parallel to a player supporting a MIME type:
 *
 *     Supporting a structure means being able to render ANY group that structure
 *     produces.
 *
 * That contract is what makes compounds interchangeable: because several compounds
 * may declare the same structure, an admin can choose between them (Image Gallery
 * vs. a future Slideshow), and the composer can fall back from one to another when
 * a backing module is missing.
 */
interface CompoundInterface
{
    public function getName(): string;

    /** Human label, shown in the config UI dropdowns. */
    public function getLabel(): string;

    /**
     * The structures this compound can render.
     *
     * Return ['*'] for universal support (the Identity compound does — it can
     * render any group, one media at a time, which is what makes "None —
     * Individual media" a valid choice for every structure row).
     *
     * @return string[]
     */
    public function getSupportedStructures(): array;

    /** Whether this compound is usable in this install (e.g. backing module present). */
    public function isAvailable(): bool;

    /**
     * Does this compound render the WHOLE ITEM rather than just the group it is handed?
     *
     * True for the IIIF viewers: Universal Viewer explicitly resolves any media back to
     * its parent item's manifest, and Mirador renders the item manifest. Neither can be
     * scoped to a subset of an item's media — hand one a group of two images and it
     * still draws every canvas the item has.
     *
     * The Composer uses this to enforce a single invariant: AT MOST ONE WHOLE-ITEM
     * VIEWER PER PAGE. Two of them would render the item's entire contents twice.
     *
     * This lives on the COMPOUND because that is where the property actually is. It
     * was previously approximated inside PagedDocument's detector as "refuse PDFs on
     * items that also have images" — a rule stated at the wrong altitude: it described
     * one symptom (PDF + gallery) of a general constraint, and it stayed in force even
     * when the structure was mapped to a per-media renderer that has no such limit.
     */
    public function isWholeItemViewer(): bool;

    /**
     * Render one group, as produced by a structure.
     *
     * @param \Omeka\Api\Representation\MediaRepresentation[] $mediaGroup
     */
    public function render(PhpRenderer $view, array $mediaGroup, array $context): string;
}
