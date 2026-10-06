<?php
namespace AdvancedMedia\File;

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
            'header' => "User-Agent: AdvancedMedia-ReachabilityProbe\r\n",
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

        return $this->result(true, 'ok', $url, sprintf(
            'The server reached %s successfully.', // @translate
            $url
        ));
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
        $message = preg_replace('~^file_get_contents\([^)]*\):\s*~', '', $message);
        return trim((string) $message);
    }

    private function result(bool $ok, string $reason, string $url, string $message): array
    {
        // A plain-language summary that LEADS the display, before the technical
        // message. On failure it states explicitly that the feature cannot be used,
        // and reassures that nothing on the site changed — so an admin who never
        // reads the technical line still learns the two things that matter.
        $summary = $ok
            ? 'Media URL proxying is available: this server can reach its own web address.' // @translate
            : 'Media URL proxying cannot be used on this installation, so it has been left off. The feature needs a web address this server can reach from the inside, and that check did not pass. Your site is not affected — media files still load directly from your file store, exactly as before.'; // @translate

        return ['ok' => $ok, 'reason' => $reason, 'summary' => $summary, 'message' => $message, 'url' => $url];
    }
}
