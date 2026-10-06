<?php
namespace AdvancedMedia\Controller;

use AdvancedMedia\File\ReachabilityProbe;
use AdvancedMedia\File\ServeMode;
use AdvancedMedia\File\Store\ProxyingAwsS3;
use Doctrine\DBAL\Connection;
use Laminas\Mvc\Controller\AbstractActionController;

/**
 * Answers the /files/* URLs that ProxyingAwsS3 hands out.
 *
 * HOW A REQUEST GETS HERE
 * -----------------------
 * It is not routed here in preference to the filesystem — Omeka's stock .htaccess
 * serves any path that EXISTS on disk and rewrites everything else to index.php.
 * So local files (the IIIF cache, theme assets, legacy uploads under files/) keep
 * being served by Apache as static files, and only paths with no file behind them
 * reach this controller. That is the try_files pattern, already in place, for free.
 *
 * WHY 200, NOT 302 (this replaced an earlier redirect)
 * ----------------------------------------------------
 * The obvious cheap answer is to 302 the client to the bucket. We do not, for two
 * reasons that both matter:
 *
 *   1. A 302 hands out the real S3 URL, so a bot can note it and hammer S3
 *      directly — bypassing the very network rules this feature exists to enforce.
 *      The point of routing media through this host is a single controllable
 *      chokepoint; a redirect punches a hole straight through it.
 *   2. ImageServer fetches the media source SERVER-SIDE and checks only that the
 *      FIRST status line is "200 OK" (AbstractImager::_loadImageResource). A 302
 *      fails that check, so tiling 500s even though the file is perfectly
 *      fetchable. This is exactly the bug that sent us here.
 *
 * So both actions answer with a direct 200, by one of two serve modes:
 *
 *   - MODE_XACCEL: emit `X-Accel-Redirect: /__advancedmedia-s3/<path>` and let the
 *     front nginx proxy the bytes from the bucket. Zero copy through PHP, and the
 *     S3 URL never reaches the client. Requires the nginx snippet (config page).
 *   - MODE_STREAM: pump the S3 object through PHP in chunks. Works everywhere with
 *     no server config — the universal fallback — but it does occupy a worker for
 *     the transfer, so it is the slower path we degrade to, never the goal.
 *
 * The mode is DETECTED (ServeModeDetector) and cached; default is stream, so an
 * unconfigured or misconfigured nginx degrades to "slower", never to "broken".
 */
class FileController extends AbstractActionController
{
    /** Bytes per chunk when streaming. Big enough to be efficient, small enough
     *  that a 40 MB object never sits in memory. */
    const STREAM_CHUNK = 262144; // 256 KiB

    /** @var Connection */
    private $connection;

    /**
     * @var mixed The configured file store. Typed loosely on purpose: the module
     * may be enabled on an install with no AmazonS3 at all, in which case this is
     * Omeka's Local store and every action here 404s.
     */
    private $store;

    /** @var \Omeka\Settings\Settings */
    private $settings;

    public function __construct(Connection $connection, $store, $settings)
    {
        $this->connection = $connection;
        $this->store = $store;
        $this->settings = $settings;
    }

    /**
     * The reachability probe target: /files/__advancedmedia-probe
     *
     * DELIBERATELY NOT gated on the proxy opt-in — its entire job is to answer
     * BEFORE the opt-in is switched on, so an admin can find out whether their
     * deployment supports proxying instead of discovering it from a 500 in a
     * different module.
     *
     * Returns a fixed token and nothing else: no version, no path, no
     * configuration. Matching the token is what distinguishes "this Omeka
     * answered" from "something on that address answered".
     */
    public function probeAction()
    {
        $response = $this->getResponse();
        $response->setStatusCode(200);
        $response->getHeaders()
            ->addHeaderLine('Content-Type', 'text/plain')
            ->addHeaderLine('Cache-Control', 'no-store');
        $response->setContent(ReachabilityProbe::TOKEN);
        return $response;
    }

    /**
     * The X-Accel detection target: /files/__advancedmedia-xaccel-probe
     *
     * Emits an X-Accel-Redirect to the internal check location and an empty 200.
     * If nginx is wired up, it swallows the header and returns XACCEL_TOKEN; if
     * nothing handles it, the header leaks through and the body stays empty. That
     * difference is exactly what ServeModeDetector reads. Ungated, like the
     * reachability probe — its job is to run before anything is committed to.
     */
    public function xaccelProbeAction()
    {
        $response = $this->getResponse();
        $response->setStatusCode(200);
        $response->getHeaders()
            ->addHeaderLine('X-Accel-Redirect', ServeMode::XACCEL_CHECK_PATH)
            ->addHeaderLine('Cache-Control', 'no-store');
        return $response;
    }

    /** Inline: /files/{storagePath} */
    public function fileAction()
    {
        $path = $this->validPath();
        if ($path === null) {
            return $this->notFound();
        }

        $store = $this->proxyingStore();
        if (!$store) {
            return $this->notFound();
        }

        return $this->serve($store, $path, null);
    }

    /** Attachment: /files/download/{storagePath} */
    public function downloadAction()
    {
        $path = $this->validPath();
        if ($path === null) {
            return $this->notFound();
        }

        // NOT gated on the proxy opt-in — see AdvancedMediaDownloadUrl. The
        // Downloads block emits these URLs on any install, so this route has to
        // answer them on any install. All it needs is an S3-backed store.
        $store = $this->store;
        if (!$store instanceof ProxyingAwsS3) {
            return $this->notFound();
        }

        return $this->serve($store, $path, $this->downloadFilename($path));
    }

    /**
     * Serve a storage path as a direct 200, by the detected mode.
     *
     * @param string|null $downloadName When set, attach a Content-Disposition so
     *   the browser saves the file under that name; when null, serve inline.
     */
    private function serve(ProxyingAwsS3 $store, string $path, ?string $downloadName)
    {
        $mode = (string) $this->settings->get(
            ServeMode::SERVE_MODE_KEY,
            ServeMode::MODE_STREAM
        );

        if ($mode === ServeMode::MODE_XACCEL) {
            return $this->serveXAccel($store, $path, $downloadName);
        }
        return $this->serveStream($store, $path, $downloadName);
    }

    /**
     * Hand nginx an X-Accel-Redirect and let it proxy the bytes. PHP returns
     * headers only; nginx serves the body from the internal S3-proxy location and
     * keeps any headers we set here (so a download's Content-Disposition sticks).
     */
    private function serveXAccel(ProxyingAwsS3 $store, string $path, ?string $downloadName)
    {
        $response = $this->getResponse();
        $response->setStatusCode(200);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('X-Accel-Redirect', $store->xAccelPath($path));
        $type = $this->contentTypeFor($path);
        if ($type !== null) {
            $headers->addHeaderLine('Content-Type', $type);
        }
        if ($downloadName !== null) {
            $headers->addHeaderLine('Content-Disposition', $store->attachmentDisposition($downloadName));
        }
        return $response;
    }

    /**
     * Pump the S3 object through PHP in chunks. Memory-safe (nothing larger than
     * STREAM_CHUNK is held), and the response is flushed as it goes rather than
     * buffered — the same shape ImageServer itself uses to return derivatives.
     */
    private function serveStream(ProxyingAwsS3 $store, string $path, ?string $downloadName)
    {
        $object = $store->streamObject($path);
        if ($object === null) {
            return $this->notFound();
        }

        $response = $this->getResponse();
        $response->setStatusCode(200);
        $headers = $response->getHeaders();
        $type = $this->contentTypeFor($path) ?? $object['type'];
        if ($type !== null && $type !== '') {
            $headers->addHeaderLine('Content-Type', $type);
        }
        if ($object['length'] !== null) {
            $headers->addHeaderLine('Content-Length', (string) $object['length']);
        }
        if ($downloadName !== null) {
            $headers->addHeaderLine('Content-Disposition', $store->attachmentDisposition($downloadName));
        }

        // Send headers separately, then stream — the same shape ImageServer uses to
        // return large derivatives in this stack. Silence the E_DEPRECATED that
        // Laminas' sendHeaders() emits on this version, drain any output buffers so
        // a big object never accumulates in memory, and empty the response content
        // so the MVC layer does not render a view over the stream.
        $errorReporting = error_reporting();
        error_reporting($errorReporting & ~E_DEPRECATED);
        $response->sendHeaders();
        error_reporting($errorReporting);

        $response->setContent('');
        while (ob_get_level()) {
            ob_end_clean();
        }

        $body = $object['body'];
        while (!$body->eof()) {
            if (connection_status() !== CONNECTION_NORMAL) {
                break;
            }
            echo $body->read(self::STREAM_CHUNK);
            flush();
        }
        $body->close();

        return $response;
    }

    /**
     * The Content-Type to serve $path as, or null when we cannot tell.
     *
     * Two sources, in order: Omeka's record for an original, then the file
     * extension for everything else. The second half exists because of how nginx
     * treats X-Accel-Redirect: the internal location's upstream (the bucket) wins
     * the Content-Type unless the snippet hides it, and `proxy_hide_header` is
     * unconditional — it cannot hide the bucket's type for originals only. So once
     * an install adds that line, EVERY response has to carry a type from here, or
     * thumbnails and tiles would arrive typeless and fall back to nginx's
     * default_type. Derivatives are the easy half: Omeka writes them as JPEG and
     * ImageServer's tiles are a short, known set of extensions.
     */
    private function contentTypeFor(string $path): ?string
    {
        $recorded = $this->recordedMediaType($path);
        if ($recorded !== null) {
            return $recorded;
        }
        return $this->typeFromExtension($path);
    }

    /**
     * A Content-Type from the file extension, for the derivative and tile paths
     * that have no media row of their own. Deliberately small: this covers what
     * Omeka and ImageServer actually put under files/, not the world.
     */
    private function typeFromExtension(string $path): ?string
    {
        static $types = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'jp2' => 'image/jp2',
            'pdf' => 'application/pdf', 'txt' => 'text/plain', 'vtt' => 'text/vtt',
            'xml' => 'application/xml', 'dzi' => 'application/xml',
            'js' => 'application/javascript', 'json' => 'application/json',
            'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav',
            'ogg' => 'audio/ogg', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
            'mov' => 'video/quicktime',
        ];

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return $types[$extension] ?? null;
    }

    /**
     * The media type Omeka recorded for an ORIGINAL file, or null when we have no
     * record of it.
     *
     * WHY WE OVERRIDE THE BUCKET'S OWN CONTENT TYPE. An object's ContentType is
     * metadata written when the file was put there, by whatever put it there —
     * Omeka, a bulk migration, a hand-run aws-cli. It is not under our control and
     * on this collection it is demonstrably wrong for a large minority of files:
     * roughly one PDF in six carries "application/octet-stream". That is not a
     * cosmetic wart. Chrome treats an octet-stream subresource as a DOWNLOAD, so a
     * PDF reader embedded with <object data="...pdf"> makes the browser start
     * saving the file the instant the page loads, with no user action — including
     * 40-180 MB scans. (Safari does not, which is why this looked browser-specific.)
     *
     * Omeka's own media.media_type is the record this application controls and the
     * one every other display decision is already made from, so it wins here too.
     * The lookup is a single hit on a UNIQUE index, against a request that is about
     * to move megabytes; it does not register.
     *
     * ONLY FOR "original/" PATHS. Derivatives (large/, medium/, square/) and tiles
     * are JPEGs generated FROM the original, while the media row's media_type
     * describes the original itself. Applying it to a derivative would label every
     * thumbnail of a PDF "application/pdf" and break thumbnails site-wide. When the
     * prefix is anything else — or there is no media row, as for tiles — we return
     * null and the bucket's own type stands, exactly as before.
     */
    private function recordedMediaType(string $path): ?string
    {
        if (strpos($path, 'original/') !== 0) {
            return null;
        }

        $storageId = (string) preg_replace(
            '~\.[^./]*$~',
            '',
            (string) substr($path, strlen('original/'))
        );
        if ($storageId === '') {
            return null;
        }

        try {
            $mediaType = $this->connection->fetchOne(
                'SELECT media_type FROM media WHERE storage_id = ? LIMIT 1',
                [$storageId]
            );
        } catch (\Exception $e) {
            return null;
        }

        $mediaType = is_string($mediaType) ? trim($mediaType) : '';

        // A media_type with a control character or a newline would let a bad row
        // inject a header; anything that is not a bare type/subtype is not trusted.
        if (!preg_match('~^[\w.+-]+/[\w.+-]+$~', $mediaType)) {
            return null;
        }

        return $mediaType;
    }

    /**
     * The name the file should be saved as.
     *
     * `media.source` holds the name the file was uploaded under, which is what a
     * human expects to see in their downloads folder. The storage path is derived
     * from storage_id and, here, looks like "P_RG057_B069_F01_I01" — meaningful to
     * the archive, not to a visitor. Falls back to the basename when there is no
     * matching media row (derivatives and tiles have none).
     */
    private function downloadFilename(string $path): string
    {
        $basename = basename($path);

        // Strip the derivative prefix ("original/", "large/", …) and the extension
        // to recover the storage_id.
        $withoutPrefix = (string) preg_replace('~^[^/]+/~', '', $path);
        $storageId = (string) preg_replace('~\.[^./]*$~', '', $withoutPrefix);
        if ($storageId === '') {
            return $basename;
        }

        try {
            $source = $this->connection->fetchOne(
                'SELECT source FROM media WHERE storage_id = ? LIMIT 1',
                [$storageId]
            );
        } catch (\Exception $e) {
            return $basename;
        }

        $source = is_string($source) ? trim($source) : '';
        return $source !== '' ? basename($source) : $basename;
    }

    /**
     * The storage path from the route, or null if it is not one we will serve.
     *
     * The path is interpolated into an S3 key, so it is validated rather than
     * merely escaped: no traversal, no absolute paths, no control characters, and
     * no leading dot on any segment.
     */
    private function validPath(): ?string
    {
        $path = (string) $this->params()->fromRoute('path', '');
        $path = ltrim($path, '/');

        if ($path === '' || strlen($path) > 1024) {
            return null;
        }
        if (strpos($path, "\0") !== false || preg_match('~[\x00-\x1f]~', $path)) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || $segment[0] === '.') {
                return null;
            }
        }
        return $path;
    }

    /**
     * The store, but only when it is ours AND the opt-in is on.
     *
     * Used by the INLINE route only. With proxying off, /files/{path} URLs are
     * never generated, so a request for one is either stale or hand-crafted — 404
     * is the honest answer, and it keeps this route from becoming an open S3
     * redirector. The download route is deliberately not gated this way.
     */
    private function proxyingStore(): ?ProxyingAwsS3
    {
        if (!$this->store instanceof ProxyingAwsS3 || !$this->store->isProxyEnabled()) {
            return null;
        }
        return $this->store;
    }

    private function notFound()
    {
        $this->getResponse()->setStatusCode(404);
        return $this->getResponse();
    }
}
