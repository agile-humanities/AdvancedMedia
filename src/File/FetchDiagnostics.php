<?php
namespace AdvancedMedia\File;

use Laminas\Log\LoggerInterface;
use Omeka\Settings\Settings;

/**
 * Records server-side media-fetch failures so they cannot pass unnoticed.
 *
 * WHY THIS EXISTS
 * ---------------
 * VttParser used `@file_get_contents(...)` and returned false on failure. When
 * file proxying made the media URL unreachable from the server, transcripts
 * silently dropped to zero cues: no error, no log line, nothing on the page
 * except an absence. The bug was found by accident while chasing a visible 500
 * elsewhere. A failure that produces a slightly emptier page is exactly the kind
 * that survives for months.
 *
 * So: keep the graceful degradation — a missing transcript must not take the
 * page down — but make the failure DISCOVERABLE. Logged for whoever reads logs,
 * and surfaced on the config page for whoever does not.
 */
class FetchDiagnostics
{
    const SETTING_KEY = 'advancedmedia_fetch_failures';

    /** Bounded: this is a diagnostic breadcrumb, not an audit trail. */
    const MAX_RECORDED = 10;

    /** @var Settings */
    private $settings;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(Settings $settings, LoggerInterface $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * @param string $url     What we tried to fetch.
     * @param string $context Where from, e.g. 'VttParser'.
     */
    public function recordFailure(string $url, string $context): void
    {
        $this->logger->warn(sprintf(
            'AdvancedMedia: server-side fetch failed in %s for %s. If file proxying is on, check that the base URI is reachable FROM THE SERVER (it may be a public-only host or port).', // @translate
            $context,
            $url
        ));

        $failures = $this->settings->get(self::SETTING_KEY, []);
        if (!is_array($failures)) {
            $failures = [];
        }

        // Most recent first, de-duplicated by url+context so one broken
        // transcript reloaded fifty times does not evict everything else.
        $key = $context . '|' . $url;
        $failures = array_values(array_filter(
            $failures,
            fn ($f) => is_array($f) && (($f['context'] ?? '') . '|' . ($f['url'] ?? '')) !== $key
        ));

        array_unshift($failures, [
            'url' => $url,
            'context' => $context,
            'at' => date('c'),
        ]);

        $this->settings->set(self::SETTING_KEY, array_slice($failures, 0, self::MAX_RECORDED));
    }

    /** @return array[] Most recent first. */
    public function failures(): array
    {
        $failures = $this->settings->get(self::SETTING_KEY, []);
        return is_array($failures) ? $failures : [];
    }

    public function clear(): void
    {
        $this->settings->delete(self::SETTING_KEY);
    }
}
