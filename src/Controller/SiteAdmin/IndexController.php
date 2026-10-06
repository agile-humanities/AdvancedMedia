<?php
namespace AdvancedMedia\Controller\SiteAdmin;

use AdvancedMedia\Config\ConfigOptions;
use AdvancedMedia\Config\ConfigWriter;
use Omeka\Settings\SiteSettings;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * The PER-SITE media configuration page — alongside Pages / Navigation / Resources in
 * the site admin sidebar.
 *
 * A dedicated page, NOT a section of core's site-settings form. That was a deliberate
 * switch (docs/config-ui-spec.md §2.3):
 *
 *   - It is what a site admin expects: how media displays is a first-class concern
 *     here, not a footnote buried in a long settings page.
 *   - It renders the SAME partial as the global page, so both scopes look and behave
 *     alike — including the empty-row warning and the override summary, which core's
 *     fieldset-flattening form renderer could never have displayed.
 *   - It removes us as a guest from core's site-settings form, and with it the
 *     sparse-save trap: core's unconditional
 *         foreach ($form->getData() as $id => $value) { $siteSettings->set($id, $value); }
 *     no longer touches our keys at all. We own the write.
 *
 * The prune and the layer-2 read immunity remain: they still guard our own writes, and
 * anything another module (or a human with a SQL client) does to these keys behind our
 * back.
 */
class IndexController extends AbstractActionController
{
    /** @var ConfigOptions */
    private $options;

    /** @var ConfigWriter */
    private $writer;

    /** @var SiteSettings */
    private $siteSettings;

    public function __construct(ConfigOptions $options, ConfigWriter $writer, SiteSettings $siteSettings)
    {
        $this->options = $options;
        $this->writer = $writer;
        $this->siteSettings = $siteSettings;
    }

    public function indexAction()
    {
        $site = $this->currentSite();
        $this->siteSettings->setTargetId($site->id());

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();

            if (!empty($post['advancedmedia_clear_empty'])) {
                // Only ever clears rows the UI flagged as EMPTY. Because layer 2 makes
                // an empty row inert, this cannot change how anything renders — which
                // is why the UI is able to promise that clearing is safe.
                $anomalies = $this->options->build('site')['anomalies'];
                $this->writer->clearRows($anomalies['empty'], $this->siteSettings);
                $this->messenger()->addSuccess('Empty rows cleared.'); // @translate
            } else {
                $this->writer->saveSite($post, $this->siteSettings);
                $this->messenger()->addSuccess('Media settings updated.'); // @translate
            }

            return $this->redirect()->toRoute(
                'admin/site/slug/advanced-media',
                ['site-slug' => $site->slug()]
            );
        }

        $view = new ViewModel;
        $view->setVariable('site', $site);
        $view->setVariable('options', $this->options->build('site'));
        return $view;
    }
}
