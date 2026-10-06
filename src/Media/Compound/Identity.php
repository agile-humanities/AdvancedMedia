<?php
namespace AdvancedMedia\Media\Compound;

use AdvancedMedia\Config\ConfigOptions;
use AdvancedMedia\Config\ScopedFlag;
use AdvancedMedia\Media\PlayerResolver;
use Laminas\View\Renderer\PhpRenderer;

/**
 * "None — Individual media": renders each media in the group on its own, through
 * its resolved player, in order.
 *
 * Universal structure support ('*'), which gives it two jobs:
 *
 *   1. THE FLOOR. Media that no structure claimed are rendered here, one at a
 *      time. This is the correctness floor — nothing can fail to render.
 *   2. THE OPT-OUT. Because it supports every structure, an admin can select it
 *      for any structure row, which turns that detector OFF for the site: the
 *      pattern is still detected, but its media are rendered individually rather
 *      than grouped. That choice was impossible before structures existed.
 */
class Identity extends AbstractCompound
{
    protected $name = 'identity';
    protected $label = 'None — Individual media'; // @translate
    protected $supportedStructures = ['*'];

    /** @var PlayerResolver */
    private $resolver;

    /** @var ScopedFlag */
    private $flags;

    public function __construct(PlayerResolver $resolver, ScopedFlag $flags)
    {
        $this->resolver = $resolver;
        $this->flags = $flags;
    }

    public function render(PhpRenderer $view, array $mediaGroup, array $context): string
    {
        // The gate applies only when this compound is acting as THE FLOOR.
        //
        // Identity has two jobs (see the class note), and the display policy only
        // meant one of them. "Only display primary and grouped media" is about loose
        // media that nothing recognised — but identity is ALSO what renders a
        // structure whose row an admin set to "None", and those media *were*
        // recognised: a structure claimed them as a pattern. Gating them too made the
        // policy's own name untrue and silently hid, for instance, every PDF on an
        // item whose paged-document row renders individually.
        //
        // The Composer marks structure-claimed groups; absence means the floor, which
        // keeps the gate on for any caller that does not set it.
        $primaryOnly = empty($context['claimed']) && $this->primaryOnly($context);
        $output = '';
        foreach ($mediaGroup as $media) {
            if ($primaryOnly && !$this->isPrimary($media)) {
                continue;
            }
            $output .= $this->resolver->resolve($media, $context)->render($view, $media, []);
        }
        return $output;
    }

    /**
     * Display policy, resolved per render: site override → global → coded default
     * (ScopedFlag). When true, only the item's primary media renders here; other
     * loose (non-compound) media are hidden. Compound media never reach this
     * compound, so they are unaffected.
     */
    private function primaryOnly(array $context): bool
    {
        return $this->flags->isOn(
            ConfigOptions::PRIMARY_COMPOUND_ONLY_KEY,
            ConfigOptions::PRIMARY_COMPOUND_ONLY_DEFAULT,
            $context
        );
    }

    /** Omeka's own "primary media" — the designated one, or the first if none. */
    private function isPrimary($media): bool
    {
        $item = $media->item();
        $primary = $item ? $item->primaryMedia() : null;
        return $primary && $primary->id() === $media->id();
    }
}
