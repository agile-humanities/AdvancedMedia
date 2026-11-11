<?php
namespace AdvancedMedia\File;

use Doctrine\DBAL\Connection;
use Omeka\Settings\Settings;

/**
 * Do the IIIF and file modules work with this installation's file store? Live checks of what the Agile patches
 * (AgileThemeTools docs/PATCHES.md) protect, run against the real site, so that a lost patch, a module update or a
 * server rule is seen even when the code looks right.
 *
 * Each check returns: key, label, state (ok | warning | error | skipped), message, and the Agile patch it relates to.
 * The HTTP checks request the site itself, as an anonymous visitor would, through its public address: they see what
 * a CDN, a WAF or the web server do to these requests too. They use one small public image and its item, with a short
 * timeout, and the result is kept for a few minutes (the config page runs them on load).
 */
class IntegrationChecks
{
    const LAST_RESULT_KEY = 'advancedmedia_integration_checks';
    const MAX_AGE = 600;
    const TIMEOUT = 15;

    /** @var Connection */
    private $connection;
    /** @var Settings */
    private $settings;
    /** @var object Omeka\File\Store */
    private $store;
    /** @var callable|null fn(string $name): bool, whether a module is active */
    private $isActive;
    /** @var callable|null fn(int $mediaId): bool, whether ImageServer has tiles for the media */
    private $hasTiles;

    public function __construct(Connection $connection, Settings $settings, $store, ?callable $isActive = null, ?callable $hasTiles = null)
    {
        $this->connection = $connection;
        $this->settings = $settings;
        $this->store = $store;
        $this->isActive = $isActive;
        $this->hasTiles = $hasTiles;
    }

    public function isRemoteStore(): bool
    {
        return !$this->store instanceof \Omeka\File\Store\Local;
    }

    /**
     * The checks, from the cache when recent enough.
     *
     * @param string $baseUrl The public base url of the site (scheme, host and Omeka base path), no trailing slash.
     * @return array{at: int, base: string, checks: array[]}
     */
    public function results(string $baseUrl, bool $refresh = false): array
    {
        $cached = $this->settings->get(self::LAST_RESULT_KEY);
        if (!$refresh && is_array($cached) && ($cached['base'] ?? '') === $baseUrl && time() - (int) ($cached['at'] ?? 0) < self::MAX_AGE) {
            return $cached;
        }
        $result = ['at' => time(), 'base' => $baseUrl, 'checks' => $this->run($baseUrl)];
        $this->settings->set(self::LAST_RESULT_KEY, $result);
        return $result;
    }

    /** @return array[] */
    public function run(string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $checks = [];
        $iiif = $this->active('IiifServer');
        $imageServer = $this->active('ImageServer');

        $checks[] = $this->checkMediaIdentifier($iiif);
        $checks[] = $this->checkFastTileRule($imageServer);

        // The HTTP checks need the public address of the site, which a job run without a request (cron, command
        // line) does not have: report them as not checked rather than failed.
        if (!preg_match('~^https?://[^/]+~i', $baseUrl)) {
            foreach ([['image-processing', 'IIIF image processing', 'imageserver-remote-source-readable'], ['manifest-caching', 'IIIF manifest caching', 'iiifserver-free-variant-without-access'], ['info-tiles', 'Tiles advertised for untiled images', 'iiifserver-no-synthetic-tiles']] as [$key, $label, $patch]) {
                $checks[] = ['key' => $key, 'label' => $label, 'patch' => $patch, 'state' => 'skipped', 'message' => 'The public address of the site is unknown here (a job run without a request): run the checks from the Advanced Media config page.'];
            }
            return $checks;
        }

        $sample = ($iiif || $imageServer) ? $this->sample() : null;
        $checks[] = $this->checkImageProcessing($imageServer, $sample, $baseUrl);
        $checks[] = $this->checkManifestCaching($iiif, $sample, $baseUrl);
        $checks[] = $this->checkInfoTiles($iiif && $imageServer, $sample, $baseUrl);
        return $checks;
    }

    protected function checkMediaIdentifier(bool $iiif): array
    {
        $check = ['key' => 'media-identifier', 'label' => 'IIIF media identifier', 'patch' => 'iiifserver-keep-media-identifier'];
        if (!$iiif) {
            return $check + ['state' => 'skipped', 'message' => 'IIIF Server is not active.'];
        }
        $identifier = (string) $this->settings->get('iiifserver_media_api_identifier', 'media_id');
        if (in_array($identifier, ['', 'default', 'media_id'], true)) {
            return $check + ['state' => 'ok', 'message' => sprintf('IIIF image urls use the media id ("%s").', $identifier ?: 'default')];
        }
        if (in_array($identifier, ['filename', 'filename_image', 'storage_id'], true)) {
            return $check + ['state' => 'warning', 'message' => sprintf(
                'IIIF image urls use the stored file path ("%s"), which contains a slash, sent as "%%2F". Apache refuses it unless "AllowEncodedSlashes NoDecode" is set, and with media on an external store no image server reads these paths anyway. Unless an external image server was set up on purpose, set "Media identifier" back to "media id" (Image Server config, Configuration tab). IIIF Server switches it by itself when it believes an external image server answers: the Agile patch "iiifserver-keep-media-identifier" prevents that.',
                $identifier
            )];
        }
        return $check + ['state' => 'ok', 'message' => sprintf('IIIF image urls use "%s".', $identifier)];
    }

    protected function checkFastTileRule(bool $imageServer): array
    {
        $check = ['key' => 'fast-tile-rule', 'label' => 'Fast IIIF tile rule', 'patch' => 'imageserver-no-fast-tile-on-remote-store'];
        if (!$imageServer) {
            return $check + ['state' => 'skipped', 'message' => 'Image Server is not active.'];
        }
        $htaccess = @file_get_contents(OMEKA_PATH . '/.htaccess');
        $active = is_string($htaccess) && preg_match('/^[ \t]*RewriteRule\b[^\n]*iiiftile\.php/m', $htaccess);
        if (!$active) {
            return $check + ['state' => 'ok', 'message' => 'No fast tile rule is active in .htaccess.'];
        }
        if ($this->isRemoteStore()) {
            return $check + ['state' => 'error', 'message' => 'A rewrite rule in .htaccess sends IIIF image requests to Image Server\'s fast tile script, which reads originals from the local disk. Files are on an external store, so every IIIF image fails: remove the "Module ImageServer: fast IIIF tile server" rules from .htaccess. The Agile patch "imageserver-no-fast-tile-on-remote-store" stops the config page from adding them.'];
        }
        return $check + ['state' => 'ok', 'message' => 'The fast tile rule is active; files are stored locally, which it needs.'];
    }

    protected function checkImageProcessing(bool $imageServer, ?array $sample, string $baseUrl): array
    {
        $check = ['key' => 'image-processing', 'label' => 'IIIF image processing', 'patch' => 'imageserver-remote-source-readable'];
        if (!$imageServer) {
            return $check + ['state' => 'skipped', 'message' => 'Image Server is not active.'];
        }
        if (!$sample) {
            return $check + ['state' => 'skipped', 'message' => 'No public image to test with.'];
        }
        // A region forces the imager to read and process the original: a full size could be redirected to a
        // pre-made thumbnail without the original being read at all.
        $url = sprintf('%s/iiif/2/%d/0,0,64,64/64,/0/default.jpg?agile-check=%d', $baseUrl, $sample['media'], time());
        [$status, $type, $error, $server] = $this->fetch($url);
        if ($status === 200 && strpos($type, 'image/') === 0) {
            return $check + ['state' => 'ok', 'message' => sprintf('Image Server read and processed the original of media #%d%s.', $sample['media'], $this->isRemoteStore() ? ' from the external store' : '')];
        }
        return $check + ['state' => 'error', 'message' => $this->httpFailure('Image Server could not process media #' . $sample['media'], $url, $status, $type, $error, $server)
            . ($this->isRemoteStore() && $status >= 500 ? ' With an external store, this is what a missing Agile patch "imageserver-remote-source-readable" does.' : '')];
    }

    protected function checkManifestCaching(bool $iiif, ?array $sample, string $baseUrl): array
    {
        $check = ['key' => 'manifest-caching', 'label' => 'IIIF manifest caching', 'patch' => 'iiifserver-free-variant-without-access'];
        if (!$iiif) {
            return $check + ['state' => 'skipped', 'message' => 'IIIF Server is not active.'];
        }
        if (!$sample) {
            return $check + ['state' => 'skipped', 'message' => 'No public item with an image to test with.'];
        }
        $url = sprintf('%s/iiif/3/%d/manifest', $baseUrl, $sample['item']);
        [$status, $type, $error, $server, $headers] = $this->fetch($url, true);
        if ($status !== 200) {
            return $check + ['state' => 'warning', 'message' => $this->httpFailure('The manifest of item #' . $sample['item'] . ' could not be read', $url, $status, $type, $error, $server)];
        }
        $cacheControl = strtolower($this->header($headers, 'Cache-Control'));
        if (strpos($cacheControl, 'public') !== false) {
            return $check + ['state' => 'ok', 'message' => sprintf('Manifests are sent as cacheable by browsers and CDNs (Cache-Control: %s).', $cacheControl)];
        }
        return $check + ['state' => 'warning', 'message' => sprintf(
            'The manifest of item #%d is sent as "Cache-Control: %s" to an anonymous visitor, so no CDN or browser caches manifests, and large ones are rebuilt often. Without the module Access, IIIF Server 3.6.34+ does this unless the Agile patch "iiifserver-free-variant-without-access" is applied.',
            $sample['item'], $cacheControl ?: 'none'
        )];
    }

    protected function checkInfoTiles(bool $active, ?array $sample, string $baseUrl): array
    {
        $check = ['key' => 'info-tiles', 'label' => 'Tiles advertised for untiled images', 'patch' => 'iiifserver-no-synthetic-tiles'];
        if (!$active || !$this->isRemoteStore()) {
            return $check + ['state' => 'skipped', 'message' => $active ? 'Files are stored locally: a tile grid for untiled images is cheap.' : 'IIIF Server and Image Server are not both active.'];
        }
        if (!$sample || !$this->hasTiles) {
            return $check + ['state' => 'skipped', 'message' => 'No public image to test with.'];
        }
        if (($this->hasTiles)((int) $sample['media'])) {
            return $check + ['state' => 'skipped', 'message' => sprintf('The test image (media #%d) is tiled.', $sample['media'])];
        }
        $url = sprintf('%s/iiif/2/%d/info.json', $baseUrl, $sample['media']);
        [$status, $type, $error, $server, , $body] = $this->fetch($url, true, true);
        if ($status !== 200) {
            return $check + ['state' => 'warning', 'message' => $this->httpFailure('The info.json of media #' . $sample['media'] . ' could not be read', $url, $status, $type, $error, $server)];
        }
        $info = json_decode((string) $body, true);
        if (is_array($info) && !empty($info['tiles'])) {
            return $check + ['state' => 'warning', 'message' => sprintf('The info.json of media #%d, which is not tiled, advertises a tile grid: viewers request dozens of tiles, and with an external store each one makes Image Server download the whole original. The Agile patch "iiifserver-no-synthetic-tiles" stops that.', $sample['media'])];
        }
        return $check + ['state' => 'ok', 'message' => 'Untiled images advertise their sizes only, as they should with an external store.'];
    }

    /** A small public image and its public item, smallest first. */
    protected function sample(): ?array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT m.id AS media, m.item_id AS item FROM media m'
                . ' JOIN resource rm ON rm.id = m.id JOIN resource ri ON ri.id = m.item_id'
                . " WHERE m.has_original = 1 AND m.media_type IN ('image/jpeg', 'image/png') AND m.size > 0"
                . ' AND rm.is_public = 1 AND ri.is_public = 1'
                . ' AND (SELECT COUNT(*) FROM media m2 WHERE m2.item_id = m.item_id) = 1'
                . ' ORDER BY m.size ASC LIMIT 1'
            );
        } catch (\Throwable $e) {
            return null;
        }
        return $row ? ['media' => (int) $row['media'], 'item' => (int) $row['item']] : null;
    }

    protected function active(string $module): bool
    {
        return $this->isActive ? (bool) ($this->isActive)($module) : true;
    }

    /** @return array{0: ?int, 1: string, 2: string, 3: string, 4: string[], 5: ?string} status, type, error, server, headers, body */
    protected function fetch(string $url, bool $withHeaders = false, bool $withBody = false): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => self::TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "Accept: */*\r\n",
        ]]);
        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            $error = error_get_last();
            return [null, '', isset($error['message']) ? preg_replace('~^fopen\([^)]*\):\s*~', '', $error['message']) : '', '', [], null];
        }
        $meta = stream_get_meta_data($handle);
        $body = $withBody ? stream_get_contents($handle, 2000000) : null;
        fclose($handle);
        $headers = (array) ($meta['wrapper_data'] ?? []);
        $status = null;
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string) $header, $m)) {
                $status = (int) $m[1];
            }
        }
        return [$status, strtolower($this->header($headers, 'Content-Type')), '', $this->header($headers, 'Server'), $headers, $body];
    }

    protected function header(array $headers, string $name): string
    {
        $value = '';
        foreach ($headers as $header) {
            if (stripos((string) $header, $name . ':') === 0) {
                $value = trim(substr((string) $header, strlen($name) + 1));
            }
        }
        return $value;
    }

    protected function httpFailure(string $what, string $url, ?int $status, string $type, string $error, string $server): string
    {
        if ($status === null) {
            return sprintf('%s: the server could not request %s (%s). This check needs the server to reach its own public address; see the reachability check above.', $what, $url, $error ?: 'no response');
        }
        if (in_array($status, [202, 401, 403, 429], true)) {
            return sprintf('%s: the request to %s was refused with HTTP %d%s before Omeka could answer. A CDN or firewall rule blocks the server\'s own requests: allow its address, then check again.', $what, $url, $status, $server !== '' ? ' by "' . $server . '"' : '');
        }
        return sprintf('%s: %s answered HTTP %d (%s).', $what, $url, $status, $type ?: 'no content type');
    }
}
