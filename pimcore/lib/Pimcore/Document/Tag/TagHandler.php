<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Document\Tag;

use Pimcore\Bundle\PimcoreBundle\HttpKernel\BundleLocator\BundleLocatorInterface;
use Pimcore\Bundle\PimcoreBundle\Service\WebPathResolver;
use Pimcore\Bundle\PimcoreBundle\Templating\Model\ViewModel;
use Pimcore\Bundle\PimcoreBundle\Templating\Model\ViewModelInterface;
use Pimcore\Bundle\PimcoreBundle\Templating\Renderer\ActionRenderer;
use Pimcore\Extension\Document\Areabrick\AreabrickInterface;
use Pimcore\Extension\Document\Areabrick\AreabrickManagerInterface;
use Pimcore\Extension\Document\Areabrick\Exception\ConfigurationException;
use Pimcore\Extension\Document\Areabrick\TemplateAreabrickInterface;
use Pimcore\Facade\Translate;
use Pimcore\Logger;
use Pimcore\Model\Document\PageSnippet;
use Pimcore\Model\Document\Tag;
use Pimcore\Model\Document\Tag\Area\Info;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;

class TagHandler implements TagHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var AreabrickManagerInterface
     */
    protected $brickManager;

    /**
     * @var EngineInterface
     */
    protected $templating;

    /**
     * @var BundleLocatorInterface
     */
    protected $bundleLocator;

    /**
     * @var WebPathResolver
     */
    protected $webPathResolver;

    /**
     * @var ActionRenderer
     */
    protected $actionRenderer;

    /**
     * @var array
     */
    protected $brickTemplateCache = [];

    /**
     * @param AreabrickManagerInterface $brickManager
     * @param EngineInterface $templating
     * @param BundleLocatorInterface $bundleLocator
     * @param WebPathResolver $webPathResolver
     * @param ActionRenderer $actionRenderer
     */
    public function __construct(
        AreabrickManagerInterface $brickManager,
        EngineInterface $templating,
        BundleLocatorInterface $bundleLocator,
        WebPathResolver $webPathResolver,
        ActionRenderer $actionRenderer
    ) {
        $this->brickManager    = $brickManager;
        $this->templating      = $templating;
        $this->bundleLocator   = $bundleLocator;
        $this->webPathResolver = $webPathResolver;
        $this->actionRenderer  = $actionRenderer;
    }

    /**
     * {@inheritdoc}
     */
    public function supports($view)
    {
        return $view instanceof ViewModelInterface;
    }

    /**
     * @inheritDoc
     */
    public function isBrickEnabled(Tag\Areablock $tag, $brick)
    {
        return $this->brickManager->isEnabled($brick);
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableAreablockAreas(Tag\Areablock $tag, array $options)
    {
        /** @var ViewModel $view */
        $view = $tag->getView();

        $areas = [];
        foreach ($this->brickManager->getBricks() as $brick) {
            // don't show disabled bricks
            if (!isset($options['dontCheckEnabled']) || !$options['dontCheckEnabled']) {
                if (!$this->brickManager->isEnabled($brick->getId())) {
                    continue;
                }
            }

            if (!(empty($options['allowed']) || in_array($brick->getId(), $options['allowed']))) {
                continue;
            }

            $name = $brick->getName();
            $desc = $brick->getDescription();
            $icon = $brick->getIcon();

            // autoresolve icon as <bundleName>/Resources/public/areas/<id>/icon.png
            if (null === $icon) {
                $bundle = null;
                try {
                    $bundle = $this->bundleLocator->getBundle($brick);

                    // check if file exists
                    $iconPath = sprintf('%s/Resources/public/areas/%s/icon.png', $bundle->getPath(), $brick->getId());
                    if (file_exists($iconPath)) {
                        // build URL to icon
                        $icon = $this->webPathResolver->getPath($bundle, 'areas/' . $brick->getId(), 'icon.png');
                    }
                } catch (\Exception $e) {
                    $iconPath = "";
                    $icon = "";
                }
            }

            if ($view->editmode) {
                $name = Translate::transAdmin($name);
                $desc = Translate::transAdmin($desc);
            }

            $areas[$brick->getId()] = [
                'name'        => $name,
                'description' => $desc,
                'type'        => $brick->getId(),
                'icon'        => $icon,
            ];
        }

        return $areas;
    }

    /**
     * {@inheritdoc}
     */
    public function renderAreaFrontend(Info $info, array $params)
    {
        $tag   = $info->getTag();
        $view  = $tag->getView();
        $brick = $this->brickManager->getBrick($info->getId());

        // assign parameters to view
        $view->getParameters()->add($params);

        // call action
        $brick->action($info);

        if (!$brick->hasViewTemplate()) {
            return;
        }

        $editmode = $view->editmode;

        echo $brick->getHtmlTagOpen($info);

        if ($brick->hasEditTemplate() && $editmode) {
            echo '<div class="pimcore_area_edit_button_' . $tag->getName() . ' pimcore_area_edit_button"></div>';

            // forces the editmode in view independent if there's an edit or not
            if (!array_key_exists('forceEditInView', $params) || !$params['forceEditInView']) {
                $view->editmode = false;
            }
        }

        // render view template
        $viewTemplate = $this->resolveBrickTemplate($brick, 'view');
        echo $this->templating->render(
            $viewTemplate,
            $view->getParameters()->all()
        );

        if ($brick->hasEditTemplate() && $editmode) {
            $view->editmode = true;

            echo '<div class="pimcore_area_editmode_' . $tag->getName() . ' pimcore_area_editmode pimcore_area_editmode_hidden">';

            $editTemplate = $this->resolveBrickTemplate($brick, 'edit');

            // render edit template
            echo $this->templating->render(
                $editTemplate,
                $view->getParameters()->all()
            );

            echo '</div>';
        }

        echo $brick->getHtmlTagClose($info);

        // call post render
        $brick->postRenderAction($info);
    }

    /**
     * Try to get the brick template from get*Template method. If method returns null and brick implements
     * TemplateAreabrickInterface fall back to auto-resolving the template reference. See interface for examples.
     *
     * @param AreabrickInterface $brick
     * @param $type
     *
     * @return mixed|null|string
     */
    protected function resolveBrickTemplate(AreabrickInterface $brick, $type)
    {
        $cacheKey = sprintf('%s.%s', $brick->getId(), $type);
        if (isset($this->brickTemplateCache[$cacheKey])) {
            return $this->brickTemplateCache[$cacheKey];
        }

        $template = null;
        if ($type === 'view') {
            $template = $brick->getViewTemplate();
        } elseif ($type === 'edit') {
            $template = $brick->getEditTemplate();
        }

        if (null === $template) {
            if ($brick instanceof TemplateAreabrickInterface) {
                $template = $this->buildBrickTemplateReference($brick, $type);
            } else {
                $e = new ConfigurationException(sprintf(
                    'Brick %s is configured to have a %s template but does not return a template path and does not implement %s',
                    $brick->getId(),
                    $type,
                    TemplateAreabrickInterface::class
                ));

                $this->logger->error($e->getMessage());

                throw $e;
            }
        }

        $this->brickTemplateCache[$cacheKey] = $template;

        return $template;
    }

    /**
     * Return either bundle or global (= app/Resources) template reference
     *
     * @param TemplateAreabrickInterface $brick
     * @param string $type
     *
     * @return string
     */
    protected function buildBrickTemplateReference(TemplateAreabrickInterface $brick, $type)
    {
        if ($brick->getTemplateLocation() === TemplateAreabrickInterface::TEMPLATE_LOCATION_BUNDLE) {
            $bundle = $this->bundleLocator->getBundle($brick);

            return sprintf(
                '%s:Areas/%s:%s.%s',
                $bundle->getName(),
                $brick->getId(),
                $type,
                $brick->getTemplateSuffix()
            );
        } else {
            return sprintf(
                'Areas/%s/%s.%s',
                $brick->getId(),
                $type,
                $brick->getTemplateSuffix()
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function renderAction($view, $controller, $action, $parent = null, array $params = [])
    {
        $document = $params['document'];
        if ($document && $document instanceof PageSnippet) {
            $params = $this->actionRenderer->addDocumentParams($document, $params);
        }

        $controller = $this->actionRenderer->createControllerReference(
            $parent,
            $controller,
            $action,
            $params
        );

        return $this->actionRenderer->render($controller);
    }
}