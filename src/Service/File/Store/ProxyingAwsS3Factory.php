<?php
namespace AdvancedMedia\Service\File\Store;

use AdvancedMedia\File\Store\ProxyingAwsS3;
use AmazonS3\File\Store\AwsS3;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Omeka\File\Exception\ConfigException;

/**
 * Builds the proxying S3 store.
 *
 * Deliberately a near-copy of AmazonS3\Service\File\Store\StoreFactory: it must
 * read the SAME settings the same way, because this store replaces that one
 * wholesale. The only additions are the base URI and the opt-in flag.
 */
class ProxyingAwsS3Factory implements FactoryInterface
{
    /** Global opt-in. Off means this store behaves exactly like the stock one. */
    const OPTION_PROXY_FILES = 'advancedmedia_proxy_files';

    /**
     * Optional pin for the base URI.
     *
     * The computed default derives from the current request, which is meaningless
     * in a CLI job (Omeka's own Local store has the same weakness). Anything that
     * generates URLs from a background job — the tiler, IIIF manifest warming —
     * needs this set, or it will bake a localhost URL into stored output.
     */
    const OPTION_BASE_URI = 'advancedmedia_files_base_uri';

    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $settings = $services->get('Omeka\Settings');

        $parameters = [
            'key' => $settings->get(AwsS3::OPTION_AWS_KEY),
            'secretKey' => $settings->get(AwsS3::OPTION_AWS_SECRET_KEY),
            'region' => $settings->get(AwsS3::OPTION_REGION, 'us-east-2'),
            'bucket' => $settings->get(AwsS3::OPTION_BUCKET),
            'expiration' => max(0, (int) $settings->get(AwsS3::OPTION_EXPIRATION, 0)),
        ];

        if (empty($parameters['key']) || empty($parameters['secretKey'])) {
            throw new ConfigException('You must specify your AWS access key and secret key to use the AWS S3 storage adapter.'); // @translate
        }
        if (empty($parameters['bucket'])) {
            throw new ConfigException('You must specify an S3 bucket name to use the AWS S3 storage adapter.'); // @translate
        }

        $baseUri = $this->baseUri($services, $settings);
        $enabled = (bool) $settings->get(self::OPTION_PROXY_FILES, false);

        // A base URI with no host yields "http:///files/…", which is not a URL.
        // Under a real request ServerUrl always has a host, but in an ad-hoc CLI
        // context it has none — and a malformed URL baked into a cached IIIF
        // manifest or a Solr document is far worse than an S3 URL, because it
        // survives long after the process that produced it.
        //
        // So: refuse to proxy rather than emit something broken. Falling back to
        // stock behaviour is always safe; emitting a bad URL never is.
        if ($enabled && !$this->isAbsoluteUri($baseUri)) {
            $services->get('Omeka\Logger')->warn(sprintf(
                '%s: file proxying is enabled but the base URI (%s) has no scheme and host, so it has been disabled for this process. Set the "%s" setting to pin it.', // @translate
                ProxyingAwsS3::class,
                $baseUri === '' ? '(empty)' : $baseUri,
                self::OPTION_BASE_URI
            ));
            $enabled = false;
        }

        return new ProxyingAwsS3(
            $services->get('Omeka\Logger'),
            $parameters,
            $baseUri,
            $enabled
        );
    }

    private function isAbsoluteUri(string $uri): bool
    {
        $parts = parse_url($uri);
        return is_array($parts) && !empty($parts['scheme']) && !empty($parts['host']);
    }

    /**
     * Resolve the base URI the same way Omeka resolves it for the Local store
     * (Omeka\Service\File\Store\LocalFactory), so the two produce identical URLs:
     * explicit setting → file_store.local.base_uri → serverUrl(basePath('files')).
     */
    private function baseUri(ContainerInterface $services, $settings): string
    {
        $pinned = (string) $settings->get(self::OPTION_BASE_URI, '');
        if ($pinned !== '') {
            return $pinned;
        }

        $config = $services->get('Config');
        $configured = $config['file_store']['local']['base_uri'] ?? null;
        if ($configured) {
            return (string) $configured;
        }

        $helpers = $services->get('ViewHelperManager');
        return (string) $helpers->get('ServerUrl')->__invoke($helpers->get('BasePath')->__invoke('files'));
    }
}
