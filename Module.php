<?php
namespace AdvancedMedia;

use AdvancedMedia\Config\ConfigOptions;
use AdvancedMedia\Config\ConfigWriter;
use Omeka\Module\AbstractModule;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Advanced Media
 *
 * Display-time media viewer selection and compound-object display.
 * See docs/architecture.md for the design, docs/config-ui-spec.md for this form.
 */
class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * The GLOBAL configuration form.
     *
     * We own this surface outright: Omeka renders whatever HTML we return, inside
     * its own <form> with a CSRF field (application/view/omeka/admin/module/configure.phtml),
     * and hands the raw POST straight back to handleConfigForm(). No Laminas form
     * plumbing is involved, which is why this scope is built first.
     */
    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();

        // Refresh what the config page reports, on every load, so both reflect the
        // CURRENT environment rather than the last save. Cheap: two loopbacks.
        $this->refreshProbe($services);      // can the server reach its own /files?
        $this->detectServeMode($services);   // is nginx handling X-Accel-Redirect?

        $optionsService = $services->get(ConfigOptions::class);

        $options = $optionsService->build('global');
        // Global scope also shows which sites have drifted from these defaults — that
        // is what makes an overridden (or debris-carrying) site findable at all.
        $options['siteOverview'] = $optionsService->siteOverview();

        return $renderer->partial('common/config/advanced-media-config', [
            'options' => $options,
        ]);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $post = $controller->params()->fromPost();
        $writer = $services->get(ConfigWriter::class);

        if (!empty($post['advancedmedia_clear_fetch_failures'])) {
            $services->get(File\FetchDiagnostics::class)->clear();
            return true;
        }

        if (!empty($post['advancedmedia_clear_empty'])) {
            // Only clears rows the UI flagged as EMPTY. Layer 2 makes empty rows inert,
            // so this cannot change how anything renders.
            $anomalies = $services->get(ConfigOptions::class)->build('global')['anomalies'];
            $writer->clearRows($anomalies['empty'], $writer->settings());
            return true;
        }

        // Rows the form showed and the admin left on "inherit" are dropped; rows the
        // form never showed are preserved (config-ui-spec §6.2).
        $writer->saveGlobal($post);

        // Global display policies (plain checkboxes — absent from POST means off).
        // Written unconditionally: this form always renders them, so absence is a
        // real "unchecked" rather than "not shown".
        $settings = $services->get('Omeka\Settings');
        foreach (ConfigWriter::SITE_TRISTATE_KEYS as $key) {
            $settings->set($key, !empty($post[$key]));
        }

        $this->saveProxySettings($services, $controller, $post);

        return true;
    }

    /**
     * Persist the media-proxy opt-in, GATED ON A REACHABILITY PROBE.
     *
     * Switching proxying on rewrites every media URL to point at this host. That
     * only works if the server can reach its own base URI — and when it cannot,
     * the symptoms surface far from the cause (HTTP 500 out of ImageServer, and
     * transcripts that silently render empty). So the precondition is verified
     * here, at the moment of the decision, rather than discovered later.
     *
     * The probe runs only on the OFF → ON transition. Turning it off always
     * succeeds; an already-on setting is not re-probed on every unrelated save.
     */
    private function saveProxySettings($services, AbstractController $controller, array $post): void
    {
        $settings = $services->get('Omeka\Settings');
        $proxyKey = Service\File\Store\ProxyingAwsS3Factory::OPTION_PROXY_FILES;
        $baseUriKey = Service\File\Store\ProxyingAwsS3Factory::OPTION_BASE_URI;

        // The form always submits the base URI field, so an empty one means
        // "unset and compute it" rather than "leave alone".
        if (array_key_exists($baseUriKey, $post)) {
            $pinned = trim((string) $post[$baseUriKey]);
            $pinned === '' ? $settings->delete($baseUriKey) : $settings->set($baseUriKey, $pinned);
        }

        $wanted = !empty($post[$proxyKey]);
        $current = (bool) $settings->get($proxyKey, false);

        if (!$wanted) {
            $settings->set($proxyKey, false);
            if ($current) {
                $controller->messenger()->addSuccess('Media URL proxying is now off. Media URLs point directly at the file store again.'); // @translate
            }
            return;
        }

        if ($current) {
            return;
        }

        // Probe the base URI the store WILL use on the NEXT request, which is not
        // necessarily the one the current store instance holds: that instance was
        // built during bootstrap, before this save, so asking it would test the
        // value the admin has just replaced. Read the pinned setting we wrote a
        // moment ago first, and only fall back to the live store when none is set.
        $pinned = (string) $settings->get($baseUriKey, '');
        if ($pinned !== '') {
            $baseUri = $pinned;
        } else {
            $store = $services->get('Omeka\File\Store');
            $baseUri = $store instanceof File\Store\ProxyingAwsS3 ? $store->baseUri() : '';
        }

        $result = $services->get(File\ReachabilityProbe::class)->check($baseUri);
        $settings->set(File\ReachabilityProbe::LAST_RESULT_KEY, $result);

        if (!$result['ok']) {
            $settings->set($proxyKey, false);
            // Non-technical summary first (it already says the feature cannot be
            // used and stays off), then the technical detail for whoever can act
            // on it.
            $controller->messenger()->addError(sprintf(
                '%s (%s)', // @translate
                $result['summary'],
                $result['message']
            ));
            return;
        }

        $settings->set($proxyKey, true);
        $controller->messenger()->addSuccess('Media URL proxying is on. Media URLs now point at this site.'); // @translate
    }

    /**
     * Run the reachability probe and store the result, so the config page shows
     * current status on load rather than only after a save.
     *
     * No-op when the store is not our proxying one (a local-storage install, where
     * proxying is not applicable and the config panel is not shown anyway). Probes
     * the base URI the store actually holds — which already incorporates any pinned
     * OPTION_BASE_URI, since the factory reads it — so load-time and save-time
     * checks agree.
     */
    private function refreshProbe($services): void
    {
        $store = $services->get('Omeka\File\Store');
        if (!$store instanceof File\Store\ProxyingAwsS3) {
            return;
        }

        $result = $services->get(File\ReachabilityProbe::class)->check($store->baseUri());
        $services->get('Omeka\Settings')->set(File\ReachabilityProbe::LAST_RESULT_KEY, $result);
    }

    /**
     * Detect and cache how FileController should serve /files bytes: nginx
     * X-Accel-Redirect (zero-copy) or PHP streaming. No-op unless the store is
     * ours — proxying, and therefore the serve mode, is meaningless on a local
     * store whose URLs are already same-origin.
     */
    private function detectServeMode($services): void
    {
        $store = $services->get('Omeka\File\Store');
        if (!$store instanceof File\Store\ProxyingAwsS3) {
            return;
        }

        $result = $services->get(File\ServeModeDetector::class)->detect($store->baseUri());
        $services->get('Omeka\Settings')->set(
            File\ServeMode::SERVE_MODE_KEY,
            $result['mode']
        );
    }

    /**
     * The per-site media page is its own admin controller, so it needs an ACL rule.
     * Same privilege as the other per-site admin screens: whoever may update the site.
     *
     * NOTE: we deliberately do NOT attach to Omeka\Form\SiteSettingsForm any more. The
     * per-site config is a first-class page (Controller\SiteAdmin\IndexController), so
     * core's unconditional site-settings save loop never touches our keys — which is
     * the sparse-save trap removed at the source rather than defended against.
     */
    public function onBootstrap(\Laminas\Mvc\MvcEvent $event)
    {
        parent::onBootstrap($event);

        $services = $this->getServiceLocator();

        $acl = $services->get('Omeka\Acl');
        $acl->allow(
            ['editor', 'site_admin', 'global_admin'],
            [Controller\SiteAdmin\IndexController::class]
        );
        // The /files/* routes are public: they stand in for static file serving.
        $acl->allow(null, [Controller\FileController::class]);

        $this->ensureUserAgent();

        // The AmazonS3 store is replaced with our proxying subclass via a
        // 'delegators' entry in module.config.php — order-independent, so nothing
        // here needs to run at bootstrap. See ProxyingAwsS3Delegator for the why.
    }

    /** What server-side fetches identify themselves as when PHP has no user_agent set. */
    const SERVER_FETCH_USER_AGENT = 'Omeka-S (AdvancedMedia server-side fetch)';

    /**
     * Give PHP's own HTTP fetches a User-Agent when the installation has none.
     *
     * ⚠️ PHP SENDS NO User-Agent HEADER AT ALL BY DEFAULT (`user_agent` is empty in
     * a stock php.ini), and that is invisible until something in front of the site
     * objects. With media proxying on, the code that fetches a media file
     * SERVER-SIDE — ImageServer building a derivative or a tile, IiifServer reading
     * dimensions, our own transcript parser — fetches it from this site's PUBLIC
     * address, i.e. back in through the CDN and its firewall. Blocking requests
     * with no User-Agent is a standard bot rule there (AWS WAF's common rule set
     * has it), so every one of those fetches gets a 403.
     *
     * It then fails far from the cause: ImageServer answers each IIIF image with
     * 500 "the resulting file is empty or not found", while the same URL opens
     * perfectly in a browser — which does send a User-Agent. That was production
     * on 2026-10-09.
     *
     * We do not patch the consumers (third-party, and there are several). They all
     * go through PHP's http stream wrapper, which reads this one ini value, so
     * setting it once covers them. An installation that has chosen its own
     * user_agent keeps it.
     */
    private function ensureUserAgent(): void
    {
        if ((string) ini_get('user_agent') === '') {
            @ini_set('user_agent', self::SERVER_FETCH_USER_AGENT);
        }
    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        $settings = $services->get('Omeka\Settings');
        $settings->delete(ConfigOptions::PLAYER_KEY);
        $settings->delete(ConfigOptions::SELECTOR_KEY);
        $settings->delete(ConfigOptions::STRUCTURE_KEY);
        $settings->delete(ConfigOptions::DOWNLOAD_KEY);
        $settings->delete(ConfigOptions::THRESHOLD_KEY);
        $settings->delete(ConfigOptions::PRIMARY_COMPOUND_ONLY_KEY);
        $settings->delete(ConfigOptions::PRIMARY_FIRST_KEY);
        $settings->delete(ConfigOptions::DOWNLOAD_METADATA_KEY);
        $settings->delete(ConfigOptions::DOWNLOAD_LABEL_KEY);
        $settings->delete(Service\File\Store\ProxyingAwsS3Factory::OPTION_PROXY_FILES);
        $settings->delete(Service\File\Store\ProxyingAwsS3Factory::OPTION_BASE_URI);
        $settings->delete(File\ServeMode::SERVE_MODE_KEY);
        $settings->delete(File\ReachabilityProbe::LAST_RESULT_KEY);
    }
}
