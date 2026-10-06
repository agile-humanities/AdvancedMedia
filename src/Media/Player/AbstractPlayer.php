<?php
namespace AdvancedMedia\Media\Player;

use Omeka\Api\Representation\MediaRepresentation;

abstract class AbstractPlayer implements PlayerInterface
{
    /** @var string */
    protected $name = '';

    /** @var string */
    protected $label = '';

    /** @var string[] */
    protected $mediaTypeCapabilities = ['*'];

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getMediaTypeCapabilities(): array
    {
        return $this->mediaTypeCapabilities;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function supports(MediaRepresentation $media, array $context = []): bool
    {
        return $this->isAvailable() && $this->matchesMediaType($media->mediaType());
    }

    /**
     * Match a media type against this player's capability patterns.
     * Supports exact ('application/pdf'), top-level wildcard ('audio/*'),
     * and any ('*').
     */
    protected function matchesMediaType(?string $mediaType): bool
    {
        foreach ($this->mediaTypeCapabilities as $capability) {
            if ($capability === '*') {
                return true;
            }
            if ($mediaType === null) {
                continue;
            }
            if ($capability === $mediaType) {
                return true;
            }
            if (substr($capability, -2) === '/*'
                && strpos($mediaType, substr($capability, 0, -1)) === 0
            ) {
                return true;
            }
        }
        return false;
    }
}
