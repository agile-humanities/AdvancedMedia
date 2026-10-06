<?php
namespace AdvancedMedia\View\Helper;

use AdvancedMedia\File\Store\ProxyingAwsS3;
use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Helper\AbstractHelper;

/**
 * The URL a download button should point at — `advancedMediaDownloadUrl($media)`.
 *
 * Not a string transformation of originalUrl(): the store knows how it builds
 * URLs, and deriving one URL from another by pattern-matching is exactly the
 * fragility that makes third-party code break when the store changes shape.
 */
class AdvancedMediaDownloadUrl extends AbstractHelper
{
    /** @var mixed The configured file store. */
    private $store;

    public function __construct($store)
    {
        $this->store = $store;
    }

    public function __invoke(MediaRepresentation $media): ?string
    {
        if (!$media->hasOriginal()) {
            return null;
        }

        // DELIBERATELY NOT gated on isProxyEnabled().
        //
        // Downloads and media routing are separate features with separate needs.
        // Routing all media through this host is an opt-in that depends on the
        // deployment (the base URI has to be reachable from the server itself).
        // A download button has no such dependency: it only needs a same-origin
        // URL so the browser will save rather than navigate, and that is exactly
        // what the /files/download route gives us — on any install, proxying on
        // or off.
        //
        // Coupling the two, as this originally did, meant downloads silently did
        // not download unless an unrelated advanced setting happened to be on.
        if ($this->store instanceof ProxyingAwsS3 && $this->store->hasUsableBaseUri()) {
            // Same storage path Omeka itself would build for the original
            // (AbstractResourceRepresentation::getFileUrl('original', filename())),
            // moved into the /files/download/ space.
            return $this->store->getDownloadRouteUri('original/' . $media->filename());
        }

        // Local storage (already same-origin, so the template's `download`
        // attribute does the work), or an S3 store we cannot build a URL for.
        return $media->originalUrl();
    }
}
