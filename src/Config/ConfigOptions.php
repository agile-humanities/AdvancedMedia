<?php
namespace AdvancedMedia\Config;

use AdvancedMedia\Media\Compound\Manager as CompoundManager;
use AdvancedMedia\Media\Player\Manager as PlayerManager;
use AdvancedMedia\Media\Structure\Manager as StructureManager;
use Doctrine\DBAL\Connection;
use Omeka\Settings\Settings;
use Omeka\Settings\SiteSettings;

/**
 * Assembles everything the configuration form needs, for EITHER scope.
 *
 * Scope-agnostic on purpose (config-ui-spec §2.3): the same component mounts at
 * global scope (module config, where we own the whole page) and at site scope
 * (a guest inside core's SiteSettingsForm, which has no tabs — only an
 * element_groups sidebar). The shared thing is the data + the partial + the
 * inheritance logic; only the chrome differs.
 *
 * INHERITANCE IS COMPUTED HERE, ONCE. Every row carries both its explicit value
 * (or '' for "inherit") and its INHERITED value — what it would resolve to if left
 * alone. That is what lets the UI distinguish "inheriting" from "explicitly set to
 * a value that happens to equal the inherited one" — a distinction that, if lost,
 * silently breaks propagation of future global changes (architecture §5.2).
 */
class ConfigOptions
{
    const CONTEXT = 'page';

    const SELECTOR_KEY = 'advancedmedia_compounds';
    const STRUCTURE_KEY = 'advancedmedia_structure_compounds';
    const PLAYER_KEY = 'advancedmedia_players';

    /**
     * Display POLICY (a boolean, not a player): when on, a loose media that is
     * neither the item's primary media nor part of a compound/viewer is hidden.
     * When off, every media renders through its mapped player.
     *
     * Deliberately NOT a per-MIME player value — "whether to show" is orthogonal
     * to "how to render", so folding it into the player dropdown would cost the
     * admin the ability to both gate on primary AND map a real player. The gate
     * lives in the identity compound (the only path that renders loose media);
     * compound-claimed media are shown by their compound regardless.
     *
     * Global-scope. Shipped default ON (the client's "coded default").
     */
    const PRIMARY_COMPOUND_ONLY_KEY = 'advancedmedia_primary_and_compound_only';
    const PRIMARY_COMPOUND_ONLY_DEFAULT = true;

    /**
     * Display ORDER policy: when on, the group holding the item's primary media is
     * hoisted to the front; everything else keeps attachment order.
     *
     * A sibling of the policy above, not part of it — one decides WHETHER a media
     * appears, this one decides WHERE. Both are display-time, both are
     * global-with-site-override.
     *
     * Shipped default OFF, and that is the point. Attachment order is not an
     * accident: Omeka gives editors a drag-and-drop ordering UI on the item, and an
     * editor who puts a contextual image ahead of the primary video may well mean
     * it. Defaulting this on would silently overrule every such decision across the
     * install. Off means a site that wants primary-first has SAID so.
     */
    const PRIMARY_FIRST_KEY = 'advancedmedia_primary_first';
    const PRIMARY_FIRST_DEFAULT = false;

    /**
     * Which media types the Downloads block offers.
     *
     * Deliberately a SEPARATE map from PLAYER_KEY rather than a player value:
     * downloadability is orthogonal to how a media is displayed. The whole point
     * of the Downloads block is that an item can show a proper viewer AND offer a
     * download of the same file — which is impossible to express if "download" is
     * just another player choice.
     *
     * Values are the strings '1' and '0', not booleans. ConfigWriter treats '' as
     * "inherit" and stringifies scalars, so '0' survives the round trip intact
     * while false would become '' and read back as inherit.
     */
    const DOWNLOAD_KEY = 'advancedmedia_downloads';

    /**
     * Suppress downloads for a media type when an item holds more than this many
     * of it. 0 means no limit. Global-or-site, like everything else here.
     *
     * Counted PER MEDIA TYPE, not per item, which is what makes one number work:
     * in this collection PDFs/audio/video never exceed 2 per item while images
     * reach 1712, so a threshold that tames a page-image set leaves every
     * individually-useful download alone.
     */
    const THRESHOLD_KEY = 'advancedmedia_download_threshold';

    const THRESHOLD_DEFAULT = 10;

    /** Wildcard rows always offered in the players band, before the exact-MIME rows. */
    const PLAYER_GROUP_ROWS = ['audio/*', 'video/*', 'image/*'];

    /** @var StructureManager */
    private $structures;

    /** @var CompoundManager */
    private $compounds;

    /** @var PlayerManager */
    private $players;

    /** @var Settings */
    private $settings;

    /** @var SiteSettings */
    private $siteSettings;

    /** @var Connection */
    private $connection;

    /** @var array */
    private $config;

    /** @var mixed The configured file store, for the proxy panel. */
    private $store;

    public function __construct(
        StructureManager $structures,
        CompoundManager $compounds,
        PlayerManager $players,
        Settings $settings,
        SiteSettings $siteSettings,
        Connection $connection,
        array $config,
        $store = null
    ) {
        $this->store = $store;
        $this->structures = $structures;
        $this->compounds = $compounds;
        $this->players = $players;
        $this->settings = $settings;
        $this->siteSettings = $siteSettings;
        $this->connection = $connection;
        $this->config = $config;
    }

    /**
     * @param string $scope 'global' | 'site'
     */
    public function build(string $scope): array
    {
        $isSite = ($scope === 'site');

        // The values explicitly stored AT THIS SCOPE. Sparse: a missing row means
        // "inherit", and that is the only way inheritance is expressed.
        $stored = [
            self::SELECTOR_KEY => $this->storedMap(self::SELECTOR_KEY, $isSite),
            self::STRUCTURE_KEY => $this->storedMap(self::STRUCTURE_KEY, $isSite),
            self::PLAYER_KEY => $this->storedMap(self::PLAYER_KEY, $isSite),
            self::DOWNLOAD_KEY => $this->storedMap(self::DOWNLOAD_KEY, $isSite),
        ];

        // What each row would resolve to if left alone. At site scope that is the
        // global value (falling back to the shipped default); at global scope it is
        // the shipped default.
        $inherited = [
            self::SELECTOR_KEY => $isSite ? $this->globalMap(self::SELECTOR_KEY) : [],
            self::STRUCTURE_KEY => $this->mergeInherited(
                $this->shipped('default_structure_compounds'),
                $isSite ? $this->globalMap(self::STRUCTURE_KEY) : []
            ),
            self::PLAYER_KEY => $this->mergeInherited(
                $this->shipped('default_players'),
                $isSite ? $this->globalMap(self::PLAYER_KEY) : []
            ),
            self::DOWNLOAD_KEY => $this->mergeInherited(
                $this->shipped('default_downloads'),
                $isSite ? $this->globalMap(self::DOWNLOAD_KEY) : []
            ),
        ];

        $templates = $this->templateRows();
        $structures = $this->structureRows();
        $players = $this->playerRows();

        // A player row's inherited value must be resolved the SAME WAY THE RESOLVER
        // RESOLVES IT — exact MIME → top-level wildcard → 'default' — not by an exact
        // key lookup. The shipped map only carries 'audio/*', 'video/*' and 'default',
        // so an exact lookup leaves rows like `application/pdf` and `image/jpeg` with no
        // effective value to show, and the null state silently stops naming what it
        // falls back to. Naming that value is the entire job of the null state.
        $inherited[self::PLAYER_KEY] = $this->expandPlayerInheritance(
            $players,
            $inherited[self::PLAYER_KEY]
        );
        // The download column sits on the SAME rows and resolves through the SAME
        // cascade (exact MIME → top-level wildcard → 'default'), so its null state
        // can name what it falls back to just as the player column does.
        $inherited[self::DOWNLOAD_KEY] = $this->expandPlayerInheritance(
            $players,
            $inherited[self::DOWNLOAD_KEY]
        );

        $rendered = [
            self::SELECTOR_KEY => array_column($templates, 'key'),
            self::STRUCTURE_KEY => array_column($structures, 'key'),
            self::PLAYER_KEY => array_column($players, 'key'),
            self::DOWNLOAD_KEY => array_column($players, 'key'),
        ];
        // strval on every key: the anomaly check compares stored VALUES (always
        // strings) against these with a STRICT in_array. PHP silently casts a
        // numeric string array key to an integer, so downloadChoices()' '1'/'0'
        // keys come back as ints 1/0 — and a strict compare of the stored string
        // '1' against int 1 fails, flagging every enabled download row as an
        // "unavailable viewer". Normalising to strings here is the fix.
        $valid = [
            self::SELECTOR_KEY => array_map('strval', array_keys($this->compoundChoices(null))),
            self::STRUCTURE_KEY => array_map('strval', array_keys($this->compoundChoices(null))),
            self::PLAYER_KEY => array_map('strval', array_keys($this->playerChoices('default'))),
            self::DOWNLOAD_KEY => array_map('strval', array_keys(self::downloadChoices())),
        ];

        return [
            'scope' => $scope,
            'context' => self::CONTEXT,
            // At site scope the row inherits from GLOBAL, so say so. At global scope
            // there is nothing above it, so "the default" is unambiguous — and any
            // qualifier ("shipped", "built-in", "module") is developer vocabulary that
            // only prompts the question "which default?".
            'inheritLabel' => $isSite
                ? 'Use global default' // @translate
                : 'Use default', // @translate
            'keys' => [
                'selector' => self::SELECTOR_KEY,
                'structure' => self::STRUCTURE_KEY,
                'player' => self::PLAYER_KEY,
                'download' => self::DOWNLOAD_KEY,
            ],
            'templates' => $templates,
            'structures' => $structures,
            'players' => $players,
            'allCompounds' => $this->compoundChoices(null),
            'downloadChoices' => self::downloadChoices(),
            'threshold' => $this->thresholdRow($isSite),
            // Global-only: server-side fetch failures are an installation-wide
            // symptom (an unreachable base URI), not a per-site setting.
            'fetchFailures' => $isSite ? [] : $this->fetchFailures(),
            'proxy' => $isSite ? null : $this->proxyRow(),
            // Display policy — shown at BOTH scopes. Global is a plain on/off
            // (the base); site is a tri-state that can inherit the global value.
            'primaryCompoundOnly' => [
                'key' => self::PRIMARY_COMPOUND_ONLY_KEY,
                'scope' => $scope,
                // Effective global value: the checkbox state at global scope, and
                // what "Use global (currently: …)" reports at site scope.
                'global' => (bool) $this->settings->get(
                    self::PRIMARY_COMPOUND_ONLY_KEY,
                    self::PRIMARY_COMPOUND_ONLY_DEFAULT
                ),
                // Site's own stored value: '' (inherit) | '1' | '0'. Null at global.
                'siteStored' => $isSite ? $this->triStateSiteStored(self::PRIMARY_COMPOUND_ONLY_KEY) : null,
            ],
            // Display ORDER — same two-scope shape as the policy above.
            'primaryFirst' => [
                'key' => self::PRIMARY_FIRST_KEY,
                'scope' => $scope,
                'global' => (bool) $this->settings->get(
                    self::PRIMARY_FIRST_KEY,
                    self::PRIMARY_FIRST_DEFAULT
                ),
                'siteStored' => $isSite ? $this->triStateSiteStored(self::PRIMARY_FIRST_KEY) : null,
            ],
            'stored' => $stored,
            'inherited' => $inherited,
            'anomalies' => $this->anomalies($stored, $rendered, $valid),
            'summary' => $this->summary($stored, $rendered),
        ];
    }

    /**
     * LAYER 3 (config-ui-spec §6.3): find stored rows that are not doing what their
     * presence implies, so the UI can surface them instead of letting them rot.
     *
     * Three kinds, and they are NOT treated alike:
     *
     *   empty   — value is ''/null. Debris. Cannot be produced by our own save (which
     *             prunes), so it came from a hand-edited setting, an older build, or
     *             another module writing our key. It is INERT — layer 2 makes the
     *             resolvers read '' as "inherit" — so clearing it can never change how
     *             anything renders. Safe to offer a one-click Clear, and we say so.
     *
     *   orphan  — value names a compound/player that is not currently available (its
     *             module is uninstalled). Flagged, IGNORED at resolve time, and
     *             DELIBERATELY NOT REMOVED: the module may be reinstalled, and
     *             silently discarding an admin's choice would be worse than the row
     *             being briefly inert.
     *
     *   unknown — the ROW KEY itself is no longer offered (a deleted template, a media
     *             type whose last file is gone, a structure from a disabled module).
     *             Also preserved, for the same reason.
     */
    private function anomalies(array $stored, array $rendered, array $valid): array
    {
        $out = ['empty' => [], 'orphan' => [], 'unknown' => [], 'anyEmpty' => false];

        foreach ($stored as $settingKey => $rows) {
            foreach ($rows as $rowKey => $value) {
                if ($value === null || $value === '') {
                    $out['empty'][$settingKey][] = (string) $rowKey;
                    $out['anyEmpty'] = true;
                    continue;
                }
                if (!in_array((string) $value, $valid[$settingKey] ?? [], true)) {
                    $out['orphan'][] = [
                        'settingKey' => $settingKey,
                        'row' => (string) $rowKey,
                        'value' => (string) $value,
                    ];
                    continue;
                }
                if (!in_array((string) $rowKey, $rendered[$settingKey] ?? [], true)) {
                    $out['unknown'][] = [
                        'settingKey' => $settingKey,
                        'row' => (string) $rowKey,
                        'value' => (string) $value,
                    ];
                }
            }
        }
        return $out;
    }

    /** "This site overrides N of M rows" (config-ui-spec §6.4). */
    private function summary(array $stored, array $rendered): array
    {
        $overrides = 0;
        foreach ($stored as $rows) {
            foreach ($rows as $value) {
                if ($value !== null && $value !== '') {
                    $overrides++;
                }
            }
        }
        $total = 0;
        foreach ($rendered as $rows) {
            $total += count($rows);
        }
        return ['overrides' => $overrides, 'total' => $total];
    }

    /**
     * Which sites override what, and which carry empty debris.
     *
     * This is what makes a frozen site FINDABLE rather than something you stumble on
     * months later. Read straight from site_setting: iterating SiteSettings would need
     * a target-id switch per site, and this is a read-only overview.
     */
    public function siteOverview(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ss.site_id, ss.id AS setting_key, ss.value, s.slug, s.title
             FROM site_setting ss JOIN site s ON s.id = ss.site_id
             WHERE ss.id IN (?)',
            [[self::SELECTOR_KEY, self::STRUCTURE_KEY, self::PLAYER_KEY, self::DOWNLOAD_KEY]],
            [Connection::PARAM_STR_ARRAY]
        );

        $sites = [];
        foreach ($rows as $r) {
            $id = (int) $r['site_id'];
            if (!isset($sites[$id])) {
                $sites[$id] = ['slug' => $r['slug'], 'title' => $r['title'], 'overrides' => 0, 'empty' => 0];
            }
            $map = json_decode((string) $r['value'], true);
            $cells = (is_array($map) && isset($map[self::CONTEXT]) && is_array($map[self::CONTEXT]))
                ? $map[self::CONTEXT]
                : [];
            foreach ($cells as $value) {
                if ($value === null || $value === '') {
                    $sites[$id]['empty']++;
                } else {
                    $sites[$id]['overrides']++;
                }
            }
        }
        return $sites;
    }

    /**
     * Band A rows: resource templates in use, with item counts.
     *
     * Only templates that ACTUALLY HOLD ITEMS are offered — a dropdown listing every
     * template ever defined is unusable, and a row that can never match is noise.
     */
    private function templateRows(): array
    {
        $counts = $this->connection->fetchAllKeyValue(
            'SELECT resource_template_id, COUNT(*) FROM resource
             WHERE resource_type = ? AND resource_template_id IS NOT NULL
             GROUP BY resource_template_id',
            ['Omeka\Entity\Item']
        );
        if (!$counts) {
            return [];
        }
        $labels = $this->connection->fetchAllKeyValue(
            'SELECT id, label FROM resource_template WHERE id IN (?)',
            [array_keys($counts)],
            [Connection::PARAM_INT_ARRAY]
        );

        $rows = [];
        foreach ($counts as $id => $count) {
            if (!isset($labels[$id])) {
                continue;
            }
            $rows[] = [
                'key' => 'template:' . $id,
                'label' => $labels[$id],
                'count' => (int) $count,
            ];
        }
        usort($rows, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        return $rows;
    }

    /**
     * Band B rows: one per registered structure, with the compounds that can render
     * it and the structure's own help text.
     *
     * The help text comes from the STRUCTURE (getRequirements()), not from the view —
     * so it cannot drift from what the detector actually does (config-ui-spec §5).
     */
    private function structureRows(): array
    {
        $shipped = $this->shipped('default_structure_compounds');

        $rows = [];
        foreach ($this->structures->getOrdered() as $structure) {
            $name = $structure->getName();
            $rows[] = [
                'key' => $name,
                'label' => $structure->getLabel(),
                'description' => $structure->getDescription(),
                'requirements' => $structure->getRequirements(),
                'choices' => $this->compoundChoices($name),
                'shipped' => $shipped[$name] ?? null,
            ];
        }
        return $rows;
    }

    /**
     * Band 2 (players) rows: the friendly wildcard groups, then every media type
     * ACTUALLY PRESENT in this install, with counts.
     *
     * Seeding from the real inventory rather than an abstract MIME list means an
     * admin can only configure things that exist here.
     */
    private function playerRows(): array
    {
        $present = $this->connection->fetchAllKeyValue(
            "SELECT media_type, COUNT(*) FROM media WHERE media_type IS NOT NULL AND media_type <> '' GROUP BY media_type"
        );
        arsort($present);

        $rows = [];
        foreach (self::PLAYER_GROUP_ROWS as $group) {
            $rows[] = [
                'key' => $group,
                'label' => $group,
                'count' => null,
                'group' => true,
                'choices' => $this->playerChoices($group),
                'warning' => null,
            ];
        }
        foreach ($present as $mediaType => $count) {
            $rows[] = [
                'key' => $mediaType,
                'label' => $mediaType,
                'count' => (int) $count,
                'group' => false,
                'choices' => $this->playerChoices($mediaType),
                'warning' => $mediaType === 'text/plain'
                    // Non-obvious, and it will bite whoever touches this row (STATUS §9).
                    ? 'WebVTT transcripts are stored as text/plain here. They are normally claimed by the Audio with Transcript structure before a player is ever resolved — but a mapping on this row would apply to any VTT that is not.' // @translate
                    : null,
            ];
        }
        $rows[] = [
            'key' => 'default',
            'label' => 'Everything else', // @translate
            'count' => null,
            'group' => true,
            'choices' => $this->playerChoices('default'),
            'warning' => 'This is the catch-all. It applies to every media type not matched above.', // @translate
        ];
        return $rows;
    }

    /**
     * Available compounds that can render a structure ('*' supporters included), or
     * ALL available compounds when $structureName is null (the template band, which
     * has no media context at config time and so cannot be filtered).
     */
    private function compoundChoices(?string $structureName): array
    {
        $choices = [];
        foreach ($this->compounds->getRegisteredNames() as $name) {
            try {
                $compound = $this->compounds->get($name);
            } catch (\Exception $e) {
                continue;
            }
            if (!$compound->isAvailable()) {
                // Availability is a capability concern: an adapter whose backing
                // module is absent simply vanishes from the dropdowns (§1.4).
                continue;
            }
            if ($structureName !== null) {
                $supported = $compound->getSupportedStructures();
                if (!in_array('*', $supported, true) && !in_array($structureName, $supported, true)) {
                    continue;
                }
            }
            $choices[$name] = $compound->getLabel();
        }
        return $choices;
    }

    /**
     * Recent server-side media-fetch failures (AdvancedMedia\File\FetchDiagnostics).
     *
     * Read straight from the setting rather than through the service: this class
     * is already the place that assembles everything the form needs, and the
     * service exists to WRITE the record from deep inside a render.
     */
    private function fetchFailures(): array
    {
        $failures = $this->settings->get(\AdvancedMedia\File\FetchDiagnostics::SETTING_KEY, []);
        return is_array($failures) ? $failures : [];
    }

    /**
     * A site's stored override for a tri-state boolean policy: '' (inherit) | '1' | '0'.
     *
     * Anything else — an absent row, a null, a value some other writer left behind —
     * normalises to '', so the control renders as "inherit" rather than inventing an
     * explicit override the admin never made.
     */
    private function triStateSiteStored(string $key): string
    {
        try {
            $v = $this->siteSettings->get($key, '');
        } catch (\Exception $e) {
            $v = '';
        }
        return ($v === '1' || $v === '0') ? (string) $v : '';
    }

    /**
     * State of the media URL proxy: the opt-in, the base URI it would use, and
     * the last probe result. Global only — this is an installation property.
     */
    private function proxyRow(): array
    {
        $store = $this->store;
        $last = $this->settings->get(\AdvancedMedia\File\ReachabilityProbe::LAST_RESULT_KEY, []);

        return [
            'enabledKey' => \AdvancedMedia\Service\File\Store\ProxyingAwsS3Factory::OPTION_PROXY_FILES,
            'baseUriKey' => \AdvancedMedia\Service\File\Store\ProxyingAwsS3Factory::OPTION_BASE_URI,
            'enabled' => (bool) $this->settings->get(
                \AdvancedMedia\Service\File\Store\ProxyingAwsS3Factory::OPTION_PROXY_FILES,
                false
            ),
            'pinnedBaseUri' => (string) $this->settings->get(
                \AdvancedMedia\Service\File\Store\ProxyingAwsS3Factory::OPTION_BASE_URI,
                ''
            ),
            'effectiveBaseUri' => ($store instanceof \AdvancedMedia\File\Store\ProxyingAwsS3)
                ? $store->baseUri()
                : null,
            // Null when the store is not an S3 one: proxying is meaningless on
            // local storage, whose URLs are already same-origin /files/… paths.
            'available' => $store instanceof \AdvancedMedia\File\Store\ProxyingAwsS3,
            'lastProbe' => is_array($last) ? $last : [],
            // Serve mode + the values needed to render the nginx snippet with this
            // install's bucket already filled in.
            'serveMode' => (string) $this->settings->get(
                \AdvancedMedia\File\ServeMode::SERVE_MODE_KEY,
                \AdvancedMedia\File\ServeMode::MODE_STREAM
            ),
            'xaccel' => ($store instanceof \AdvancedMedia\File\Store\ProxyingAwsS3) ? [
                'prefix' => \AdvancedMedia\File\ServeMode::XACCEL_PREFIX,
                'checkPath' => \AdvancedMedia\File\ServeMode::XACCEL_CHECK_PATH,
                'token' => \AdvancedMedia\File\ServeMode::XACCEL_TOKEN,
                's3Endpoint' => $store->s3Endpoint(),
                'bucket' => $store->bucket(),
            ] : null,
        ];
    }

    /**
     * The download column's three states.
     *
     * A checkbox cannot express this. Unchecked submits NOTHING, so the row is
     * absent from the POST, and ConfigWriter::mergePreserving() reads an absent row
     * as "the form never showed this — keep it verbatim". The result would be a
     * column you can switch on but never off. Adding a hidden companion field fixes
     * that but collapses "inherit" into "explicitly no", which silently freezes a
     * site against later global changes (architecture §5.2).
     *
     * Three states, therefore a select — same control as the player column beside it.
     */
    public static function downloadChoices(): array
    {
        return [
            '1' => 'Yes — offer a download', // @translate
            '0' => 'No', // @translate
        ];
    }

    /**
     * The per-scope download threshold, in the same explicit/inherited shape the
     * map rows use, so the view can render its null state identically.
     */
    private function thresholdRow(bool $isSite): array
    {
        $globalRaw = $this->settings->get(self::THRESHOLD_KEY, '');
        $global = ($globalRaw === '' || $globalRaw === null)
            ? self::THRESHOLD_DEFAULT
            : max(0, (int) $globalRaw);

        if (!$isSite) {
            return [
                'key' => self::THRESHOLD_KEY,
                'stored' => ($globalRaw === null) ? '' : (string) $globalRaw,
                'inherited' => self::THRESHOLD_DEFAULT,
            ];
        }

        try {
            $siteRaw = $this->siteSettings->get(self::THRESHOLD_KEY, '');
        } catch (\Exception $e) {
            $siteRaw = '';
        }
        return [
            'key' => self::THRESHOLD_KEY,
            'stored' => ($siteRaw === null) ? '' : (string) $siteRaw,
            'inherited' => $global,
        ];
    }

    /** Available players whose capabilities cover this media-type key. */
    private function playerChoices(string $key): array
    {
        $choices = [];
        foreach ($this->players->getRegisteredNames() as $name) {
            try {
                $player = $this->players->get($name);
            } catch (\Exception $e) {
                continue;
            }
            if (!$player->isAvailable()) {
                continue;
            }
            if ($this->capabilityCovers($player->getMediaTypeCapabilities(), $key)) {
                $choices[$name] = $player->getLabel();
            }
        }
        return $choices;
    }

    /**
     * Does any capability pattern cover this row key? The row key may itself be a
     * wildcard ('audio/*') or the catch-all ('default'), so this is a pattern-to-
     * pattern test, not the resolver's media-to-pattern test.
     */
    private function capabilityCovers(array $capabilities, string $key): bool
    {
        foreach ($capabilities as $capability) {
            if ($capability === '*' || $capability === $key || $key === 'default') {
                return true;
            }
            // 'audio/*' row is covered by an 'audio/mpeg' capability, and vice versa.
            $capBase = substr($capability, -2) === '/*' ? substr($capability, 0, -1) : null;
            $keyBase = substr($key, -2) === '/*' ? substr($key, 0, -1) : null;
            if ($capBase !== null && strpos($key, $capBase) === 0) {
                return true;
            }
            if ($keyBase !== null && strpos($capability, $keyBase) === 0) {
                return true;
            }
        }
        return false;
    }

    private function storedMap(string $key, bool $isSite): array
    {
        if ($isSite) {
            try {
                $map = $this->siteSettings->get($key, []);
            } catch (\Exception $e) {
                $map = [];
            }
        } else {
            $map = $this->settings->get($key, []);
        }
        if (!is_array($map)) {
            return [];
        }
        $context = $map[self::CONTEXT] ?? [];
        return is_array($context) ? $context : [];
    }

    private function globalMap(string $key): array
    {
        $map = $this->settings->get($key, []);
        if (!is_array($map)) {
            return [];
        }
        $context = $map[self::CONTEXT] ?? [];
        return is_array($context) ? $context : [];
    }

    private function shipped(string $which): array
    {
        $map = $this->config[$which][self::CONTEXT] ?? [];
        return is_array($map) ? $map : [];
    }

    /**
     * Give every rendered player row the value it would ACTUALLY resolve to, walking the
     * resolver's own cascade (PlayerResolver::lookupKeys): exact MIME → top-level
     * wildcard → 'default'.
     */
    private function expandPlayerInheritance(array $playerRows, array $rawMap): array
    {
        $resolved = [];
        foreach ($playerRows as $row) {
            foreach ($this->lookupKeys($row['key']) as $key) {
                if (isset($rawMap[$key]) && $rawMap[$key] !== '') {
                    $resolved[$row['key']] = $rawMap[$key];
                    break;
                }
            }
        }
        // Keep any raw entries for rows we did not render, so nothing is lost.
        return $resolved + $rawMap;
    }

    /** Mirrors PlayerResolver::lookupKeys(), for a row key rather than a media type. */
    private function lookupKeys(string $rowKey): array
    {
        if ($rowKey === 'default') {
            return ['default'];
        }
        if (substr($rowKey, -2) === '/*') {
            return [$rowKey, 'default'];
        }
        $keys = [$rowKey];
        $slash = strpos($rowKey, '/');
        if ($slash !== false) {
            $keys[] = substr($rowKey, 0, $slash) . '/*';
        }
        $keys[] = 'default';
        return $keys;
    }

    /** Global rows win over shipped rows; both are "inherited" from a site's view. */
    private function mergeInherited(array $shipped, array $global): array
    {
        foreach ($global as $rowKey => $value) {
            if ($value !== null && $value !== '') {
                $shipped[$rowKey] = $value;
            }
        }
        return $shipped;
    }
}
