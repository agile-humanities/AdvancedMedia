<?php
namespace AdvancedMedia\File;

/**
 * Decides HOW FileController should serve /files/* bytes: via nginx
 * X-Accel-Redirect (zero-copy) or by streaming through PHP (the universal
 * fallback). Detection is by behaviour, not by reading anyone's config.
 *
 * HOW IT WORKS
 * ------------
 * FileController::xaccelProbeAction() answers /files/__advancedmedia-xaccel-probe
 * with an `X-Accel-Redirect` header pointing at an internal nginx location and an
 * EMPTY body. If nginx is wired up (the snippet from the config page is installed),
 * nginx intercepts the header and returns ServeMode::XACCEL_TOKEN. If nothing
 * is handling X-Accel-Redirect — no nginx, or the snippet is not installed — the
 * header leaks through to us and the body is empty. So: token in the body means
 * X-Accel works; anything else means it does not.
 *
 * WHY THE FALLBACK IS THE DEFAULT
 * -------------------------------
 * Detection can be wrong or go stale (nginx reconfigured between config-page
 * visits). Streaming works everywhere, so defaulting to it means a wrong guess
 * degrades to "slower", never to "broken" — which, given the 500 that sent us
 * here, is the failure mode that matters.
 *
 * Runs on every global-config load (Module::refreshProbe), same cadence as the
 * reachability probe, and caches its verdict in ServeMode::SERVE_MODE_KEY.
 */
class ServeModeDetector
{
    const PROBE_PATH = '__advancedmedia-xaccel-probe';

    /** Seconds. A loopback answers in milliseconds; this only bounds a hang. */
    const TIMEOUT = 5;

    /**
     * @return array{mode: string, detail: string}
     *   `mode` is one of ServeMode::MODE_*; `detail` is a short line for the
     *   config page and the log.
     */
    public function detect(string $baseUri): array
    {
        $baseUri = rtrim($baseUri, '/');
        $url = $baseUri . '/' . self::PROBE_PATH;

        $parts = parse_url($baseUri);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            // No usable base URI to probe. Streaming needs no server config, so it
            // is the safe assumption.
            return $this->result(ServeMode::MODE_STREAM,
                'No usable base URI to test, so streaming is assumed.'); // @translate
        }

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => self::TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "User-Agent: AdvancedMedia-ServeModeDetector\r\n",
        ]]);

        $body = @file_get_contents($url, false, $context);

        if ($body !== false && trim($body) === ServeMode::XACCEL_TOKEN) {
            return $this->result(ServeMode::MODE_XACCEL,
                'nginx is handling X-Accel-Redirect, so media is served zero-copy.'); // @translate
        }

        // Reached us but got no token (header leaked, empty body), or could not
        // reach us at all. Either way, do not rely on X-Accel.
        return $this->result(ServeMode::MODE_STREAM,
            'X-Accel-Redirect is not being handled, so media is streamed through PHP. Install the nginx snippet below to enable zero-copy serving.'); // @translate
    }

    private function result(string $mode, string $detail): array
    {
        return ['mode' => $mode, 'detail' => $detail];
    }
}
