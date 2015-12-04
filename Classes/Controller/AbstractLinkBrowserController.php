<?php
namespace TYPO3\CMS\Recordlist\Controller;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\DocumentTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Service\DependencyOrderingService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Lang\LanguageService;
use TYPO3\CMS\Recordlist\LinkHandler\LinkHandlerInterface;

/**
 * Script class for the Link Browser window.
 */
abstract class AbstractLinkBrowserController
{
    /**
     * @var DocumentTemplate
     */
    protected $doc;

    /**
     * @var array
     */
    protected $parameters;

    /**
     * URL of current request
     *
     * @var string
     */
    protected $thisScript = '';

    /**
     * @var LinkHandlerInterface[]
     */
    protected $linkHandlers = [];

    /**
     * All parts of the current link
     *
     * Comprised of url information and additional link parameters.
     *
     * @var string[]
     */
    protected $currentLinkParts = [];

    /**
     * Link handler responsible for the current active link
     *
     * @var LinkHandlerInterface $currentLinkHandler
     */
    protected $currentLinkHandler;

    /**
     * The ID of the currently active link handler
     *
     * @var string
     */
    protected $currentLinkHandlerId;

    /**
     * Link handler to be displayed
     *
     * @var LinkHandlerInterface $displayedLinkHandler
     */
    protected $displayedLinkHandler;

    /**
     * The ID of the displayed link handler
     *
     * This is read from the 'act' GET parameter
     *
     * @var string
     */
    protected $displayedLinkHandlerId = '';

    /**
     * List of available link attribute fields
     *
     * @var string[]
     */
    protected $linkAttributeFields = [];

    /**
     * Values of the link attributes
     *
     * @var string[]
     */
    protected $linkAttributeValues = [];

    /**
     * @var array
     */
    protected $hookObjects = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initHookObjects();
        $this->init();
    }

    /**
     * Initialize the controller
     *
     * @return void
     */
    protected function init()
    {
        $this->getLanguageService()->includeLLFile('EXT:lang/locallang_browse_links.xlf');
    }

    /**
     * Initialize hook objects implementing the interface
     *
     * @throws \UnexpectedValueException
     * @return void
     */
    protected function initHookObjects()
    {
        if (
            isset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['LinkBrowser']['hooks'])
            && is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['LinkBrowser']['hooks'])
        ) {
            $hooks = GeneralUtility::makeInstance(DependencyOrderingService::class)->orderByDependencies(
                $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['LinkBrowser']['hooks']
            );
            foreach ($hooks as $key => $hook) {
                $this->hookObjects[] = GeneralUtility::makeInstance($hook['handler']);
            }
        }
    }

    /**
     * Injects the request object for the current request or subrequest
     * As this controller goes only through the main() method, it is rather simple for now
     *
     * @param ServerRequestInterface $request the current request
     * @param ResponseInterface $response the prepared response object
     * @return ResponseInterface the response with the content
     */
    public function mainAction(ServerRequestInterface $request, ResponseInterface $response)
    {
        $this->determineScriptUrl($request);
        $this->initVariables($request);
        $this->loadLinkHandlers();
        $this->initCurrentUrl();

        $menuData = $this->buildMenuArray();
        $renderLinkAttributeFields = $this->renderLinkAttributeFields();
        $browserContent = $this->displayedLinkHandler->render($request);

        $this->initDocumentTemplate();
        $content = $this->doc->startPage('Link Browser');
        $content .= $this->doc->getFlashMessages();

        if (!empty($this->currentLinkParts)) {
            $content .= $this->renderCurrentUrl();
        }
        $content .= $this->doc->getTabMenuRaw($menuData);
        $content .= $renderLinkAttributeFields;

        $content .= '<div class="linkBrowser-tabContent">' . $browserContent . '</div>';
        $content .= $this->doc->endPage();

        $response->getBody()->write($this->doc->insertStylesAndJS($content));
        return $response;
    }

    /**
     * Sets the script url depending on being a module or script request
     *
     * @param ServerRequestInterface $request
     *
     * @throws \TYPO3\CMS\Backend\Routing\Exception\ResourceNotFoundException
     * @throws \TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException
     */
    protected function determineScriptUrl(ServerRequestInterface $request)
    {
        if ($routePath = $request->getQueryParams()['route']) {
            $router = GeneralUtility::makeInstance(Router::class);
            $route = $router->match($routePath);
            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $this->thisScript = (string)$uriBuilder->buildUriFromRoute($route->getOption('_identifier'));
        } elseif ($moduleName = $request->getQueryParams()['M']) {
            $this->thisScript = BackendUtility::getModuleUrl($moduleName);
        } else {
            $this->thisScript = GeneralUtility::getIndpEnv('SCRIPT_NAME');
        }
    }

    /**
     * @param ServerRequestInterface $request
     */
    protected function initVariables(ServerRequestInterface $request)
    {
        $queryParams = $request->getQueryParams();
        $act = isset($queryParams['act']) ? $queryParams['act'] : '';
        // @deprecated since CMS 7, remove with CMS 8
        if (strpos($act, '|')) {
            GeneralUtility::deprecationLog('Using multiple values for the "act" parameter in the link wizard is deprecated. Only a single value is allowed. Values were: ' . $act);
            $act = array_shift(explode('|', $act));
        }
        $this->displayedLinkHandlerId = $act;
        $this->parameters = isset($queryParams['P']) ? $queryParams['P'] : [];
        $this->linkAttributeValues = isset($queryParams['linkAttributes']) ? $queryParams['linkAttributes'] : [];
    }

    /**
     * @return void
     * @throws \UnexpectedValueException
     */
    protected function loadLinkHandlers()
    {
        $linkHandlers = $this->getLinkHandlers();
        if (empty($linkHandlers)) {
            throw new \UnexpectedValueException('No link handlers are configured. Check page TSconfig TCEMAIN.linkHandler.', 1442787911);
        }

        $lang = $this->getLanguageService();
        foreach ($linkHandlers as $identifier => $configuration) {
            $identifier = rtrim($identifier, '.');
            /** @var LinkHandlerInterface $handler */
            $handler = GeneralUtility::makeInstance($configuration['handler']);
            $handler->initialize(
                $this,
                $identifier,
                isset($configuration['configuration.']) ? $configuration['configuration.'] : []
            );

            $this->linkHandlers[$identifier] = [
                'handlerInstance' => $handler,
                'label' => $lang->sL($configuration['label'], true),
                'displayBefore' => isset($configuration['displayBefore']) ? GeneralUtility::trimExplode(',', $configuration['displayBefore']) : [],
                'displayAfter' => isset($configuration['displayAfter']) ? GeneralUtility::trimExplode(',', $configuration['displayAfter']) : [],
                'scanBefore' => isset($configuration['scanBefore']) ? GeneralUtility::trimExplode(',', $configuration['scanBefore']) : [],
                'scanAfter' => isset($configuration['scanAfter']) ? GeneralUtility::trimExplode(',', $configuration['scanAfter']) : [],
                'addParams' => isset($configuration['addParams']) ? $configuration['addParams'] : '',
            ];
        }
    }

    /**
     * Reads the configured link handlers from page TSconfig
     *
     * @return array
     */
    protected function getLinkHandlers()
    {
        $pageTSconfig = BackendUtility::getPagesTSconfig($this->getCurrentPageId());
        $pageTSconfig = $this->getBackendUser()->getTSConfig('TCEMAIN.linkHandler.', $pageTSconfig);
        $linkHandlers = (array)$pageTSconfig['properties'];

        foreach ($this->hookObjects as $hookObject) {
            if (method_exists($hookObject, 'modifyLinkHandlers')) {
                $linkHandlers = $hookObject->modifyLinkHandlers($linkHandlers, $this->currentLinkParts);
            }
        }

        return $linkHandlers;
    }

    /**
     * Initialize $this->currentLinkParts and $this->currentLinkHandler
     *
     * @return void
     */
    protected function initCurrentUrl()
    {
        if (empty($this->currentLinkParts)) {
            return;
        }

        $orderedHandlers = GeneralUtility::makeInstance(DependencyOrderingService::class)->orderByDependencies($this->linkHandlers, 'scanBefore', 'scanAfter');

        // find responsible handler for current link
        foreach ($orderedHandlers as $key => $configuration) {
            /** @var LinkHandlerInterface $handler */
            $handler = $configuration['handlerInstance'];
            if ($handler->canHandleLink($this->currentLinkParts)) {
                $this->currentLinkHandler = $handler;
                $this->currentLinkHandlerId = $key;
                break;
            }
        }
        // reset the link if we have no handler for it
        if (!$this->currentLinkHandler) {
            $this->currentLinkParts = [];
        }

        // overwrite any preexisting
        foreach ($this->currentLinkParts as $key => $part) {
            if ($key !== 'url') {
                $this->linkAttributeValues[$key] = $part;
            }
        }
    }

    /**
     * Initialize document template object
     *
     *  @return void
     */
    protected function initDocumentTemplate()
    {
        $this->doc = GeneralUtility::makeInstance(DocumentTemplate::class);
        $this->doc->bodyTagId = 'typo3-browse-links-php';

        foreach ($this->getBodyTagAttributes() as $attributeName => $value) {
            $this->doc->bodyTagAdditions .= ' ' . $attributeName . '="' . htmlspecialchars($value) . '"';
        }

        // Finally, add the accumulated JavaScript to the template object:
        // also unset the default jumpToUrl() function before
        unset($this->doc->JScodeArray['jumpToUrl']);
    }

    /**
     * Render the currently set URL
     *
     * @return string
     */
    protected function renderCurrentUrl()
    {
        return '<!-- Print current URL -->
				<table border="0" cellpadding="0" cellspacing="0" id="typo3-curUrl">
					<tr>
						<td>' . $this->getLanguageService()->getLL('currentLink', true) . ': ' . htmlspecialchars($this->currentLinkHandler->formatCurrentUrl()) . '</td>
					</tr>
				</table>';
    }

    /**
     * Returns an array definition of the top menu
     *
     * @return mixed[][]
     */
    protected function buildMenuArray()
    {
        $allowedItems = $this->getAllowedItems();
        if ($this->displayedLinkHandlerId && !in_array($this->displayedLinkHandlerId, $allowedItems, true)) {
            $this->displayedLinkHandlerId = '';
        }

        $allowedHandlers = array_flip($allowedItems);
        $menuDef = array();
        foreach ($this->linkHandlers as $identifier => $configuration) {
            if (!isset($allowedHandlers[$identifier])) {
                continue;
            }

            /** @var LinkHandlerInterface $handlerInstance */
            $handlerInstance = $configuration['handlerInstance'];
            $isActive = $this->displayedLinkHandlerId === $identifier || !$this->displayedLinkHandlerId && $handlerInstance === $this->currentLinkHandler;
            if ($isActive) {
                $this->displayedLinkHandler = $handlerInstance;
                if (!$this->displayedLinkHandlerId) {
                    $this->displayedLinkHandlerId = $this->currentLinkHandlerId;
                }
            }

            if ($configuration['addParams']) {
                $addParams = $configuration['addParams'];
            } else {
                $parameters = GeneralUtility::implodeArrayForUrl('', $this->getUrlParameters(['act' => $identifier]));
                $addParams = 'onclick="jumpToUrl(' . GeneralUtility::quoteJSvalue('?' . ltrim($parameters, '&')) . ');return false;"';
            }
            $menuDef[$identifier] = [
                'isActive' => $isActive,
                'label' => $configuration['label'],
                'url' => '#',
                'addParams' => $addParams,
                'before' => $configuration['displayBefore'],
                'after' => $configuration['displayAfter']
            ];
        }

        $menuDef = GeneralUtility::makeInstance(DependencyOrderingService::class)->orderByDependencies($menuDef);

        // if there is no active tab
        if (!$this->displayedLinkHandler) {
            // empty the current link
            $this->currentLinkParts = [];
            $this->currentLinkHandler = null;
            $this->currentLinkHandler = '';
            // select first tab
            reset($menuDef);
            $this->displayedLinkHandlerId = key($menuDef);
            $this->displayedLinkHandler = $this->linkHandlers[$this->displayedLinkHandlerId]['handlerInstance'];
            $menuDef[$this->displayedLinkHandlerId]['isActive'] = true;
        }

        return $menuDef;
    }

    /**
     * Get the allowed items or tabs
     *
     * @return string[]
     */
    protected function getAllowedItems()
    {
        $allowedItems = array_keys($this->linkHandlers);

        foreach ($this->hookObjects as $hookObject) {
            if (method_exists($hookObject, 'modifyAllowedItems')) {
                $allowedItems = $hookObject->modifyAllowedItems($allowedItems, $this->currentLinkParts);
            }
        }

        // Initializing the action value, possibly removing blinded values etc:
        $blindLinkOptions = isset($this->parameters['params']['blindLinkOptions'])
            ? GeneralUtility::trimExplode(',', $this->parameters['params']['blindLinkOptions'])
            : [];
        $allowedItems = array_diff($allowedItems, $blindLinkOptions);

        return $allowedItems;
    }

    /**
     * Get the allowed link attributes
     *
     * @return string[]
     */
    protected function getAllowedLinkAttributes()
    {
        $allowedLinkAttributes = $this->displayedLinkHandler->getLinkAttributes();

        // Removing link fields if configured
        $blindLinkFields = isset($this->parameters['params']['blindLinkFields'])
            ? GeneralUtility::trimExplode(',', $this->parameters['params']['blindLinkFields'], true)
            : [];
        $allowedLinkAttributes = array_diff($allowedLinkAttributes, $blindLinkFields);

        return $allowedLinkAttributes;
    }

    /**
     * Renders the link attributes for the selected link handler
     *
     * @return string
     */
    public function renderLinkAttributeFields()
    {
        $fieldRenderingDefinitions = $this->getLinkAttributeFieldDefinitions();

        $fieldRenderingDefinitions = $this->displayedLinkHandler->modifyLinkAttributes($fieldRenderingDefinitions);

        $this->linkAttributeFields = $this->getAllowedLinkAttributes();

        $content = '';
        foreach ($this->linkAttributeFields as $attribute) {
            $content .= $fieldRenderingDefinitions[$attribute];
        }

        // add update button if appropriate
        if (!empty($this->currentLinkParts) && $this->displayedLinkHandler === $this->currentLinkHandler && $this->currentLinkHandler->isUpdateSupported()) {
            $content .= '
				<form action="" name="lparamsform" id="lparamsform">
					<table border="0" cellpadding="2" cellspacing="1" id="typo3-linkParams">
					<tr><td>
						<input class="btn btn-default t3js-linkCurrent" type="submit" value="' . $this->getLanguageService()->getLL('update', true) . '" />
					</td></tr>
					</table>
				</form><br /><br />';
        }

        return $content;
    }

    /**
     * Create an array of link attribute field rendering definitions
     *
     * @return string[]
     */
    protected function getLinkAttributeFieldDefinitions()
    {
        $lang = $this->getLanguageService();

        $fieldRenderingDefinitions = [];
        $fieldRenderingDefinitions['target'] = '
			<!--
				Selecting target for link:
			-->
				<form action="" name="ltargetform" id="ltargetform" class="t3js-dummyform">
					<table border="0" cellpadding="2" cellspacing="1" id="typo3-linkTarget">
						<tr>
							<td style="width: 96px;">' . $lang->getLL('target', true) . ':</td>
							<td>
								<input type="text" name="ltarget" class="t3js-linkTarget" value="' . htmlspecialchars($this->linkAttributeValues['target']) . '" />
								<select name="ltarget_type" class="t3js-targetPreselect">
									<option value=""></option>
									<option value="_top">' . $lang->getLL('top', true) . '</option>
									<option value="_blank">' . $lang->getLL('newWindow', true) . '</option>
								</select>
							</td>
						</tr>
					</table>
				</form>';

        $fieldRenderingDefinitions['title'] = '
				<!--
					Selecting title for link:
				-->
				<form action="" name="ltitleform" id="ltitleform" class="t3js-dummyform">
					<table border="0" cellpadding="2" cellspacing="1" id="typo3-linkTitle">
						<tr>
							<td style="width: 96px;">' . $lang->getLL('title', true) . '</td>
							<td><input type="text" name="ltitle" class="typo3-link-input" value="' . htmlspecialchars($this->linkAttributeValues['title']) . '" /></td>
						</tr>
					</table>
				</form>
			';

        $fieldRenderingDefinitions['class'] = '
				<!--
					Selecting class for link:
				-->
				<form action="" name="lclassform" id="lclassform" class="t3js-dummyform">
					<table border="0" cellpadding="2" cellspacing="1" id="typo3-linkClass">
						<tr>
							<td style="width: 96px;">' . $lang->getLL('class', true) . '</td>
							<td><input type="text" name="lclass" class="typo3-link-input" value="' . htmlspecialchars($this->linkAttributeValues['class']) . '" /></td>
						</tr>
					</table>
				</form>
			';

        $fieldRenderingDefinitions['params'] = '
				<!--
					Selecting params for link:
				-->
				<form action="" name="lparamsform" id="lparamsform" class="t3js-dummyform">
					<table border="0" cellpadding="2" cellspacing="1" id="typo3-linkParams">
						<tr>
							<td style="width: 96px;">' . $lang->getLL('params', true) . '</td>
							<td><input type="text" name="lparams" class="typo3-link-input" value="' . htmlspecialchars($this->linkAttributeValues['params']) . '" /></td>
						</tr>
					</table>
				</form>
			';

        return $fieldRenderingDefinitions;
    }

    /**
     * @param array $overrides
     *
     * @return array Array of parameters which have to be added to URLs
     */
    public function getUrlParameters(array $overrides = null)
    {
        return [
            'act' => isset($overrides['act']) ? $overrides['act'] : $this->displayedLinkHandlerId
        ];
    }

    /**
     * Get attributes for the body tag
     *
     * @return string[] Array of body-tag attributes
     */
    protected function getBodyTagAttributes()
    {
        $parameters = [];
        $parameters['uid'] = $this->parameters['uid'];
        $parameters['pid'] = $this->parameters['pid'];
        $parameters['itemName'] = $this->parameters['itemName'];
        $parameters['formName'] = $this->parameters['formName'];
        $parameters['params']['allowedExtensions'] = isset($this->parameters['params']['allowedExtensions']) ? $this->parameters['params']['allowedExtensions'] : '';
        $parameters['params']['blindLinkOptions'] = isset($this->parameters['params']['blindLinkOptions']) ? $this->parameters['params']['blindLinkOptions'] : '';
        $parameters['params']['blindLinkFields'] = isset($this->parameters['params']['blindLinkFields']) ? $this->parameters['params']['blindLinkFields']: '';
        $addPassOnParams = GeneralUtility::implodeArrayForUrl('P', $parameters);

        $attributes = $this->displayedLinkHandler->getBodyTagAttributes();
        return array_merge(
            $attributes,
            [
                'data-this-script-url' => strpos($this->thisScript, '?') === false ? $this->thisScript . '?' : $this->thisScript . '&',
                'data-url-parameters' => json_encode($this->getUrlParameters()),
                'data-parameters' => json_encode($this->parameters),
                'data-add-on-params' => $addPassOnParams,
                'data-link-attribute-fields' => json_encode($this->linkAttributeFields)
            ]
        );
    }

    /**
     * Return the ID of current page
     *
     * @return int
     */
    abstract protected function getCurrentPageId();

    /**
     * @return array
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * Retrieve the configuration
     *
     * @return array
     */
    public function getConfiguration()
    {
        return [];
    }

    /**
     * @return string
     */
    public function getDisplayedLinkHandlerId()
    {
        return $this->displayedLinkHandlerId;
    }

    /**
     * @return string
     */
    public function getScriptUrl()
    {
        return $this->thisScript;
    }

    /**
     * @return LanguageService
     */
    protected function getLanguageService()
    {
        return $GLOBALS['LANG'];
    }

    /**
     * @return BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }
}
