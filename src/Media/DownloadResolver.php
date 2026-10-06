<?php
namespace AdvancedMedia\Media;

use AdvancedMedia\Config\ConfigOptions;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Settings\Settings;
use Omeka\Settings\SiteSettings;

/**
 * Decides which of an item's media the Downloads block offers.
 *
 * Deliberately SEPARATE from PlayerResolver. Downloadability is not a player
 * choice: the point of the Downloads block is that an item can show a proper
 * viewer AND offer the same file as a download. Expressing that as a player value
 * is impossible — a media resolves to exactly one player.
 *
 * The cascade is the same one PlayerResolver walks, so the two columns in the
 * config UI behave identically:
 *
 *     per-site mapping  →  global mapping  →  shipped default ('0' — opt in)
 *
 * and within each tier, exact MIME → top-level wildcard → 'default'.
 */
class DownloadResolver
{
    const DEFAULT_CONTEXT = 'page';

    /** @var Settings */
    private $settings;

    /** @var SiteSettings */
    private $siteSettings;

    /** @var array Shipped default map, keyed by context. */
    private $defaultMap;

    public function __construct(Settings $settings, SiteSettings $siteSettings, array $defaultMap)
    {
        $this->settings = $settings;
        $this->siteSettings = $siteSettings;
        $this->defaultMap = $defaultMap;
    }

    /**
     * The media this item should offer for download, in attachment order.
     *
     * Attachment order rather than grouping by type: it matches the order the item
     * itself presents, and it keeps the threshold's effect legible.
     */
    public function downloadableMedia(ItemRepresentation $item, array $context = []): array
    {
        $mediaList = $item->media();
        $threshold = $this->threshold($context);

        // Count EVERY media of each type, not just the downloadable ones — the
        // threshold is about how many files of a kind the item holds, which is what
        // makes "600 page images" distinguishable from "2 PDFs".
        $countsByType = [];
        foreach ($mediaList as $media) {
            $type = (string) $media->mediaType();
            $countsByType[$type] = ($countsByType[$type] ?? 0) + 1;
        }

        $out = [];
        foreach ($mediaList as $media) {
            if (!$this->hasSomethingToDownload($media)) {
                continue;
            }
            if (!$this->isDownloadable($media, $context)) {
                continue;
            }
            $type = (string) $media->mediaType();
            if ($threshold > 0 && ($countsByType[$type] ?? 0) > $threshold) {
                // Suppressed silently and per TYPE, so an item with 600 page images
                // and one PDF still offers the PDF.
                continue;
            }
            $out[] = $media;
        }
        return $out;
    }

    /**
     * Is this media type configured as downloadable?
     *
     * @param array $context Recognized keys: 'context' (string), 'site' (int site id).
     */
    public function isDownloadable(MediaRepresentation $media, array $context = []): bool
    {
        $contextName = $context['context'] ?? self::DEFAULT_CONTEXT;
        $keys = $this->lookupKeys($media->mediaType());

        foreach ($this->maps($contextName, $context) as $map) {
            foreach ($keys as $key) {
                $value = $map[$key] ?? null;
                // Same layer-2 immunity as PlayerResolver: '' means "inherit", not
                // "no". Treating it as a value here would let a stray empty row
                // freeze a site against later global changes.
                if ($value === null || $value === '') {
                    continue;
                }
                return (string) $value === '1';
            }
        }
        return false;
    }

    /**
     * Suppress a media type when the item holds more than this many of it.
     * 0 means no limit.
     */
    public function threshold(array $context = []): int
    {
        if (!empty($context['site'])) {
            try {
                $site = $this->siteSettings->get(ConfigOptions::THRESHOLD_KEY, '');
            } catch (\Exception $e) {
                $site = '';
            }
            if ($site !== '' && $site !== null) {
                return max(0, (int) $site);
            }
        }

        $global = $this->settings->get(ConfigOptions::THRESHOLD_KEY, '');
        if ($global !== '' && $global !== null) {
            return max(0, (int) $global);
        }

        return ConfigOptions::THRESHOLD_DEFAULT;
    }

    /**
     * A media with no original, or a zero-byte one, has nothing to offer.
     *
     * Both occur in real data: this install carries 24 zero-byte records typed
     * `application/x-empty` / `inode/x-empty` from failed uploads. Offering a
     * download button that yields an empty file is worse than offering nothing.
     */
    private function hasSomethingToDownload(MediaRepresentation $media): bool
    {
        return $media->hasOriginal() && (int) $media->size() > 0;
    }

    /** @return array[] Ordered tiers, highest priority first. */
    private function maps(string $contextName, array $context): array
    {
        $maps = [];
        if (!empty($context['site'])) {
            try {
                $siteMap = $this->siteSettings->get(ConfigOptions::DOWNLOAD_KEY, []);
            } catch (\Exception $e) {
                $siteMap = [];
            }
            $maps[] = is_array($siteMap) ? ($siteMap[$contextName] ?? []) : [];
        }

        $globalMap = $this->settings->get(ConfigOptions::DOWNLOAD_KEY, []);
        $maps[] = is_array($globalMap) ? ($globalMap[$contextName] ?? []) : [];

        $maps[] = $this->defaultMap[$contextName] ?? [];

        return array_filter($maps, 'is_array');
    }

    /** Mirrors PlayerResolver::lookupKeys(). */
    private function lookupKeys(?string $mediaType): array
    {
        if (!$mediaType) {
            return ['default'];
        }
        $keys = [$mediaType];
        $slash = strpos($mediaType, '/');
        if ($slash !== false) {
            $keys[] = substr($mediaType, 0, $slash) . '/*';
        }
        $keys[] = 'default';
        return $keys;
    }
}
