<?php
namespace AdvancedMedia\Media\Structure;

use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

abstract class AbstractStructure implements StructureInterface
{
    /** @var string */
    protected $name = '';

    /** @var string */
    protected $label = '';

    /** @var string */
    protected $description = '';

    /** @var string[] */
    protected $requirements = [];

    /** @var int */
    protected $priority = 0;

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getRequirements(): array
    {
        return $this->requirements;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * The item's primary media: the designated one if a curator set it, else the
     * first media by attachment order.
     *
     * CORE ALREADY DOES THE FALLBACK. `ItemRepresentation::primaryMedia()` returns
     * the designated primary and, failing that, "the first media if one exists"
     * (Omeka\Api\Representation\ItemRepresentation::primaryMedia). So this is a
     * named passthrough, kept because the CONCEPT is load-bearing for structures
     * and worth stating once, here, rather than re-explaining at every call site.
     *
     * Why the concept matters (architecture §6.0.1): the designation is a real
     * editorial act and is used deliberately here — every Interactive Resource item
     * has its HTML embed designated primary, most of them at position 2 or 3,
     * explicitly overriding attachment order to say "the embed is the thing." Only
     * 809 of 5,659 media-bearing items set the column, but because core falls back
     * to the first media, a structure consulting primary media always gets an
     * answer and degrades to attachment order — which is what every detector
     * already implicitly assumed.
     */
    protected function primaryOrFirst(ItemRepresentation $item): ?MediaRepresentation
    {
        return $item->primaryMedia();
    }

    protected function isImage(MediaRepresentation $media): bool
    {
        if (strpos((string) $media->mediaType(), 'image/') === 0) {
            return true;
        }
        // IIIF-ingested media are image-like even when their stored mediaType is empty.
        return $media->ingester() === 'iiif';
    }

    protected function isHtml(MediaRepresentation $media): bool
    {
        return $media->ingester() === 'html' || $media->renderer() === 'html';
    }

    protected function isAudio(MediaRepresentation $media): bool
    {
        return strpos((string) $media->mediaType(), 'audio/') === 0;
    }

    /**
     * VTTs are commonly stored as text/plain with a .vtt extension (Omeka
     * mis-sniffs them), so the extension fallback is NOT optional.
     */
    protected function isVtt(MediaRepresentation $media): bool
    {
        if (strpos(strtolower((string) $media->mediaType()), 'vtt') !== false) {
            return true;
        }
        return strtolower((string) $media->extension()) === 'vtt';
    }

    /** Is this media still in the unclaimed pool? */
    protected function inPool(?MediaRepresentation $media, array $pool): bool
    {
        if (!$media) {
            return false;
        }
        foreach ($pool as $candidate) {
            if ($candidate->id() === $media->id()) {
                return true;
            }
        }
        return false;
    }
}
