<?php
namespace AdvancedMedia\Media;

use AdvancedMedia\Config\ConfigOptions;
use AdvancedMedia\Config\ScopedFlag;
use AdvancedMedia\Media\Compound\Manager as CompoundManager;
use AdvancedMedia\Media\Structure\Manager as StructureManager;
use Omeka\Api\Representation\ItemRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Composes an item's media into rendered output.
 *
 *   1. STRUCTURES detect, highest priority first, greedy-claiming from the pool of
 *      not-yet-claimed media. Priority is the specificity of the PATTERN.
 *   2. For each detected group, the CompoundResolver picks the compound that
 *      renders it (template override → structure default → shipped default).
 *   3. Whatever no structure claimed falls to the IDENTITY floor: each media
 *      rendered on its own through its resolved player.
 *   4. Groups render in the order of their first-claimed media, so an item can
 *      legitimately host several compounds at once. Optionally (PRIMARY_FIRST_KEY)
 *      the group holding the primary media is hoisted to the front.
 *
 * Greedy claiming means a config row says "wherever this pattern occurs", NOT "for
 * these items" — an item with images AND an audio+VTT pair matches two structures
 * and renders both compounds (architecture §6.0.2).
 */
class Composer
{
    const IDENTITY = 'identity';

    /** @var StructureManager */
    private $structures;

    /** @var CompoundManager */
    private $compounds;

    /** @var CompoundResolver */
    private $resolver;

    /** @var ScopedFlag */
    private $flags;

    public function __construct(
        StructureManager $structures,
        CompoundManager $compounds,
        CompoundResolver $resolver,
        ScopedFlag $flags
    ) {
        $this->structures = $structures;
        $this->compounds = $compounds;
        $this->resolver = $resolver;
        $this->flags = $flags;
    }

    public function compose(PhpRenderer $view, ItemRepresentation $item, array $context = []): string
    {
        $remaining = [];
        $position = [];
        $index = 0;
        foreach ($item->media() as $media) {
            $remaining[$media->id()] = $media;
            $position[$media->id()] = $index++;
        }

        $groups = [];
        $wholeItemViewerUsed = false;
        foreach ($this->structures->getOrdered() as $structure) {
            if (!$remaining) {
                break;
            }

            $detected = $structure->detect($item, array_values($remaining));
            if (!is_array($detected)) {
                continue;
            }

            foreach ($detected as $groupMedia) {
                if (!is_array($groupMedia) || !$groupMedia) {
                    continue;
                }
                // Every detected media must still be in the pool (guards against a
                // structure claiming something a higher-priority one already took).
                $minPos = PHP_INT_MAX;
                $valid = true;
                foreach ($groupMedia as $media) {
                    if (!isset($remaining[$media->id()])) {
                        $valid = false;
                        break;
                    }
                    $minPos = min($minPos, $position[$media->id()]);
                }
                if (!$valid) {
                    continue;
                }

                // Which compound renders this group? Null means no usable compound
                // (none configured, or the backing module is gone) — the media are
                // still CLAIMED and rendered individually, rather than being left
                // for a lower-priority structure to reinterpret. That keeps "None"
                // meaning what an admin expects: this pattern is not grouped here.
                $compound = $this->resolver->resolve($item, $structure, $context)
                    ?: $this->identity();

                // AT MOST ONE WHOLE-ITEM VIEWER PER PAGE. UV and Mirador both draw the
                // item's entire manifest regardless of the group handed to them, so a
                // second one would render the item's contents twice. Structures are
                // iterated highest-priority first, so the more specific pattern keeps
                // its viewer and the later one degrades to individual rendering rather
                // than vanishing.
                if ($compound->isWholeItemViewer()) {
                    if ($wholeItemViewerUsed) {
                        $compound = $this->identity();
                    } else {
                        $wholeItemViewerUsed = true;
                    }
                }

                foreach ($groupMedia as $media) {
                    unset($remaining[$media->id()]);
                }
                // 'claimed' travels to the compound: a structure recognised these media
                // as a pattern, which is precisely what the display policy means by
                // "grouped" (see the identity compound).
                $groups[] = [
                    'compound' => $compound,
                    'media' => $groupMedia,
                    'pos' => $minPos,
                    'claimed' => true,
                ];
            }
        }

        // The floor: anything no structure claimed, rendered one media at a time.
        $identity = $this->identity();
        foreach ($remaining as $media) {
            $groups[] = [
                'compound' => $identity,
                'media' => [$media],
                'pos' => $position[$media->id()],
                // The floor: nothing recognised these, so the display policy applies.
                'claimed' => false,
            ];
        }

        usort($groups, function ($a, $b) {
            return $a['pos'] <=> $b['pos'];
        });

        $groups = $this->applyPrimaryFirst($groups, $item, $context);

        $output = '';
        foreach ($groups as $group) {
            $output .= $group['compound']->render(
                $view,
                $group['media'],
                ['claimed' => $group['claimed']] + $context
            );
        }
        return $output;
    }

    /**
     * Optionally hoist the group holding the item's primary media to the front.
     *
     * OFF BY DEFAULT, because attachment order is a curatorial decision, not an
     * accident: Omeka gives editors a drag-and-drop ordering UI on the item, and an
     * editor who deliberately placed a contextual image ahead of the primary video
     * meant it. This reverses that for sites that would rather lead with the primary
     * media, and it is opt-in so that reversal is somebody's explicit choice.
     *
     * MOVES THE GROUP, NOT THE MEDIA. The primary media may be inside a compound —
     * one page of a paged document, one image of a Mirador set — and lifting it out
     * would break the very grouping the structures just established. So the whole
     * group travels, and the order WITHIN a group stays the compound's business.
     *
     * Everything else keeps attachment order: this is a single stable rotation of
     * one element to the front, not a re-sort.
     */
    private function applyPrimaryFirst(array $groups, ItemRepresentation $item, array $context): array
    {
        if (count($groups) < 2) {
            return $groups;
        }
        if (!$this->flags->isOn(
            ConfigOptions::PRIMARY_FIRST_KEY,
            ConfigOptions::PRIMARY_FIRST_DEFAULT,
            $context
        )) {
            return $groups;
        }

        // Omeka's own designation. Null on an item with no media, and note that
        // primaryMedia() already falls back to the first attached media — in which
        // case the group holding it is first anyway and this is a no-op.
        $primary = $item->primaryMedia();
        if (!$primary) {
            return $groups;
        }
        $primaryId = $primary->id();

        foreach ($groups as $i => $group) {
            foreach ($group['media'] as $media) {
                if ($media->id() !== $primaryId) {
                    continue;
                }
                if ($i > 0) {
                    array_splice($groups, $i, 1);
                    array_unshift($groups, $group);
                }
                return $groups;
            }
        }

        // The primary media was claimed by nothing that renders (it can only be
        // absent from every group if it is absent from the item's media list), so
        // there is nothing to hoist.
        return $groups;
    }

    private function identity(): \AdvancedMedia\Media\Compound\CompoundInterface
    {
        return $this->compounds->get(self::IDENTITY);
    }
}
