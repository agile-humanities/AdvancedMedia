<?php
namespace AdvancedMedia\Config;

use Omeka\Settings\Settings;
use Omeka\Settings\SiteSettings;

/**
 * Resolves a boolean DISPLAY POLICY for the scope a render is happening in:
 *
 *     site override ('1'/'0')  →  global value  →  coded default
 *
 * Extracted because there is now more than one such policy (the primary-and-grouped
 * gate in the identity compound, and primary-media-first ordering in the Composer),
 * and every one of them has to walk the cascade IDENTICALLY. In particular:
 *
 *   - a site value of '' (or an absent row) means INHERIT, never "off". Reading it
 *     as a value is the bug that silently freezes a site against later global
 *     changes (architecture §5.2) — the same layer-2 immunity the map resolvers have.
 *   - only a site-scoped render consults site settings at all; on the admin side
 *     there is no target site and SiteSettings would throw.
 *
 * One implementation, so a second policy cannot drift from the first.
 */
class ScopedFlag
{
    /** @var Settings */
    private $settings;

    /** @var SiteSettings */
    private $siteSettings;

    public function __construct(Settings $settings, SiteSettings $siteSettings)
    {
        $this->settings = $settings;
        $this->siteSettings = $siteSettings;
    }

    /**
     * @param string $key     The setting id, shared by both scopes.
     * @param bool   $default The coded default, used when nothing is stored anywhere.
     * @param array  $context The render context; 'site' (a site id) selects site scope.
     */
    public function isOn(string $key, bool $default, array $context = []): bool
    {
        if (!empty($context['site'])) {
            try {
                $site = $this->siteSettings->get($key, '');
            } catch (\Exception $e) {
                $site = '';
            }
            if ($site === '1') {
                return true;
            }
            if ($site === '0') {
                return false;
            }
            // '' / null → inherit → fall through to global.
        }

        return (bool) $this->settings->get($key, $default);
    }
}
