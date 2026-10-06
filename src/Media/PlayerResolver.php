<?php
namespace AdvancedMedia\Media;

use AdvancedMedia\Media\Player\Manager as PlayerManager;
use AdvancedMedia\Media\Player\PlayerInterface;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Settings\Settings;
use Omeka\Settings\SiteSettings;

/**
 * Resolves a media to a player at display time, walking the cascade:
 *
 *     per-site mapping  →  global mapping  →  shipped default map  →  core-native
 *
 * and, within each tier, exact MIME → top-level wildcard → 'default'. Every
 * candidate is validated with the player's supports() before being accepted.
 *
 * The per-resource exception tier (docs/architecture.md §8) is added in a later
 * stage; it will sit above the per-site tier here.
 */
class PlayerResolver
{
    const DEFAULT_CONTEXT = 'page';
    const SETTING_KEY = 'advancedmedia_players';
    const FALLBACK_PLAYER = 'core-native';

    /** @var PlayerManager */
    protected $players;

    /** @var Settings */
    protected $settings;

    /** @var SiteSettings */
    protected $siteSettings;

    /** @var array Shipped default map, keyed by context. */
    protected $defaultMap;

    public function __construct(
        PlayerManager $players,
        Settings $settings,
        SiteSettings $siteSettings,
        array $defaultMap
    ) {
        $this->players = $players;
        $this->settings = $settings;
        $this->siteSettings = $siteSettings;
        $this->defaultMap = $defaultMap;
    }

    /**
     * @param array $context Recognized keys: 'context' (string), 'site' (int site id).
     */
    public function resolve(MediaRepresentation $media, array $context = []): PlayerInterface
    {
        $contextName = $context['context'] ?? self::DEFAULT_CONTEXT;
        foreach ($this->candidateNames($contextName, $media->mediaType(), $context) as $name) {
            $player = $this->getPlayer($name);
            if ($player && $player->supports($media, ['context' => $contextName])) {
                return $player;
            }
        }
        return $this->players->get(self::FALLBACK_PLAYER);
    }

    /**
     * Ordered, de-duplicated list of candidate player names for this media,
     * highest priority first.
     *
     * @return string[]
     */
    protected function candidateNames(string $context, ?string $mediaType, array $context2): array
    {
        $keys = $this->lookupKeys($mediaType);

        $maps = [];
        if (!empty($context2['site'])) {
            $siteMap = $this->siteSettingsMap();
            $maps[] = $siteMap[$context] ?? [];
        }
        $globalMap = $this->settings->get(self::SETTING_KEY, []);
        $maps[] = (is_array($globalMap) ? $globalMap[$context] ?? [] : []);
        $maps[] = $this->defaultMap[$context] ?? [];

        $names = [];
        foreach ($maps as $map) {
            if (!is_array($map)) {
                continue;
            }
            foreach ($keys as $key) {
                $value = $map[$key] ?? null;
                // LAYER 2 IMMUNITY (docs/config-ui-spec.md §6.3). An empty/null row
                // is NOT an override — it means "inherit", and inheritance is
                // expressed as row ABSENCE. Skipping it here means a stray empty row
                // (a buggy save, a hand-edited setting, another module writing our
                // key) can never freeze a site against global changes: resolution
                // just continues to the next tier. Do not "simplify" this away.
                if ($value === null || $value === '') {
                    continue;
                }
                $names[] = (string) $value;
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Lookup keys for a media type, most specific first.
     *
     * @return string[]
     */
    protected function lookupKeys(?string $mediaType): array
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

    protected function siteSettingsMap(): array
    {
        try {
            $map = $this->siteSettings->get(self::SETTING_KEY, []);
        } catch (\Exception $e) {
            // No site in scope; treat as no per-site overrides.
            return [];
        }
        return is_array($map) ? $map : [];
    }

    protected function getPlayer(string $name): ?PlayerInterface
    {
        try {
            return $this->players->get($name);
        } catch (\Exception $e) {
            return null;
        }
    }
}
