<?php
namespace AdvancedMedia\File;

/**
 * The constants that describe HOW /files bytes are served from an S3-backed store.
 *
 * They live here, not on Store\ProxyingAwsS3, because that class extends the
 * AmazonS3 module's store: reading a constant from it autoloads it, and that is a
 * fatal error on an install without AmazonS3. The config page, the uninstaller and
 * the X-Accel probe route all need these values on ANY install, so they must not
 * depend on an optional module being present.
 *
 * ProxyingAwsS3 keeps aliases of the same names for code that already holds a store.
 */
final class ServeMode
{
    /**
     * The internal nginx location prefix an X-Accel-Redirect points at. nginx
     * strips it and proxies the remainder to the bucket. Must match the snippet
     * shown on the config page and in the README.
     */
    const XACCEL_PREFIX = '/__advancedmedia-s3/';

    /**
     * A separate internal location that returns XACCEL_TOKEN, used only to detect
     * whether X-Accel-Redirect is wired up (ServeModeDetector). Kept distinct from
     * the S3-proxy location so detection never depends on a real object existing.
     */
    const XACCEL_CHECK_PATH = '/__advancedmedia-xaccel-check';

    /** Body the check location returns. Fixed; carries nothing about the install. */
    const XACCEL_TOKEN = 'advancedmedia-xaccel-ok';

    /** Setting holding the detected serve mode, one of the MODE_* below. */
    const SERVE_MODE_KEY = 'advancedmedia_serve_mode';

    const MODE_XACCEL = 'xaccel';
    const MODE_STREAM = 'stream';
}
