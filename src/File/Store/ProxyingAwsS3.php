<?php
namespace AdvancedMedia\File\Store;

use AdvancedMedia\File\ServeMode;
use AmazonS3\File\Store\AwsS3;
use Laminas\Log\Logger;

/**
 * The AmazonS3 store, with ONE behaviour changed: getUri() hands out a URL on
 * THIS host, in Omeka's own canonical shape, instead of a bucket URL.
 *
 *     before   https://s3.us-west-2.amazonaws.com/omekacloud-dev/original/P_RG057…pdf
 *     after    https://example.org/files/original/P_RG057…pdf
 *
 * WHY A SUBCLASS RATHER THAN A WRAPPER
 * ------------------------------------
 * Callers depend on far more than StoreInterface — ImageServer and ArchiveRepertory
 * reach for getClient(), hasFile(), deleteDir(), move() and the stream-wrapper path.
 * A wrapper would have to re-expose all of it and would still fail an `instanceof
 * AwsS3` check. Extending inherits the whole surface and stays type-compatible, so
 * only the one method we actually want to change is written here.
 *
 * WHY THE *CANONICAL* SHAPE, NOT A ROUTE OF OUR OWN
 * -------------------------------------------------
 * `{baseUri}/{storagePath}` is exactly what Omeka\File\Store\Local produces. Emitting
 * anything else would be a second URL vocabulary that the rest of the ecosystem does
 * not know about. Matching Local means:
 *
 *   - No query string, so the several places that string-append to a store URL keep
 *     working (IiifManifest2's `originalUrl() . '/element/e0'`, ImageServer's
 *     TileInfoFactory splitting on '?').
 *   - Code that HARDCODES '/files' — IiifServer's alto link, IiifManifest2's
 *     derivative base — starts producing correct URLs instead of broken ones.
 *   - Network/WAF rules are written once and hold for local and S3 installs alike.
 *   - Third-party modules keep working by assumption rather than by patch.
 *
 * The whole point of this class is that downstream code should not be able to tell
 * the difference between this install and a local-storage one.
 *
 * SERVING: FileController answers the resulting /files/… URL with a direct 200,
 * either by handing nginx an X-Accel-Redirect to an internal S3-proxy location
 * (zero-copy) or by streaming the S3 object through PHP (the universal fallback).
 * It deliberately does NOT 302 to the bucket: a redirect leaks the S3 URL (so a
 * bot can bypass the network rules this feature exists to enforce) and, more
 * immediately, ImageServer's source fetch rejects any first status line that is
 * not "200 OK", so a 302 makes tiling 500. See FileController.
 */
class ProxyingAwsS3 extends AwsS3
{
    // Serve-mode constants: defined on File\ServeMode, which has no AmazonS3
    // dependency and is what code outside this class should read. Aliased here for
    // callers that already hold a store.
    const XACCEL_PREFIX = ServeMode::XACCEL_PREFIX;
    const XACCEL_CHECK_PATH = ServeMode::XACCEL_CHECK_PATH;
    const XACCEL_TOKEN = ServeMode::XACCEL_TOKEN;
    const SERVE_MODE_KEY = ServeMode::SERVE_MODE_KEY;
    const MODE_XACCEL = ServeMode::MODE_XACCEL;
    const MODE_STREAM = ServeMode::MODE_STREAM;

    /**
     * Presign TTL for download URLs, in minutes.
     *
     * Independent of `amazons3_expiration`: that setting governs whether NORMAL
     * urls are presigned (it is 0 here — objects are public-read), whereas a
     * download url must be presigned regardless, because attaching a
     * Content-Disposition is only possible on a signed request.
     */
    const DOWNLOAD_TTL_MINUTES = 10;

    /** @var string Base URI, no trailing slash. Mirrors Local's $baseUri. */
    private $baseUri;

    /** @var bool When false this behaves exactly like the stock AwsS3 store. */
    private $proxyEnabled;

    public function __construct(Logger $logger, array $parameters, string $baseUri, bool $proxyEnabled)
    {
        parent::__construct($logger, $parameters);
        $this->baseUri = rtrim($baseUri, '/');
        $this->proxyEnabled = $proxyEnabled;
    }

    /**
     * Can we build a usable absolute URL in the /files space?
     *
     * Independent of $proxyEnabled. The DOWNLOAD route depends on this and not
     * on the proxy flag: a download link needs a same-origin URL whether or not
     * an admin has opted into routing *all* media through this host.
     */
    public function hasUsableBaseUri(): bool
    {
        $parts = parse_url($this->baseUri);
        return is_array($parts) && !empty($parts['scheme']) && !empty($parts['host']);
    }

    /**
     * The one overridden method.
     *
     * Deliberately formatted with the same sprintf as Local::getUri() — including
     * the absence of any URL-encoding — because the goal is byte-identical output
     * to what a local install would produce for the same storage path.
     */
    public function getUri($path)
    {
        if (!$this->proxyEnabled) {
            return parent::getUri($path);
        }
        return sprintf('%s/%s', $this->baseUri, $path);
    }

    /**
     * The sibling /files/download/… URL for a storage path.
     *
     * A path segment rather than a query string, for two reasons: the rest of the
     * /files space is query-free and several third-party call sites append to
     * these URLs, and a distinct path prefix is exactly what network policy needs
     * in order to treat "harvesting originals" differently from ordinary page
     * rendering.
     */
    public function getDownloadRouteUri($path)
    {
        return sprintf('%s/download/%s', $this->baseUri, $path);
    }

    /**
     * The real bucket URL, for callers that must reach S3 rather than us.
     *
     * FileController uses this to redirect. Anything doing a SERVER-SIDE fetch
     * should use it too, so the request does not loop back through our own
     * webserver — see the note on self-fetching in FileController.
     */
    public function getRemoteUri($path)
    {
        return parent::getUri($path);
    }

    /**
     * Turn a public /files/… URL back into one THIS SERVER can actually fetch.
     *
     * WHY THIS EXISTS
     * ---------------
     * getUri() is browser-facing. Some code takes that URL and fetches it
     * SERVER-SIDE over HTTP — ImageServer loading a source image to transform,
     * ImageServer reading DZI metadata, our own VttParser reading a transcript.
     * For those callers the public URL is the wrong answer, and often an
     * unreachable one: the public host may be a reverse proxy, a TLS terminator,
     * or (as in this Docker stack) a host-side port mapping that does not exist
     * inside the container. The failure is not a slow self-request — the
     * connection simply never establishes.
     *
     * This was the flaw in the original "downstream code cannot tell the
     * difference from a local install" premise. On a LOCAL install, server-side
     * code reads a filesystem PATH. Only on S3 does it read a URL, and that URL
     * has to be reachable from the server rather than from the browser.
     *
     * Idempotent and safe on any input: a URL that is not one of ours is returned
     * untouched, so callers can pipe everything through it.
     */
    public function toFetchableUri($url)
    {
        if (!$this->proxyEnabled || !is_string($url) || $url === '') {
            return $url;
        }

        $prefix = $this->baseUri . '/';
        if (strncmp($url, $prefix, strlen($prefix)) !== 0) {
            return $url;
        }

        $path = substr($url, strlen($prefix));
        // The download sibling resolves to the same object.
        if (strncmp($path, 'download/', 9) === 0) {
            $path = substr($path, 9);
        }
        if ($path === '') {
            return $url;
        }

        return $this->getRemoteUri($path);
    }

    /**
     * The X-Accel-Redirect header VALUE for a storage path.
     *
     * nginx receives e.g. `/__advancedmedia-s3/original/foo.tif`, strips the
     * prefix, and proxies `original/foo.tif` to the bucket. The path is emitted
     * verbatim (already validated by the controller), matching how getUri() builds
     * the public URL — no encoding, same as Local.
     */
    public function xAccelPath($path): string
    {
        return self::XACCEL_PREFIX . ltrim((string) $path, '/');
    }

    /**
     * Open the S3 object as a stream, with the metadata the controller needs to
     * set response headers. The Body is lazy (`@http stream`), so nothing is read
     * into memory until the controller pumps it out in chunks.
     *
     * @return array{body: \Psr\Http\Message\StreamInterface, type: ?string, length: ?int}|null
     *   Null when the object cannot be opened (missing, permission, network).
     */
    public function streamObject($path): ?array
    {
        try {
            $result = $this->getClient()->getObject([
                'Bucket' => $this->getBucketName(),
                'Key' => (string) $path,
                '@http' => ['stream' => true],
            ]);
        } catch (\Throwable $e) {
            $this->getLogger()->err(sprintf(
                '%s: could not open S3 object "%s" for streaming. %s',
                self::class, $path, $e->getMessage()
            ));
            return null;
        }

        $length = $result['ContentLength'] ?? null;
        return [
            'body' => $result['Body'],
            'type' => $result['ContentType'] ?? null,
            'length' => $length === null ? null : (int) $length,
        ];
    }

    /**
     * A presigned URL that makes the browser save the file under $filename.
     *
     * S3 will not send Content-Disposition for an ordinary object GET, so a plain
     * redirect to the bucket renders the PDF in the tab instead of downloading it —
     * the same failure as the `download` attribute on a cross-origin link, just
     * moved. ResponseContentDisposition on a SIGNED request is what actually fixes
     * it, and the short TTL is a bonus: a harvested download URL goes stale.
     *
     * Note the objects are currently stored `public-read` (AwsS3::put), so signing
     * adds no access control here — it is purely the mechanism for the header.
     */
    public function getDownloadUri($path, $filename)
    {
        $filename = $this->sanitizeFilename($filename);

        $cmd = $this->getClient()->getCommand('GetObject', [
            // NOT urlencode()d, unlike the parent's getUri(): this value is an SDK
            // parameter, not a URL component, and the SDK does its own encoding.
            'Bucket' => $this->getBucketName(),
            'Key' => $path,
            'ResponseContentDisposition' => sprintf(
                // The plain `filename=` is the fallback for old clients; RFC 5987
                // `filename*=` carries anything non-ASCII correctly.
                'attachment; filename="%s"; filename*=UTF-8\'\'%s',
                str_replace('"', '', $filename),
                rawurlencode($filename)
            ),
        ]);

        $request = $this->getClient()->createPresignedRequest(
            $cmd,
            sprintf('+%d minutes', self::DOWNLOAD_TTL_MINUTES)
        );

        return (string) $request->getUri();
    }

    public function isProxyEnabled(): bool
    {
        return $this->proxyEnabled;
    }

    /** The resolved base URI, so the config page can probe and display it. */
    public function baseUri(): string
    {
        return $this->baseUri;
    }

    /** The bucket name, so the config page can build the nginx snippet. */
    public function bucket(): string
    {
        return (string) $this->getBucketName();
    }

    /**
     * The S3 endpoint, e.g. https://s3.us-west-2.amazonaws.com — what the nginx
     * internal location proxy_passes to (path-style: endpoint/bucket/key).
     */
    public function s3Endpoint(): string
    {
        return rtrim((string) $this->getClient()->getEndpoint(), '/');
    }

    /**
     * A `Content-Disposition: attachment` value that makes the browser save the
     * file under $filename. Used when serving downloads via X-Accel or streaming
     * — where WE set the header — rather than via a presigned S3 URL.
     */
    public function attachmentDisposition($filename): string
    {
        $filename = $this->sanitizeFilename($filename);
        return sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            str_replace('"', '', $filename),
            rawurlencode($filename)
        );
    }

    /** Strip path separators and control characters; never trust a stored name. */
    private function sanitizeFilename($filename): string
    {
        $filename = (string) $filename;
        $filename = str_replace(['/', '\\', "\r", "\n", "\0"], '', $filename);
        $filename = trim($filename);
        return $filename !== '' ? $filename : 'download';
    }
}
