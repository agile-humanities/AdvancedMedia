<?php
namespace AdvancedMedia\Config;

use Omeka\Settings\Settings;

/**
 * Prunes and persists the three configuration maps.
 *
 * THE PRUNE IS THE WHOLE POINT (config-ui-spec §6.2, §6.3 layer 1).
 *
 * Inheritance is expressed as row ABSENCE — there is no "inherit" sentinel in
 * storage. The form has to submit *something* for every row, so it submits '' for
 * "inherit"; those rows must be stripped before anything is written.
 *
 * At SITE scope this is not merely tidiness. Core saves site settings with an
 * unconditional loop over the whole form:
 *
 *     foreach ($settingsForm->getData() as $id => $value) { $siteSettings->set($id, $value); }
 *
 * — so our keys are written on EVERY site-settings save, even when the admin never
 * opened our tab. If '' rows survived, every unrelated save would silently
 * materialise empty overrides on that site and freeze it against future global
 * changes, with nothing in the UI to show for it. Hence prune() is deliberately a
 * pure static: the site-scope input filter calls exactly the same code path as the
 * global-scope save, so the two cannot drift.
 *
 * Layer 2 (the resolvers ignoring '' rows on READ) means a prune failure is
 * survivable rather than catastrophic. Both layers, not one.
 */
class ConfigWriter
{
    const CONTEXT = 'page';

    const KEYS = [
        ConfigOptions::STRUCTURE_KEY,
        ConfigOptions::SELECTOR_KEY,
        ConfigOptions::PLAYER_KEY,
        // Values here are '1'/'0'. Both prune() and mergePreserving() guard on
        // `=== ''` and is_scalar(), so '0' passes through untouched and needs no
        // special handling — which is precisely why the column stores strings.
        ConfigOptions::DOWNLOAD_KEY,
    ];

    /**
     * Boolean display policies: a plain checkbox at global scope, a tri-state
     * ('1' | '0' | '' = inherit) at site scope. Listed here so adding another one
     * is a single entry rather than another hand-copied save method.
     */
    const SITE_TRISTATE_KEYS = [
        ConfigOptions::PRIMARY_COMPOUND_ONLY_KEY,
        ConfigOptions::PRIMARY_FIRST_KEY,
    ];

    /** @var Settings */
    private $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Strip "inherit" rows from a posted, context-keyed map.
     *
     * @param mixed $posted Expected shape: ['page' => ['<row>' => '<value>', ...]]
     */
    public static function prune($posted): array
    {
        if (!is_array($posted)) {
            return [];
        }
        $clean = [];
        foreach ($posted as $context => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            $keep = [];
            foreach ($rows as $rowKey => $value) {
                // '' is the form's "inherit" marker. Absence is the storage's.
                if ($value === null || $value === '' || !is_scalar($value)) {
                    continue;
                }
                $keep[(string) $rowKey] = (string) $value;
            }
            if ($keep) {
                $clean[(string) $context] = $keep;
            }
        }
        return $clean;
    }

    /**
     * Merge a posted map over the stored one, PRESERVING rows the form never showed.
     *
     * A form only renders rows that currently make sense: templates that hold items,
     * media types present in this install, registered structures. But storage may hold
     * a row for something not currently rendered — a media type whose last file was
     * deleted, a structure from a module that is temporarily disabled. Those rows are
     * absent from the POST, and a naive "replace the map with what was posted" would
     * silently DESTROY them.
     *
     * So: a row is removed only when the form actually showed it and the admin set it
     * to "inherit" ('' — present in the POST, pruned out). A row the form never showed
     * is left exactly as it was. Never destroy configuration you did not display.
     */
    public static function mergePreserving(array $postedRaw, array $storedMap): array
    {
        $context = self::CONTEXT;
        $postedRows = (isset($postedRaw[$context]) && is_array($postedRaw[$context]))
            ? $postedRaw[$context]
            : [];
        $storedRows = (isset($storedMap[$context]) && is_array($storedMap[$context]))
            ? $storedMap[$context]
            : [];

        $result = [];
        // Rows the form did not show: keep verbatim.
        foreach ($storedRows as $rowKey => $value) {
            if (!array_key_exists($rowKey, $postedRows)) {
                $result[(string) $rowKey] = $value;
            }
        }
        // Rows the form showed: an explicit value wins; '' means inherit, i.e. absent.
        foreach ($postedRows as $rowKey => $value) {
            if ($value !== null && $value !== '' && is_scalar($value)) {
                $result[(string) $rowKey] = (string) $value;
            }
        }

        return $result ? [$context => $result] : [];
    }

    /** Persist the maps at GLOBAL scope from a raw POST array. */
    public function saveGlobal(array $post): void
    {
        foreach (self::KEYS as $key) {
            $stored = $this->settings->get($key, []);
            $this->settings->set($key, self::mergePreserving(
                is_array($post[$key] ?? null) ? $post[$key] : [],
                is_array($stored) ? $stored : []
            ));
        }
        $this->saveThreshold($post, $this->settings);
    }

    /** Persist the maps at SITE scope. */
    public function saveSite(array $post, $siteSettings): void
    {
        foreach (self::KEYS as $key) {
            $stored = $siteSettings->get($key, []);
            $siteSettings->set($key, self::mergePreserving(
                is_array($post[$key] ?? null) ? $post[$key] : [],
                is_array($stored) ? $stored : []
            ));
        }
        $this->saveThreshold($post, $siteSettings);
        foreach (self::SITE_TRISTATE_KEYS as $key) {
            $this->saveTriStateSite($post, $siteSettings, $key);
        }
    }

    /**
     * A site-scope display-policy override, a tri-state scalar: '1' | '0' |
     * '' (inherit). Inherit is stored as absence, so an unrelated future global
     * change still propagates. Global scope is a plain checkbox saved elsewhere
     * (Module::handleConfigForm) — global is the base, it has nothing to inherit.
     *
     * A key absent from the POST means the form never showed it, so the stored
     * value is left alone — same rule mergePreserving() and saveThreshold() follow.
     */
    private function saveTriStateSite(array $post, $siteSettings, string $key): void
    {
        if (!array_key_exists($key, $post)) {
            return;
        }
        $raw = $post[$key];
        if ($raw === '1' || $raw === '0') {
            $siteSettings->set($key, $raw);
            return;
        }
        $siteSettings->delete($key); // '' = inherit the global value
    }

    /**
     * The download threshold is a scalar, not a map, so it cannot ride the
     * prune/merge machinery — but it follows the SAME convention: '' means
     * "inherit", and inheritance is expressed by the setting being absent.
     *
     * Deleting rather than storing '' matters for the same reason prune() does:
     * a stored '' at site scope would be an override that looks like nothing and
     * freezes the site against later changes to the global value.
     */
    private function saveThreshold(array $post, $store): void
    {
        $key = ConfigOptions::THRESHOLD_KEY;
        if (!array_key_exists($key, $post)) {
            // The form did not show it — leave whatever is stored alone.
            return;
        }

        $raw = $post[$key];
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            $store->delete($key);
            return;
        }

        $store->set($key, (string) max(0, (int) $raw));
    }

    /**
     * Remove the listed rows from storage (the "Clear" action behind the empty-row
     * warning, config-ui-spec §6.3 layer 3).
     *
     * Only ever called with rows the UI has flagged as EMPTY. Orphaned rows — a value
     * naming a compound whose module is currently uninstalled — are flagged but never
     * removed, because the module may come back.
     *
     * @param array $toRemove [ '<settingKey>' => ['<rowKey>', ...] ]
     */
    public function clearRows(array $toRemove, $store): void
    {
        foreach (self::KEYS as $key) {
            $rows = $toRemove[$key] ?? [];
            if (!$rows) {
                continue;
            }
            $map = $store->get($key, []);
            if (!is_array($map) || !isset($map[self::CONTEXT]) || !is_array($map[self::CONTEXT])) {
                continue;
            }
            foreach ($rows as $rowKey) {
                unset($map[self::CONTEXT][$rowKey]);
            }
            if (!$map[self::CONTEXT]) {
                unset($map[self::CONTEXT]);
            }
            $store->set($key, $map);
        }
    }

    public function settings(): Settings
    {
        return $this->settings;
    }
}
