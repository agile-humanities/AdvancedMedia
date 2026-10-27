<?php
namespace AdvancedMedia\Site\ResourcePageBlockLayout;

use AdvancedMedia\Config\ConfigOptions;
use AdvancedMedia\Config\ScopedFlag;
use AdvancedMedia\Media\DownloadResolver;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Item-page block offering downloads for the media types an admin has opted in.
 *
 * WHY THIS IS A BLOCK AND NOT A PLAYER
 * ------------------------------------
 * The module already has a `download` player, but mapping a media type to it
 * REPLACES the viewer — the file becomes a button instead of a player. In practice
 * you usually want both: a working viewer AND a download link for the same file.
 * A media resolves to exactly one player, so that is not expressible there. A
 * separate block is.
 *
 * RELATIONSHIP TO COMPOUNDS
 * -------------------------
 * None, deliberately. This block does not go through the Composer; it walks the
 * item's media directly. So compound grouping (Audio with Transcript, Image
 * Gallery…) has no effect here, and the two blocks can sit in different regions
 * without interacting. The per-media-type opt-in is the only control — which is
 * also what keeps VTT sidecars out of the list, since `text/plain` stays off.
 *
 * CONFIGURATION LIVES ELSEWHERE
 * -----------------------------
 * ResourcePageBlockLayoutInterface has no hook for per-placement options — it is
 * getLabel/getCompatibleResourceNames/render and nothing else. Everything
 * configurable is therefore a global or per-site setting, on the Advanced Media
 * config pages, next to the player mapping it sits beside.
 */
class Downloads implements ResourcePageBlockLayoutInterface
{
    /** @var DownloadResolver */
    private $resolver;

    /** @var ScopedFlag */
    private $flags;

    public function __construct(DownloadResolver $resolver, ScopedFlag $flags)
    {
        $this->resolver = $resolver;
        $this->flags = $flags;
    }

    public function getLabel(): string
    {
        return 'Downloads'; // @translate
    }

    public function getCompatibleResourceNames(): array
    {
        return ['items'];
    }

    public function render(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): string
    {
        $site = $view->currentSite();

        $context = [
            'context' => DownloadResolver::DEFAULT_CONTEXT,
            'site' => $site ? $site->id() : null,
        ];

        $media = $this->resolver->downloadableMedia($resource, $context);

        // The metadata link is an element of the block in its own right, decided
        // here and NOT by whether any file happens to be downloadable. An item
        // with no opted-in files still has metadata.
        $includeMetadata = $this->flags->isOn(
            ConfigOptions::DOWNLOAD_METADATA_KEY,
            ConfigOptions::DOWNLOAD_METADATA_DEFAULT,
            $context
        );

        if (!$media && !$includeMetadata) {
            return '';
        }

        // Custom link text, where an admin has set any. Resolved here because it
        // is configuration; the template only composes the final string.
        $labelPatterns = [];
        foreach ($media as $m) {
            $pattern = $this->resolver->labelPattern($m, $context);
            if ($pattern !== null) {
                $labelPatterns[$m->id()] = $pattern;
            }
        }

        return $view->partial('common/resource-page-block-layout/downloads', [
            'item' => $resource,
            'media' => $media,
            'includeMetadata' => $includeMetadata,
            'labelPatterns' => $labelPatterns,
        ]);
    }
}
