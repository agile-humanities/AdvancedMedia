<?php
namespace AdvancedMedia\File;

use Doctrine\DBAL\Connection;

/**
 * Can this server reach its own public base URI?
 *
 * WHY THIS GATE EXISTS
 * --------------------
 * Media URL proxying rewrites $media->originalUrl() to point at THIS host. Most
 * consumers of that URL are browsers, which is the point — but a handful of
 * callers fetch it SERVER-SIDE (ImageServer loading a source image, IiifServer
 * and ImageServer reading image dimensions, our own transcript player reading a
 * VTT). Those callers are not buggy: on stock Omeka the URL is either never
 * fetched (local storage resolves a filesystem path first) or always fetchable
 * (S3 is public). Proxying is the first configuration where the URL is
 * browser-reachable but possibly not server-reachable.
 *
 * When that happens the symptoms do not point at the setting that caused them:
 * ImageServer returns HTTP 500 (reads as an ImageServer bug) and transcripts
 * render empty (reads as nothing at all). So the precondition is checked up
 * front, once, in the one place where the admin is making the decision.
 *
 * Known-unreachable shapes:
 *   - a container port mapping the server does not share (public :8080, Apache
 *     listening on :80 — `localhost:8080` simply has nothing behind it inside);
 *   - egress firewall rules forbidding the server to call its own public address;
 *   - split-horizon DNS resolving the public hostname elsewhere internally;
 *   - network gear that does not support NAT hairpinning;
 *   - Kubernetes/ECS pods with no route to their own external ingress;
 *   - an https base URI the server cannot validate (a broken CA trust store —
 *     which this deployment has hit before).
 *
 * A PASS IS NOT A PROMISE OF EFFICIENCY. Where the public URI resolves publicly,
 * the request leaves the host and comes back through the proxy: it works, but it
 * costs a round trip and a worker per server-side fetch, and it is subject to
 * whatever bot rules were added to /files in the first place. That is a
 * deliberate trade, not a defect — see docs/media-routing.md.
 *
 * TWO STEPS, BECAUSE ROUTING IS NOT ACCESS
 * ----------------------------------------
 * 1. The token path proves the address leads back to THIS Omeka install.
 * 2. A real media file (an original, then a derivative) proves the server is
 *    allowed to fetch what its consumers fetch.
 *
 * Step 1 alone passed on production on 2026-10-16 while every IIIF image
 * failed: a CDN bot rule (AWS WAF on CloudFront) blocked the server's own
 * requests under /files/original, /files/large and the other media folders,
 * as non-browser traffic from a data centre. The token path is not under
 * those folders, so nothing protecting media ever saw it. Testing the same
 * kind of path the consumers use is the only way to see that class of block.
 */
class ReachabilityProbe
{
    /** Path answered by FileController::probeAction(), ungated by the opt-in. */
    const PROBE_PATH = '__advancedmedia-probe';

    /** Body that path returns. Fixed, and carries nothing about the install. */
    const TOKEN = 'advancedmedia-probe-ok';

    /** Seconds. Short: a healthy loopback answers in milliseconds. */
    const TIMEOUT = 5;

    /** Where the most recent result is kept, for display on the config page. */
    const LAST_RESULT_KEY = 'advancedmedia_probe_last_result';

    /** Sample media tried before giving up on finding one present in storage. */
    const MEDIA_SAMPLES = 3;

    /** @var Connection|null */
    private $connection;

    /**
     * The connection is optional only so the class stays usable without a
     * database (it then skips step 2 and says so); the factory always passes it.
     */
    public function __construct(?Connection $connection = null)
    {
        $this->connection = $connection;
    }

    /**
     * @return array{ok: bool, reason: string, summary: string, message: string, url: string}
     *   `reason` is a stable machine key; `summary` is the non-technical lead
     *   (states plainly whether the feature can be used); `message` is the
     *   technical detail that follows it.
     */
    public function check(string $baseUri): array
    {
        $baseUri = rtrim($baseUri, '/');
        $url = $baseUri . '/' . self::PROBE_PATH;

        $parts = parse_url($baseUri);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $this->result(false, 'invalid-base-uri', $url, sprintf(
                'The base URI (%s) is not an absolute URL, so no media URLs can be built from it.', // @translate
                $baseUri === '' ? '(empty)' : $baseUri
            ));
        }

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => self::TIMEOUT,
            // Return the body for 4xx/5xx too, so a wrong status is diagnosable
            // rather than indistinguishable from a dead connection.
            'ignore_errors' => true,
            'follow_location' => 0,
            // NO User-Agent of our own here, deliberately. The probe exists to
            // predict whether the REAL server-side consumers (ImageServer,
            // IiifServer, the transcript parser) can fetch a media URL, and they
            // send whatever PHP's `user_agent` ini says — nothing at all on a stock
            // install. A probe that announced itself politely passed on production
            // while every real fetch was refused by the CDN's "no User-Agent" rule.
            // Sending exactly what they send is the only honest test.
        ]]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            // No response at all: refused, timed out, DNS failure, or TLS refused.
            $error = error_get_last();
            $detail = isset($error['message']) ? $this->tidy($error['message']) : '';
            $isTls = $parts['scheme'] === 'https'
                && $detail !== ''
                && preg_match('~ssl|certificate|tls~i', $detail);

            return $this->result(
                false,
                $isTls ? 'tls-failure' : 'unreachable',
                $url,
                $isTls
                    ? sprintf('The server could not complete an HTTPS connection to itself at %s. This is usually a certificate the server does not trust, or an empty CA trust store. %s', $url, $detail) // @translate
                    : sprintf('The server could not connect to %s. It may be a port the server does not listen on, a DNS name that resolves differently inside the network, or a firewall rule preventing the server from calling its own public address. In some cases this is due to a Dockerized installation not serving Omeka from the standard web port (:80), as in a customized local dev setup with non-standard ports.   %s', $url, $detail) // @translate
            );
        }

        $status = $this->statusFromHeaders($http_response_header ?? []);

        if ($status !== 200) {
            return $this->result(false, 'unexpected-status', $url, sprintf(
                'The server reached %s but received HTTP %s instead of 200. Something other than Omeka may be answering that address, or a proxy may be intercepting it.', // @translate
                $url,
                $status === null ? '(no status)' : $status
            ));
        }

        if (trim($body) !== self::TOKEN) {
            return $this->result(false, 'unexpected-body', $url, sprintf(
                'The server reached %s and got a 200, but not from this Omeka installation — the response did not match. A proxy or another site is likely answering that address.', // @translate
                $url
            ));
        }

        return $this->checkMedia($baseUri, $url);
    }

    /**
     * Step 2: fetch a real media file the way the server-side consumers do.
     *
     * Only the response status is wanted, so the body is opened and closed
     * without reading it. A 404 means that sample's file is missing from storage
     * (some collections have a few), which says nothing about access, so the next
     * sample is tried. Any other refusal is reported, with the responding server
     * named when it says who it is: "CloudFront" points straight at the CDN.
     */
    private function checkMedia(string $baseUri, string $tokenUrl): array
    {
        $paths = $this->sampleMediaPaths();
        if (!$paths) {
            return $this->result(true, 'ok', $tokenUrl, sprintf(
                'The server reached %s successfully. Access to media files was not tested, because there is no media file to test with yet.', // @translate
                $tokenUrl
            ));
        }

        foreach ($paths as $path) {
            $mediaUrl = $baseUri . '/' . $path;
            [$status, $server, $error] = $this->fetchStatus($mediaUrl);

            if ($status === 200 || $status === 206) {
                continue;
            }
            if ($status === 404) {
                // Missing from storage: inconclusive about access. Try the next.
                continue;
            }
            if ($status === null) {
                return $this->result(false, 'media-unreachable', $mediaUrl, sprintf(
                    'The server reached %s, but could not connect when it requested a media file, %s. %s', // @translate
                    $tokenUrl,
                    $mediaUrl,
                    $error
                ), 'Media URL proxying cannot work here: this server can reach its own web address, but not its own media files. Features that read media on the server, such as the IIIF image server, image dimensions and transcripts, would fail.'); // @translate
            }
            return $this->result(false, 'media-refused', $mediaUrl, sprintf(
                'The server reached %s, but its request for a media file, %s, was refused with HTTP %d%s. A firewall or bot-blocking rule in front of the site, such as a CDN or WAF rule on /files/, is probably blocking the server itself. Allow this server\'s own address in that rule.', // @translate
                $tokenUrl,
                $mediaUrl,
                $status,
                $server !== '' ? sprintf(' by "%s"', $server) : ''
            ), 'Media URL proxying cannot work here: this server can reach its own web address, but its requests for media files are refused. Features that read media on the server, such as the IIIF image server, image dimensions and transcripts, fail while this lasts.'); // @translate
        }

        return $this->result(true, 'ok', $tokenUrl, sprintf(
            'The server reached %s and fetched media files through it, as the image server and other server-side readers do.', // @translate
            $tokenUrl
        ));
    }

    /**
     * Storage paths to test: originals of a few small public files, plus the
     * large derivative of the first, since consumers read both. Small first, so
     * the probe moves as few bytes as possible; images first, since they are
     * what the image server fetches.
     *
     * @return string[] Paths relative to the base URI, e.g. "original/ab12….jpg".
     */
    private function sampleMediaPaths(): array
    {
        if (!$this->connection) {
            return [];
        }
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT m.storage_id, m.extension, m.has_thumbnails'
                . ' FROM media m JOIN resource r ON r.id = m.id'
                . ' WHERE m.has_original = 1 AND m.size > 0 AND r.is_public = 1'
                . " AND m.storage_id IS NOT NULL AND m.storage_id <> ''"
                . " ORDER BY (m.media_type LIKE 'image/%') DESC, m.size ASC"
                . ' LIMIT ' . self::MEDIA_SAMPLES
            );
        } catch (\Throwable $e) {
            return [];
        }

        $paths = [];
        foreach ($rows as $row) {
            $extension = (string) $row['extension'];
            $paths[] = 'original/' . $row['storage_id'] . ($extension !== '' ? '.' . $extension : '');
        }
        foreach ($rows as $row) {
            if (!empty($row['has_thumbnails'])) {
                // Derivatives are always JPEG, whatever the original.
                $paths[] = 'large/' . $row['storage_id'] . '.jpg';
                break;
            }
        }
        return $paths;
    }

    /**
     * @return array{0: ?int, 1: string, 2: string} Status (null = no response),
     *   the Server header if any, and a tidied connection error.
     */
    private function fetchStatus(string $url): array
    {
        // Same request shape as the consumers: GET, PHP's own User-Agent, no
        // redirects followed (a redirect breaks ImageServer, which reads only the
        // first status line).
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => self::TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            $error = error_get_last();
            return [null, '', isset($error['message']) ? $this->tidy($error['message']) : ''];
        }
        $meta = stream_get_meta_data($handle);
        fclose($handle);

        $headers = (array) ($meta['wrapper_data'] ?? []);
        $server = '';
        foreach ($headers as $header) {
            if (preg_match('~^Server:\s*(.+)$~i', (string) $header, $m)) {
                $server = trim($m[1]);
            }
        }
        return [$this->statusFromHeaders($headers), $server, ''];
    }

    private function statusFromHeaders(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string) $header, $m)) {
                // Last status line wins if anything redirected.
                $status = (int) $m[1];
            }
        }
        return $status ?? null;
    }

    /** PHP's stream errors are prefixed and noisy; keep the informative tail. */
    private function tidy(string $message): string
    {
        $message = preg_replace('~^(file_get_contents|fopen)\([^)]*\):\s*~', '', $message);
        return trim((string) $message);
    }

    private function result(bool $ok, string $reason, string $url, string $message, ?string $summary = null): array
    {
        // A plain-language summary that LEADS the display, before the technical
        // message. On failure it states explicitly that the feature cannot be used,
        // and reassures that nothing on the site changed — so an admin who never
        // reads the technical line still learns the two things that matter.
        $summary = $summary ?? ($ok
            ? 'Media URL proxying is available: this server can reach its own web address.' // @translate
            : 'Media URL proxying cannot be used on this installation, so it has been left off. The feature needs a web address this server can reach from the inside, and that check did not pass. Your site is not affected — media files still load directly from your file store, exactly as before.'); // @translate

        return ['ok' => $ok, 'reason' => $reason, 'summary' => $summary, 'message' => $message, 'url' => $url];
    }
}
