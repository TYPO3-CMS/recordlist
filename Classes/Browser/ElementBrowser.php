<?php
namespace TYPO3\CMS\Recordlist\Browser;

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

use TYPO3\CMS\Backend\Form\FormEngine;
use TYPO3\CMS\Backend\RecordList\ElementBrowserRecordList;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\DocumentTemplate;
use TYPO3\CMS\Backend\Tree\View\ElementBrowserFolderTreeView;
use TYPO3\CMS\Backend\Tree\View\ElementBrowserPageTreeView;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\ElementBrowser\ElementBrowserHookInterface;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\Exception;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Filter\FileExtensionFilter;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\InaccessibleFolder;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\File\BasicFileUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Frontend\Service\TypoLinkCodecService;
use TYPO3\CMS\Lang\LanguageService;

/**
 * class for the Element Browser window.
 */
class ElementBrowser {

	/**
	 * Optional instance of a record list that TBE_expandPage() should
	 * use to render the records in a page
	 *
	 * @var ElementBrowserRecordList
	 */
	protected $recordList = NULL;

	/**
	 * Current site URL (Frontend)
	 *
	 * @var string
	 * @internal
	 */
	public $siteURL;

	/**
	 * the script to link to
	 *
	 * @var string
	 */
	public $thisScript;

	/**
	 * RTE configuration
	 *
	 * @var array
	 */
	protected $RTEProperties = array();

	/**
	 * Target (RTE specific)
	 *
	 * @var string
	 */
	public $setTarget;

	/**
	 * CSS Class (RTE specific)
	 *
	 * @var string
	 */
	public $setClass;

	/**
	 * title (RTE specific)
	 *
	 * @var string
	 */
	public $setTitle;

	/**
	 * @var string
	 */
	public $setParams;

	/**
	 * Backend template object
	 *
	 * @var DocumentTemplate
	 */
	public $doc;

	/**
	 * Holds information about files
	 *
	 * @var mixed[][]
	 */
	public $elements = array();

	/**
	 * The mode determines the main kind of output from the element browser.
	 * There are these options for values: rte, db, file, filedrag, wizard.
	 * "rte" will show the link selector for the Rich Text Editor (see main_rte())
	 * "db" will allow you to browse for pages or records in the page tree (for TCEforms, see main_db())
	 * "file"/"filedrag" will allow you to browse for files or folders in the folder mounts (for TCEforms, main_file())
	 * "wizard" will allow you to browse for links (like "rte") which are passed back to TCEforms (see main_rte(1))
	 *
	 * @see main()
	 * @var string
	 */
	public $mode;

	/**
	 * Link selector action.
	 * page,file,url,mail are allowed values.
	 * These are only important with the link selector function and in that case they switch
	 * between the various menu options.
	 *
	 * @var string
	 */
	public $act;

	/**
	 * When you click a page title/expand icon to see the content of a certain page, this
	 * value will contain that value (the ID of the expanded page). If the value is NOT set,
	 * then it will be restored from the module session data (see main(), mode="db")
	 *
	 * @var NULL|int
	 */
	public $expandPage;

	/**
	 * When you click a folder name/expand icon to see the content of a certain file folder,
	 * this value will contain that value (the path of the expanded file folder). If the
	 * value is NOT set, then it will be restored from the module session data (see main(),
	 * mode="file"/"filedrag"). Example value: "/www/htdocs/typo3/32/3dsplm/fileadmin/css/"
	 *
	 * @var string
	 */
	public $expandFolder;

	/**
	 * the folder object of a parent folder that was selected
	 *
	 * @var Folder
	 */
	protected $selectedFolder;

	/**
	 * TYPO3 Element Browser, wizard mode parameters. There is a heap of parameters there,
	 * better debug() them out if you need something... :-)
	 *
	 * @var array[]
	 */
	public $P;

	/**
	 * Active with TYPO3 Element Browser: Contains the name of the form field for which this window
	 * opens - thus allows us to make references back to the main window in which the form is.
	 * Example value: "data[pages][39][bodytext]|||tt_content|"
	 * or "data[tt_content][NEW3fba56fde763d][image]|||gif,jpg,jpeg,tif,bmp,pcx,tga,png,pdf,ai|"
	 *
	 * Values:
	 * 0: form field name reference, eg. "data[tt_content][123][image]"
	 * 1: htmlArea RTE parameters: editorNo:contentTypo3Language
	 * 2: RTE config parameters: RTEtsConfigParams
	 * 3: allowed types. Eg. "tt_content" or "gif,jpg,jpeg,tif,bmp,pcx,tga,png,pdf,ai"
	 * 4: IRRE uniqueness: target level object-id to perform actions/checks on, eg. "data[79][tt_address][1][<field>][<foreign_table>]"
	 * 5: IRRE uniqueness: name of function in opener window that checks if element is already used, eg. "inline.checkUniqueElement"
	 * 6: IRRE uniqueness: name of function in opener window that performs some additional(!) action, eg. "inline.setUniqueElement"
	 * 7: IRRE uniqueness: name of function in opener window that performs action instead of using addElement/insertElement, eg. "inline.importElement"
	 *
	 * $pArr = explode('|', $this->bparams);
	 * $formFieldName = $pArr[0];
	 * $allowedTablesOrFileTypes = $pArr[3];
	 *
	 * @var string
	 */
	public $bparams;

	/**
	 * Used with the Rich Text Editor.
	 * Example value: "tt_content:NEW3fba58c969f5c:bodytext:23:text:23:"
	 *
	 * @var string
	 */
	public $RTEtsConfigParams;

	/**
	 * Plus/Minus icon value. Used by the tree class to open/close notes on the trees.
	 *
	 * @var string
	 */
	public $PM;

	/**
	 * Pointer, used when browsing a long list of records etc.
	 *
	 * @var int
	 */
	public $pointer;

	/**
	 * Used with the link selector: Contains the GET input information about the CURRENT link
	 * in the RTE/TCEform field. This consists of "href", "target" and "title" keys.
	 * This information is passed around in links.
	 *
	 * @var array[]
	 */
	public $curUrlArray;

	/**
	 * Used with the link selector: Contains a processed version of the input values from curUrlInfo.
	 * This is splitted into pageid, content element id, label value etc.
	 * This is used for the internal processing of that information.
	 *
	 * @var array[]
	 */
	public $curUrlInfo;

	/**
	 * array which holds hook objects (initialised in init())
	 *
	 * @var ElementBrowserHookInterface[]
	 */
	protected $hookObjects = array();

	/**
	 * @var BasicFileUtility
	 */
	public $fileProcessor;

	/**
	 * @var PageRenderer
	 */
	protected $pageRenderer = NULL;

	/**
	 * @var IconFactory
	 */
	protected $iconFactory;

	/**
	 * @var string
	 */
	protected $hookName = 'typo3/class.browse_links.php';

	/**
	 * @var string
	 */
	protected $searchWord;

	/**
	 * @var FileRepository
	 */
	protected $fileRepository;

	/**
	* Construct
	*/
	public function __construct() {
		$this->iconFactory = GeneralUtility::makeInstance(IconFactory::class);
		$this->fileRepository = GeneralUtility::makeInstance(FileRepository::class);
		$this->pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
		$this->pageRenderer->loadJquery();
		$this->pageRenderer->loadRequireJsModule('TYPO3/CMS/Recordlist/FieldSelectBox');
	}

	/**
	 * Sets the script url depending on being a module or script request
	 */
	protected function determineScriptUrl() {
		if ($routePath = GeneralUtility::_GP('route')) {
			$router = GeneralUtility::makeInstance(Router::class);
			$route = $router->match($routePath);
			$uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
			$this->thisScript = (string)$uriBuilder->buildUriFromRoute($route->getOption('_identifier'));
		} elseif ($moduleName = GeneralUtility::_GP('M')) {
			$this->thisScript = BackendUtility::getModuleUrl($moduleName);
		} else {
			$this->thisScript = GeneralUtility::getIndpEnv('SCRIPT_NAME');
		}
	}

	/**
	 * Calculate path to this script.
	 * This method is public, to be used in hooks of this class only.
	 *
	 * @return string
	 */
	public function getThisScript() {
		return strpos($this->thisScript, '?') === FALSE ? $this->thisScript . '?' : $this->thisScript . '&';
	}

	/**
	 * Constructor:
	 * Initializes a lot of variables, setting JavaScript functions in header etc.
	 *
	 * @return void
	 * @throws \UnexpectedValueException
	 */
	public function init() {
		$this->initVariables();
		$this->initDocumentTemplate();

		// Initializing hooking browsers
		$this->initHookObjects();

		$this->initCurrentUrl();

		// Determine nature of current url:
		$this->act = GeneralUtility::_GP('act');
		if (!$this->act) {
			$this->act = $this->curUrlInfo['act'];
		}

		$this->initLinkAttributes();

		// Finally, add the accumulated JavaScript to the template object:
		// also unset the default jumpToUrl() function before
		unset($this->doc->JScodeArray['jumpToUrl']);
		$this->doc->JScode .= $this->doc->wrapScriptTags($this->getJSCode());
	}

	/**
	 * Initialize class variables
	 *
	 * @return void
	 */
	public function initVariables() {
		// Main GPvars:
		$this->pointer = GeneralUtility::_GP('pointer');
		$this->bparams = GeneralUtility::_GP('bparams');
		$this->P = GeneralUtility::_GP('P');
		$this->expandPage = GeneralUtility::_GP('expandPage');
		$this->expandFolder = GeneralUtility::_GP('expandFolder');
		$this->PM = GeneralUtility::_GP('PM');
		$this->RTEtsConfigParams = GeneralUtility::_GP('RTEtsConfigParams');
		$this->searchWord = (string)GeneralUtility::_GP('searchWord');

		// Site URL
		// Current site url
		$this->siteURL = GeneralUtility::getIndpEnv('TYPO3_SITE_URL');
		$this->determineScriptUrl();

		// Default mode is RTE
		$this->mode = GeneralUtility::_GP('mode');
		if (!$this->mode) {
			$this->mode = 'rte';
		}

		// Init fileProcessor
		$this->fileProcessor = GeneralUtility::makeInstance(BasicFileUtility::class);
		$this->fileProcessor->init(array(), $GLOBALS['TYPO3_CONF_VARS']['BE']['fileExtensions']);

		// Rich Text Editor specific configuration:
		if ($this->mode === 'rte') {
			$this->RTEProperties = $this->getRTEConfig();
		}
	}

	/**
	 * Initialize document template object
	 *
	 *  @return void
	 */
	protected function initDocumentTemplate() {
		// Creating backend template object:
		$this->doc = GeneralUtility::makeInstance(DocumentTemplate::class);
		$this->doc->bodyTagId = 'typo3-browse-links-php';
		$this->doc->getContextMenuCode();

		$pageRenderer = $this->getPageRenderer();
		$pageRenderer->loadJquery();
		$pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/BrowseLinks');
		$pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/LegacyTree');
	}

	/**
	 * Initialize hook objects implementing the interface
	 *
	 * @throws \UnexpectedValueException
	 * @return void
	 */
	protected function initHookObjects() {
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->hookName]['browseLinksHook'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->hookName]['browseLinksHook'] as $classData) {
				$processObject = GeneralUtility::getUserObj($classData);
				if (!$processObject instanceof ElementBrowserHookInterface) {
					throw new \UnexpectedValueException('$processObject must implement interface ' . ElementBrowserHookInterface::class, 1195039394);
				}
				$parameters = array();
				$processObject->init($this, $parameters);
				$this->hookObjects[] = $processObject;
			}
		}
	}

	/**
	 * Initialize $this->curUrlArray and $this->curUrlInfo based on script parameters
	 *
	 * @return void
	 */
	protected function initCurrentUrl() {
		// CurrentUrl - the current link url must be passed around if it exists
		if ($this->mode === 'wizard') {
			$currentValues = GeneralUtility::trimExplode(LF, trim($this->P['currentValue']));
			if (!empty($currentValues)) {
				$currentValue = array_pop($currentValues);
			} else {
				$currentValue = '';
			}
			$currentLinkParts = GeneralUtility::makeInstance(TypoLinkCodecService::class)->decode($currentValue);

			$initialCurUrlArray = array(
				'href' => $currentLinkParts['url'],
				'target' => $currentLinkParts['target'],
				'class' => $currentLinkParts['class'],
				'title' => $currentLinkParts['title'],
				'params' => $currentLinkParts['additionalParams']
			);
			$this->curUrlArray = is_array(GeneralUtility::_GP('curUrl'))
				? array_merge($initialCurUrlArray, GeneralUtility::_GP('curUrl'))
				: $initialCurUrlArray;
			// Additional fields for page links
			if (isset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->hookName]['extendUrlArray'])
				&& is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->hookName]['extendUrlArray'])
			) {
				$conf = array();
				$_params = array(
					'conf' => &$conf,
					'linkParts' => [
						// the hook expects old numerical indexes
						$currentLinkParts['url'],
						$currentLinkParts['target'],
						$currentLinkParts['class'],
						$currentLinkParts['title'],
						$currentLinkParts['additionalParams']
					]
				);
				foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->hookName]['extendUrlArray'] as $objRef) {
					$processor =& GeneralUtility::getUserObj($objRef);
					$processor->extendUrlArray($_params, $this);
				}
			}
			$this->curUrlInfo = $this->parseCurUrl($this->siteURL . '?id=' . $this->curUrlArray['href'], $this->siteURL);
			// pageid == 0 means that this is not an internal (page) link
			if ($this->curUrlInfo['pageid'] == 0 && $this->curUrlArray['href']) {
				// Check if there is the FAL API
				if (GeneralUtility::isFirstPartOfStr($this->curUrlArray['href'], 'file:')) {
					$this->curUrlInfo = $this->parseCurUrl($this->curUrlArray['href'], $this->siteURL);
				} elseif (file_exists(PATH_site . rawurldecode($this->curUrlArray['href']))) {
					$this->curUrlInfo = $this->parseCurUrl($this->siteURL . $this->curUrlArray['href'], $this->siteURL);
				} elseif (strstr($this->curUrlArray['href'], '@')) {
					$this->curUrlInfo = $this->parseCurUrl('mailto:' . $this->curUrlArray['href'], $this->siteURL);
				} else {
					// nothing of the above. this is an external link
					if (strpos($this->curUrlArray['href'], '://') === FALSE) {
						$currentLinkParts['url'] = 'http://' . $this->curUrlArray['href'];
					}
					$this->curUrlInfo = $this->parseCurUrl($currentLinkParts['url'], $this->siteURL);
				}
			} elseif (!$this->curUrlArray['href']) {
				$this->curUrlInfo = array();
				$this->act = 'page';
			} else {
				$this->curUrlInfo = $this->parseCurUrl($this->siteURL . '?id=' . $this->curUrlArray['href'], $this->siteURL);
			}
		} else {
			$this->curUrlArray = GeneralUtility::_GP('curUrl');
			if ($this->curUrlArray['all']) {
				$this->curUrlArray = GeneralUtility::get_tag_attributes($this->curUrlArray['all']);
				$this->curUrlArray['href'] = htmlspecialchars_decode($this->curUrlArray['href']);
			}
			// Note: parseCurUrl will invoke the hooks
			$this->curUrlInfo = $this->parseCurUrl($this->curUrlArray['href'], $this->siteURL);
			if (isset($this->curUrlArray['data-htmlarea-external']) && $this->curUrlArray['data-htmlarea-external'] === '1' && $this->curUrlInfo['act'] !== 'mail') {
				$this->curUrlInfo['act'] = 'url';
				$this->curUrlInfo['info'] = $this->curUrlArray['href'];
			}
		}
	}

	/**
	 * Initialize the current or default values of the link attributes (RTE)
	 *
	 * @return void
	 */
	protected function initLinkAttributes() {
		$this->setTarget = $this->curUrlArray['target'] != '-' ? $this->curUrlArray['target'] : '';
		if ($this->RTEProperties['default.']['defaultLinkTarget'] && !isset($this->curUrlArray['target'])) {
			$this->setTarget = $this->RTEProperties['default.']['defaultLinkTarget'];
		}
		$this->setClass = $this->curUrlArray['class'] != '-' ? $this->curUrlArray['class'] : '';
		$this->setTitle = $this->curUrlArray['title'] != '-' ? $this->curUrlArray['title'] : '';
		$this->setParams = $this->curUrlArray['params'] != '-' ? $this->curUrlArray['params'] : '';
	}

	/**
	 * Get the RTE configuration from Page TSConfig
	 *
	 * @return array[] RTE configuration array
	 */
	protected function getRTEConfig() {
		$RTEtsConfigParts = explode(':', $this->RTEtsConfigParams);
		$RTEsetup = $this->getBackendUser()->getTSConfig('RTE', BackendUtility::getPagesTSconfig($RTEtsConfigParts[5]));
		$RTEsetup['properties']['default.'] = BackendUtility::RTEsetup($RTEsetup['properties'], $RTEtsConfigParts[0], $RTEtsConfigParts[2], $RTEtsConfigParts[4]);
		return $RTEsetup['properties'];
	}

	/**
	 * Generate JS code to be used on the link insert/modify dialogue
	 *
	 * @return string the generated JS code
	 */
	public function getJsCode() {
		// BEGIN accumulation of header JavaScript:
		$JScode = '
			// This JavaScript is primarily for RTE/Link. jumpToUrl is used in the other cases as well...
			var add_href=' . GeneralUtility::quoteJSvalue($this->curUrlArray['href'] ? '&curUrl[href]=' . rawurlencode($this->curUrlArray['href']) : '') . ';
			var add_target=' . GeneralUtility::quoteJSvalue($this->setTarget ? '&curUrl[target]=' . rawurlencode($this->setTarget) : '') . ';
			var add_class=' . GeneralUtility::quoteJSvalue($this->setClass ? '&curUrl[class]=' . rawurlencode($this->setClass) : '') . ';
			var add_title=' . GeneralUtility::quoteJSvalue($this->setTitle ? '&curUrl[title]=' . rawurlencode($this->setTitle) : '') . ';
			var add_params=' . GeneralUtility::quoteJSvalue($this->bparams ? '&bparams=' . rawurlencode($this->bparams) : '') . ';

			var cur_href=' . GeneralUtility::quoteJSvalue($this->curUrlArray['href'] ?: '') . ';
			var cur_target=' . GeneralUtility::quoteJSvalue($this->setTarget ?: '') . ';
			var cur_class=' . GeneralUtility::quoteJSvalue($this->setClass ?: '') . ';
			var cur_title=' . GeneralUtility::quoteJSvalue($this->setTitle ?: '') . ';
			var cur_params=' . GeneralUtility::quoteJSvalue($this->setParams ?: '') . ';

			function browse_links_setTarget(target) {
				cur_target=target;
				add_target="&curUrl[target]="+encodeURIComponent(target);
			}
			function browse_links_setClass(cssClass) {
				cur_class = cssClass;
				add_class = "&curUrl[class]="+encodeURIComponent(cssClass);
			}
			function browse_links_setTitle(title) {
				cur_title=title;
				add_title="&curUrl[title]="+encodeURIComponent(title);
			}
			function browse_links_setValue(value) {
				cur_href=value;
				add_href="&curUrl[href]="+value;
			}
			function browse_links_setParams(params) {
				cur_params=params;
				add_params="&curUrl[params]="+encodeURIComponent(params);
			}
		' . $this->doc->redirectUrls();

		// Functions used, if the link selector is in wizard mode (= TCEforms fields)
		$addPassOnParams = '';
		if ($this->mode === 'rte') {
			// Rich Text Editor specific configuration
			$addPassOnParams .= '&RTEtsConfigParams=' . rawurlencode($this->RTEtsConfigParams);
		}
		$update = '';
		if ($this->mode === 'wizard') {
			if (!$this->areFieldChangeFunctionsValid() && !$this->areFieldChangeFunctionsValid(TRUE)) {
				$this->P['fieldChangeFunc'] = array();
			}
			unset($this->P['fieldChangeFunc']['alert']);
			foreach ($this->P['fieldChangeFunc'] as $v) {
				$update .= '
				window.opener.' . $v;
			}
			$P2 = array();
			$P2['uid'] = $this->P['uid'];
			$P2['pid'] = $this->P['pid'];
			$P2['itemName'] = $this->P['itemName'];
			$P2['formName'] = $this->P['formName'];
			$P2['fieldChangeFunc'] = $this->P['fieldChangeFunc'];
			$P2['fieldChangeFuncHash'] = GeneralUtility::hmac(serialize($this->P['fieldChangeFunc']));
			$P2['params']['allowedExtensions'] = isset($this->P['params']['allowedExtensions']) ? $this->P['params']['allowedExtensions'] : '';
			$P2['params']['blindLinkOptions'] = isset($this->P['params']['blindLinkOptions']) ? $this->P['params']['blindLinkOptions'] : '';
			$P2['params']['blindLinkFields'] = isset($this->P['params']['blindLinkFields']) ? $this->P['params']['blindLinkFields']: '';
			$addPassOnParams .= GeneralUtility::implodeArrayForUrl('P', $P2);
			$JScode .= '
				function link_typo3Page(id,anchor) {	//
					updateValueInMainForm(id + (anchor ? anchor : ""));
					close();
					return false;
				}
				function link_folder(folder) {	//
					updateValueInMainForm(folder);
					close();
					return false;
				}
				function link_current() {	//
					if (cur_href!="http://" && cur_href!="mailto:") {
						returnBeforeCleaned = cur_href;
						if (returnBeforeCleaned.substr(0, 7) == "http://") {
							returnToMainFormValue = returnBeforeCleaned.substr(7);
						} else if (returnBeforeCleaned.substr(0, 7) == "mailto:") {
							if (returnBeforeCleaned.substr(0, 14) == "mailto:mailto:") {
								returnToMainFormValue = returnBeforeCleaned.substr(14);
							} else {
								returnToMainFormValue = returnBeforeCleaned.substr(7);
							}
						} else {
							returnToMainFormValue = returnBeforeCleaned;
						}
						updateValueInMainForm(returnToMainFormValue);
						close();
					}
					return false;
				}
				function checkReference() {	//
					if (window.opener && window.opener.document && window.opener.document.querySelector(\'form[name="'
						. $this->P['formName'] . '"] [data-formengine-input-name="' . $this->P['itemName'] . '"]\')) {
						return window.opener.document.querySelector(\'form[name="' . $this->P['formName'] . '"] [data-formengine-input-name="' . $this->P['itemName'] . '"]\');
					} else {
						close();
					}
				}
				function updateValueInMainForm(input) {	//
					var field = checkReference();
					if (field) {
						if (cur_target == "" && (cur_class != "" || cur_title != "" || cur_params != "")) {
							cur_target = "-";
						}
						if (cur_class == "" && (cur_title != "" || cur_params != "")) {
							cur_class = "-";
						}
						cur_class = cur_class.replace(/[\'\\"]/g, "");
						if (cur_class.indexOf(" ") != -1) {
							cur_class = "\\"" + cur_class + "\\"";
						}
						if (cur_title == "" && cur_params != "") {
							cur_title = "-";
						}
						// replace each \ with \\
						cur_title = cur_title.replace(/\\\\/g, "\\\\\\\\");
						// replace each " with \"
						cur_title = cur_title.replace(/\\"/g, "\\\\\\"");
						if (cur_title.indexOf(" ") != -1) {
							cur_title = "\\"" + cur_title + "\\"";
						}
						if (cur_params) {
							cur_params = cur_params.replace(/\\bid\\=.*?(\\&|$)/, "");
						}
						input = input + " " + cur_target + " " + cur_class + " " + cur_title + " " + cur_params;
						input = input.replace(/^\s+|\s+$/g, "");
						if(field.value && field.className.search(/textarea/) != -1) {
							field.value += "\\n" + input;
						} else {
							field.value = input;
						}
						if (typeof field.onchange === \'function\') {
							field.onchange();
						}
						' . $update . '
					}
				}
			';
		}
		// General "jumpToUrl" function:
		$JScode .= '
			function jumpToUrl(URL,anchor) {	//
				if (URL.charAt(0) === \'?\') {
					URL = ' . GeneralUtility::quoteJSvalue($this->getThisScript()) . ' + URL.substring(1);
				}
				var add_act = URL.indexOf("act=")==-1 ? "&act=' . $this->act . '" : "";
				var add_mode = URL.indexOf("mode=")==-1 ? "&mode=' . $this->mode . '" : "";
				window.location.href = URL + add_act + add_mode + add_href + add_target + add_class + add_title + add_params'
					. ($addPassOnParams ? '+' . GeneralUtility::quoteJSvalue($addPassOnParams) : '')
					. '+(typeof(anchor) === "string" ? anchor : "");
				return false;
			}
		';

		$JScode .= $this->getBParamJSCode();

		// extends JavaScript code
		if (isset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->hookName]['extendJScode'])
			&& is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->hookName]['extendJScode'])
		) {
			$_params = array(
				'conf' => [],
				'wizardUpdate' => $update,
				'addPassOnParams' => $addPassOnParams
			);
			foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->hookName]['extendJScode'] as $objRef) {
				$processor =& GeneralUtility::getUserObj($objRef);
				$JScode .= $processor->extendJScode($_params, $this);
			}
		}
		return $JScode;
	}

	/**
	 * Splits parts of $this->bparams and returns needed JS
	 *
	 * @return string JavaScript code
	 */
	protected function getBParamJSCode() {
		$pArr = explode('|', $this->bparams);
		// This is JavaScript especially for the TBE Element Browser!
		$formFieldName = 'data[' . $pArr[0] . '][' . $pArr[1] . '][' . $pArr[2] . ']';
		// insertElement - Call check function (e.g. for uniqueness handling):
		$JScodeCheck = '';
		if ($pArr[4] && $pArr[5]) {
			$JScodeCheck = '
					// Call a check function in the opener window (e.g. for uniqueness handling):
				if (parent.window.opener) {
					var res = parent.window.opener.' . $pArr[5] . '("' . addslashes($pArr[4]) . '",table,uid,type);
					if (!res.passed) {
						if (res.message) alert(res.message);
						performAction = false;
					}
				} else {
					alert("Error - reference to main window is not set properly!");
					parent.close();
				}
			';
		}
		// insertElement - Call helper function:
		$JScodeHelper = '';
		if ($pArr[4] && $pArr[6]) {
			$JScodeHelper = '
						// Call helper function to manage data in the opener window:
					if (parent.window.opener) {
						parent.window.opener.' . $pArr[6] . '("' . addslashes($pArr[4]) . '",table,uid,type,"' . addslashes($pArr[0]) . '");
					} else {
						alert("Error - reference to main window is not set properly!");
						parent.close();
					}
			';
		}
		// insertElement - perform action commands:
		$JScodeActionMultiple = '';
		if ($pArr[4] && $pArr[7]) {
			// Call user defined action function:
			$JScodeAction = '
					if (parent.window.opener) {
						parent.window.opener.' . $pArr[7] . '("' . addslashes($pArr[4]) . '",table,uid,type);
						if (close) { focusOpenerAndClose(close); }
					} else {
						alert("Error - reference to main window is not set properly!");
						if (close) { parent.close(); }
					}
			';
			$JScodeActionMultiple = '
						// Call helper function to manage data in the opener window:
					if (parent.window.opener) {
						parent.window.opener.' . $pArr[7] . 'Multiple("' . addslashes($pArr[4]) . '",table,uid,type,"'
				. addslashes($pArr[0]) . '");
					} else {
						alert("Error - reference to main window is not set properly!");
						parent.close();
					}
			';
		} elseif ($pArr[0] && !$pArr[1] && !$pArr[2]) {
			$JScodeAction = '
					addElement(filename,table+"_"+uid,fp,close);
			';
		} else {
			$JScodeAction = '
					if (setReferences()) {
						parent.window.opener.group_change("add","' . $pArr[0] . '","' . $pArr[1] . '","' . $pArr[2]
				. '",elRef,targetDoc);
					} else {
						alert("Error - reference to main window is not set properly!");
					}
					focusOpenerAndClose(close);
			';
		}
		return '
			var elRef="";
			var targetDoc="";

			function setReferences() {	//
				if (parent.window.opener && parent.window.opener.content && parent.window.opener.content.document.editform'
			. '&& parent.window.opener.content.document.editform["' . $formFieldName . '"]) {
					targetDoc = parent.window.opener.content.document;
					elRef = targetDoc.editform["' . $formFieldName . '"];
					return true;
				} else {
					return false;
				}
			}
			function insertElement(table, uid, type, filename, fp, filetype, imagefile, action, close) {	//
				var performAction = true;
				' . $JScodeCheck . '
					// Call performing function and finish this action:
				if (performAction) {
						' . $JScodeHelper . $JScodeAction . '
				}
				return false;
			}
			var _hasActionMultipleCode = ' . (!empty($JScodeActionMultiple) ? 'true' : 'false') . ';
			function insertMultiple(table, uid) {
				var type = "";
						' . $JScodeActionMultiple . '
				return false;
			}
			function addElement(elName, elValue, altElValue, close) {	//
				if (parent.window.opener && parent.window.opener.setFormValueFromBrowseWin) {
					parent.window.opener.setFormValueFromBrowseWin("' . $pArr[0] . '",altElValue?altElValue:elValue,elName);
					focusOpenerAndClose(close);
				} else {
					alert("Error - reference to main window is not set properly!");
					parent.close();
				}
			}
			function focusOpenerAndClose(close) {	//
				BrowseLinks.focusOpenerAndClose(close);
			}
		';
	}

	/**
	 * Session data for this class can be set from outside with this method.
	 * Call after init()
	 *
	 * @param mixed[] $data Session data array
	 * @return array[] Session data and boolean which indicates that data needs to be stored in session because it's changed
	 */
	public function processSessionData($data) {
		$store = FALSE;
		switch ($this->mode) {
			case 'db':
				if (isset($this->expandPage)) {
					$data['expandPage'] = $this->expandPage;
					$store = TRUE;
				} else {
					$this->expandPage = $data['expandPage'];
				}
				break;
			case 'file':
			case 'filedrag':
			case 'folder':
				if (isset($this->expandFolder)) {
					$data['expandFolder'] = $this->expandFolder;
					$store = TRUE;
				} else {
					$this->expandFolder = $data['expandFolder'];
				}
				break;
			default:
				// intentionally empty
		}
		return array($data, $store);
	}

	/******************************************************************
	 *
	 * Main functions
	 *
	 ******************************************************************/

	/**
	 * Main entry point
	 *
	 * @return string HTML output
	 */
	public function render() {
		// Output the correct content according to $this->mode
		switch ($this->mode) {
			case 'rte':
				return $this->main_rte();
			case 'db':
				return $this->main_db();
			case 'file':
			case 'filedrag':
				return $this->main_file();
			case 'folder':
				return $this->main_folder();
			case 'wizard':
				return $this->main_rte(TRUE);
		}
		return '';
	}

	/**
	 * Rich Text Editor (RTE) link selector (MAIN function)
	 * Generates the link selector for the Rich Text Editor.
	 * Can also be used to select links for the TCEforms (see $wiz)
	 *
	 * @param bool $wiz If set, the "remove link" is not shown in the menu: Used for the "Select link" wizard which is used by the TCEforms
	 * @return string Modified content variable.
	 */
	protected function main_rte($wiz = FALSE) {
		// needs to be executed before doc->startPage()
		if (in_array($this->act, array('file', 'folder'))) {
			$this->doc->getDragDropCode('folders', 'Tree.ajaxID = "sc_alt_file_navframe_expandtoggle"');
		} elseif ($this->act === 'page') {
			$this->doc->getDragDropCode('pages');
		}
		// Starting content:
		$content = $this->doc->startPage('RTE link');
		// Add the FlashMessages if any
		$content .= $this->doc->getFlashMessages();

		$content .= $this->doc->getTabMenuRaw($this->buildMenuArray($wiz, $this->getAllowedItems('page,file,folder,url,mail')));
		// Adding the menu and header to the top of page:
		$content .= $this->printCurrentUrl($this->curUrlInfo['info']) . '<br />';
		// Depending on the current action we will create the actual module content for selecting a link:
		switch ($this->act) {
			case 'mail':
				$content .= $this->getEmailSelectorHtml();
				break;
			case 'url':
				$content .= $this->getExternalUrlSelectorHtml();
				break;
			case 'file':
			case 'folder':
				$content .= $this->getFileSelectorHtml();
				break;
			case 'page':
				$content .= $this->getPageSelectorHtml();
				break;
			default:
				// Call hook
				foreach ($this->hookObjects as $hookObject) {
					$content .= $hookObject->getTab($this->act);
				}
		}
		$lang = $this->getLanguageService();

		// Removing link fields if configured
		$blindLinkFields = isset($this->RTEProperties['default.']['blindLinkFields'])
			? GeneralUtility::trimExplode(',', $this->RTEProperties['default.']['blindLinkFields'], TRUE)
			: array();
		$pBlindLinkFields = isset($this->P['params']['blindLinkFields'])
			? GeneralUtility::trimExplode(',', $this->P['params']['blindLinkFields'], TRUE)
			: array();
		$allowedFields = array_diff(array('target', 'title', 'class', 'params'), $blindLinkFields, $pBlindLinkFields);

		if (in_array('params', $allowedFields, TRUE) && $this->act !== 'url') {
			$content .= '
				<!--
					Selecting params for link:
				-->
				<form action="" name="lparamsform" id="lparamsform">
					<table border="0" cellpadding="2" cellspacing="1" id="typo3-linkParams">
						<tr>
							<td style="width: 96px;">' . $lang->getLL('params', TRUE) . '</td>
							<td><input type="text" name="lparams" class="typo3-link-input" onchange="'
								. 'browse_links_setParams(this.value);" value="' . htmlspecialchars($this->setParams)
								. '" /></td>
						</tr>
					</table>
				</form>
			';
		}
		if (in_array('class', $allowedFields, TRUE)) {
			$content .= '
				<!--
					Selecting class for link:
				-->
				<form action="" name="lclassform" id="lclassform">
					<table border="0" cellpadding="2" cellspacing="1" id="typo3-linkClass">
						<tr>
							<td style="width: 96px;">' . $lang->getLL('class', TRUE) . '</td>
							<td><input type="text" name="lclass" class="typo3-link-input" onchange="'
								. 'browse_links_setClass(this.value);" value="' . htmlspecialchars($this->setClass)
								. '" /></td>
						</tr>
					</table>
				</form>
			';
		}
		if (in_array('title', $allowedFields, TRUE)) {
			$content .= '
				<!--
					Selecting title for link:
				-->
				<form action="" name="ltitleform" id="ltitleform">
					<table border="0" cellpadding="2" cellspacing="1" id="typo3-linkTitle">
						<tr>
							<td style="width: 96px;">' . $lang->getLL('title', TRUE) . '</td>
							<td><input type="text" name="ltitle" class="typo3-link-input" onchange="'
								. 'browse_links_setTitle(this.value);" value="' . htmlspecialchars($this->setTitle)
								. '" /></td>
						</tr>
					</table>
				</form>
			';
		}
		// additional fields for page links
		if (isset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->hookName]['addFields_PageLink'])
			&& is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->hookName]['addFields_PageLink'])
		) {
			$conf = array();
			$_params = array(
				'conf' => &$conf
			);
			foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->hookName]['addFields_PageLink'] as $objRef) {
				$processor =& GeneralUtility::getUserObj($objRef);
				$content .= $processor->addFields($_params, $this);
			}
		}
		// Target:
		if ($this->act !== 'mail' && in_array('target', $allowedFields, TRUE)) {
			$ltarget = '

			<!--
				Selecting target for link:
			-->
				<form action="" name="ltargetform" id="ltargetform">
					<table border="0" cellpadding="2" cellspacing="1" id="typo3-linkTarget">
						<tr>
							<td>' . $lang->getLL('target', TRUE) . ':</td>
							<td><input type="text" name="ltarget" onchange="browse_links_setTarget(this.value);" value="'
								. htmlspecialchars($this->setTarget) . '"' . $this->doc->formWidth(10) . ' /></td>
							<td>
								<select name="ltarget_type" onchange="browse_links_setTarget('
									. 'this.options[this.selectedIndex].value);document.ltargetform.ltarget.value='
									. 'this.options[this.selectedIndex].value;this.selectedIndex=0;">
									<option></option>
									<option value="_top">' . $lang->getLL('top', TRUE) . '</option>
									<option value="_blank">' . $lang->getLL('newWindow', TRUE) . '</option>
								</select>
							</td>
							<td>';
			if (($this->curUrlInfo['act'] === 'page' || $this->curUrlInfo['act'] === 'file' || $this->curUrlInfo['act'] === 'folder')
				&& $this->curUrlArray['href'] && $this->curUrlInfo['act'] === $this->act
			) {
				$ltarget .= '
							<input class="btn btn-default" type="submit" value="' . $lang->getLL('update', TRUE)
								. '" onclick="return link_current();" />';
			}
			$ltarget .= '		</td>
						</tr>
					</table>
				</form>';
			// Add "target selector" box to content:
			$content .= $ltarget;
			// Add some space
			$content .= '<br /><br />';
		}
		// End page, return content:
		$content .= $this->doc->endPage();
		$content = $this->doc->insertStylesAndJS($content);
		return $content;
	}

	/**
	 * Get the allowed items or tabs
	 *
	 * @param string $items initial list of possible items
	 * @return array the allowed items
	 */
	public function getAllowedItems($items) {
		$allowedItems = explode(',', $items);
		// Call hook for extra options
		foreach ($this->hookObjects as $hookObject) {
			$allowedItems = $hookObject->addAllowedItems($allowedItems);
		}

		// Initializing the action value, possibly removing blinded values etc:
		$blindLinkOptions = isset($this->RTEProperties['default.']['blindLinkOptions'])
			? GeneralUtility::trimExplode(',', $this->RTEProperties['default.']['blindLinkOptions'], TRUE)
			: array();
		$pBlindLinkOptions = isset($this->P['params']['blindLinkOptions'])
			? GeneralUtility::trimExplode(',', $this->P['params']['blindLinkOptions'])
			: array();
		$allowedItems = array_diff($allowedItems, $blindLinkOptions, $pBlindLinkOptions);

		reset($allowedItems);
		if (!in_array($this->act, $allowedItems)) {
			$this->act = current($allowedItems);
		}
		return $allowedItems;
	}

	/**
	 * Returns an array definition of the top menu
	 *
	 * @param bool $wiz
	 * @param array $allowedItems
	 * @return mixed[][]
	 */
	protected function buildMenuArray($wiz, $allowedItems) {
		$lang = $this->getLanguageService();

		$menuDef = array();
		if (!$wiz && $this->curUrlArray['href']) {
			$menuDef['removeLink']['isActive'] = $this->act === 'removeLink';
			$menuDef['removeLink']['label'] = $lang->getLL('removeLink', TRUE);
			$menuDef['removeLink']['url'] = '#';
			$menuDef['removeLink']['addParams'] = 'onclick="plugin.unLink();return false;"';
		}
		if (in_array('page', $allowedItems, TRUE)) {
			$menuDef['page']['isActive'] = $this->act === 'page';
			$menuDef['page']['label'] = $lang->getLL('page', TRUE);
			$menuDef['page']['url'] = '#';
			$menuDef['page']['addParams'] = 'onclick="jumpToUrl(' . GeneralUtility::quoteJSvalue('?act=page&mode=' . $this->mode . '&bparams=' . $this->bparams) . ');return false;"';
		}
		if (in_array('file', $allowedItems, TRUE)) {
			$menuDef['file']['isActive'] = $this->act === 'file';
			$menuDef['file']['label'] = $lang->getLL('file', TRUE);
			$menuDef['file']['url'] = '#';
			$menuDef['file']['addParams'] = 'onclick="jumpToUrl(' . GeneralUtility::quoteJSvalue('?act=file&mode=' . $this->mode . '&bparams=' . $this->bparams) . ');return false;"';
		}
		if (in_array('folder', $allowedItems, TRUE)) {
			$menuDef['folder']['isActive'] = $this->act === 'folder';
			$menuDef['folder']['label'] = $lang->getLL('folder', TRUE);
			$menuDef['folder']['url'] = '#';
			$menuDef['folder']['addParams'] = 'onclick="jumpToUrl(' . GeneralUtility::quoteJSvalue('?act=folder&mode=' . $this->mode . '&bparams=' . $this->bparams) . ');return false;"';
		}
		if (in_array('url', $allowedItems, TRUE)) {
			$menuDef['url']['isActive'] = $this->act === 'url';
			$menuDef['url']['label'] = $lang->getLL('extUrl', TRUE);
			$menuDef['url']['url'] = '#';
			$menuDef['url']['addParams'] = 'onclick="jumpToUrl(' . GeneralUtility::quoteJSvalue('?act=url&mode=' . $this->mode . '&bparams=' . $this->bparams) . ');return false;"';
		}
		if (in_array('mail', $allowedItems, TRUE)) {
			$menuDef['mail']['isActive'] = $this->act === 'mail';
			$menuDef['mail']['label'] = $lang->getLL('email', TRUE);
			$menuDef['mail']['url'] = '#';
			$menuDef['mail']['addParams'] = 'onclick="jumpToUrl(' . GeneralUtility::quoteJSvalue('?act=mail&mode=' . $this->mode . '&bparams=' . $this->bparams) . ');return false;"';
		}
		// Call hook for extra options
		foreach ($this->hookObjects as $hookObject) {
			$menuDef = $hookObject->modifyMenuDefinition($menuDef);
		}
		return $menuDef;
	}

	/**
	 * Returns HTML of the email link from
	 *
	 * @return string
	 */
	protected function getEmailSelectorHtml() {
		$lang = $this->getLanguageService();
		$extUrl = '
			<!--
				Enter mail address:
			-->
			<form action="" name="lurlform" id="lurlform">
				<table border="0" cellpadding="2" cellspacing="1" id="typo3-linkMail">
					<tr>
						<td style="width: 96px;">
							' . $lang->getLL('emailAddress', TRUE) . ':
						</td>
						<td>
							<input type="text" name="lemail"' . $this->doc->formWidth(20) . ' value="'
								. htmlspecialchars(($this->curUrlInfo['act'] === 'mail' ? $this->curUrlInfo['info'] : ''))
								. '" />
							<input class="btn btn-default" type="submit" value="' . $lang->getLL('setLink', TRUE)
								. '" onclick="browse_links_setTarget(\'\');browse_links_setValue(\'mailto:\'+'
								. 'document.lurlform.lemail.value); return link_current();" />
						</td>
					</tr>
				</table>
			</form>';
		return $extUrl;
	}

	/**
	 * Returns HTML of the external url link from
	 *
	 * @return string
	 */
	protected function getExternalUrlSelectorHtml() {
		$extUrl = '

				<!--
					Enter External URL:
				-->
						<form action="" name="lurlform" id="lurlform">
							<table border="0" cellpadding="2" cellspacing="1" id="typo3-linkURL">
								<tr>
									<td style="width: 96px;">URL:</td>
									<td><input type="text" name="lurl"' . $this->doc->formWidth(30) . ' value="'
			. htmlspecialchars(($this->curUrlInfo['act'] === 'url' ? $this->curUrlInfo['info'] : 'http://'))
			. '" /> ' . '<input class="btn btn-default" type="submit" value="' . $this->getLanguageService()->getLL('setLink', TRUE)
			. '" onclick="browse_links_setValue(document.lurlform.lurl.value); return link_current();" /></td>
								</tr>
							</table>
						</form>';
		return $extUrl;
	}

	/**
	 * Returns HTML of the file/folder link selector
	 *
	 * @param string $treeClassName
	 * @return string
	 */
	protected function getFileSelectorHtml($treeClassName = ElementBrowserFolderTreeView::class) {
		/** @var ElementBrowserFolderTreeView $folderTree */
		$folderTree = GeneralUtility::makeInstance($treeClassName);
		$folderTree->setElementBrowser($this);
		$folderTree->thisScript = $this->thisScript;
		$tree = $folderTree->getBrowsableTree();
		$backendUser = $this->getBackendUser();
		if ($this->curUrlInfo['value'] && $this->curUrlInfo['act'] === $this->act) {
			$cmpPath = $this->curUrlInfo['value'];
			if (!isset($this->expandFolder)) {
				$this->expandFolder = $cmpPath;
			}
		}
		// Create upload/create folder forms, if a path is given
		$selectedFolder = FALSE;
		if ($this->expandFolder) {
			$fileOrFolderObject = NULL;
			try {
				$fileOrFolderObject = ResourceFactory::getInstance()->retrieveFileOrFolderObject($this->expandFolder);
			} catch (\Exception $e) {
				// No path is selected
			}

			if ($fileOrFolderObject instanceof Folder) {
				// It's a folder
				$selectedFolder = $fileOrFolderObject;
			} elseif ($fileOrFolderObject instanceof FileInterface) {
				// It's a file
				try {
					$selectedFolder = $fileOrFolderObject->getParentFolder();
				} catch (\Exception $e) {
					// Accessing the parent folder failed for some reason. e.g. permissions
				}
			}
		}
		// If no folder is selected, get the user's default upload folder
		if (!$selectedFolder) {
			try {
				$selectedFolder = $backendUser->getDefaultUploadFolder();
			} catch (\Exception $e) {
				// The configured default user folder does not exist
			}
		}
		// Build the file upload and folder creation form
		$uploadForm = '';
		$createFolder = '';
		$content = '';
		if ($selectedFolder) {
			$uploadForm = ($this->act === 'file') ? $this->uploadForm($selectedFolder) : '';
			$createFolder = $this->createFolder($selectedFolder);
		}
		// Insert the upload form on top, if so configured
		if ($backendUser->getTSConfigVal('options.uploadFieldsInTopOfEB')) {
			$content .= $uploadForm;
		}

		// Render the filelist if there is a folder selected
		$files = '';
		if ($selectedFolder) {
			$allowedExtensions = isset($this->P['params']['allowedExtensions']) ? $this->P['params']['allowedExtensions'] : '';
			$files = $this->expandFolder($selectedFolder, $allowedExtensions);
		}
		// Create folder tree:
		$content .= '
				<!--
					Wrapper table for folder tree / file/folder list:
				-->
						<table border="0" cellpadding="0" cellspacing="0" id="typo3-linkFiles">
							<tr>
								<td class="c-wCell" valign="top">'
			. $this->barheader(($this->getLanguageService()->getLL('folderTree') . ':')) . $tree . '</td>
								<td class="c-wCell" valign="top">' . $files . '</td>
							</tr>
						</table>
						';
		// Adding create folder + upload form if applicable
		if (!$backendUser->getTSConfigVal('options.uploadFieldsInTopOfEB')) {
			$content .= $uploadForm;
		}
		$content .=  '<br />' . $createFolder . '<br />';
		return $content;
	}

	/**
	 * Returns HTML of the page link selector
	 *
	 * @param string $treeClassName name of the class used for page tree rendering
	 * @return string
	 */
	protected function getPageSelectorHtml($treeClassName = ElementBrowserPageTreeView::class) {
		$backendUser = $this->getBackendUser();

		/** @var ElementBrowserPageTreeView $pageTree */
		$pageTree = GeneralUtility::makeInstance($treeClassName);
		$pageTree->setElementBrowser($this);
		$pageTree->thisScript = $this->thisScript;
		$pageTree->ext_showPageId = (bool)$backendUser->getTSConfigVal('options.pageTree.showPageIdWithTitle');
		$pageTree->ext_showNavTitle = (bool)$backendUser->getTSConfigVal('options.pageTree.showNavTitle');
		$pageTree->addField('nav_title');
		$tree = $pageTree->getBrowsableTree();
		$cElements = $this->expandPage();
		$dbmount = $this->getTemporaryTreeMountCancelNotice();
		$content = '

				<!--
					Wrapper table for page tree / record list:
				-->
						<table border="0" cellpadding="0" cellspacing="0" id="typo3-linkPages">
							<tr>
								<td class="c-wCell" valign="top">'
			. $this->barheader(($this->getLanguageService()->getLL('pageTree') . ':'))
			. $dbmount
			. $tree . '</td>
								<td class="c-wCell" valign="top">' . $cElements . '</td>
							</tr>
						</table>
						';
		return $content;
	}

	/**
	 * TYPO3 Element Browser: Showing a page tree and allows you to browse for records
	 *
	 * @return string HTML content for the module
	 */
	protected function main_db() {
		// Starting content:
		$content = $this->doc->startPage('TBE record selector');
		// Init variable:
		$pArr = explode('|', $this->bparams);
		$tables = $pArr[3];
		$backendUser = $this->getBackendUser();

		// Making the browsable pagetree:
		/** @var \TYPO3\CMS\Recordlist\Tree\View\ElementBrowserPageTreeView $pageTree */
		$pageTree = GeneralUtility::makeInstance(\TYPO3\CMS\Recordlist\Tree\View\ElementBrowserPageTreeView::class);
		$pageTree->setElementBrowser($this);
		$pageTree->thisScript = $this->thisScript;
		$pageTree->ext_pArrPages = $tables === 'pages';
		$pageTree->ext_showNavTitle = (bool)$backendUser->getTSConfigVal('options.pageTree.showNavTitle');
		$pageTree->ext_showPageId = (bool)$backendUser->getTSConfigVal('options.pageTree.showPageIdWithTitle');
		$pageTree->addField('nav_title');

		$withTree = TRUE;
		if (($tables !== '') && ($tables !== '*')) {
			$tablesArr = GeneralUtility::trimExplode(',', $tables, TRUE);
			$onlyRootLevel = TRUE;
			foreach ($tablesArr as $currentTable) {
				$tableTca = $GLOBALS['TCA'][$currentTable];
				if (isset($tableTca)) {
					if (!isset($tableTca['ctrl']['rootLevel']) || ((int)$tableTca['ctrl']['rootLevel']) != 1) {
						$onlyRootLevel = FALSE;
					}
				}
			}
			if ($onlyRootLevel) {
				$withTree = FALSE;
				// page to work on will be root
				$this->expandPage = 0;
			}
		}

		$tree = $pageTree->getBrowsableTree();
		// Making the list of elements, if applicable:
		$cElements = $this->TBE_expandPage($tables);
		// Putting the things together, side by side:
		$content .= '

			<!--
				Wrapper table for page tree / record list:
			-->
			<table border="0" cellpadding="0" cellspacing="0" id="typo3-EBrecords">
				<tr>';
		if ($withTree) {
			$content .= '<td class="c-wCell" valign="top">'
				. $this->barheader(($this->getLanguageService()->getLL('pageTree') . ':'))
				. $this->getTemporaryTreeMountCancelNotice()
				. $tree . '</td>';
		}
		$content .= '<td class="c-wCell" valign="top">' . $cElements . '</td>
				</tr>
			</table>
			';
		// Add some space
		$content .= '<br /><br />';
		// End page, return content:
		$content .= $this->doc->endPage();
		$content = $this->doc->insertStylesAndJS($content);
		return $content;
	}

	/**
	 * TYPO3 Element Browser: Showing a folder tree, allowing you to browse for files.
	 *
	 * @return string HTML content for the module
	 */
	protected function main_file() {
		// include JS files and set prefs for foldertree
		$this->doc->getDragDropCode('folders', 'Tree.ajaxID = "sc_alt_file_navframe_expandtoggle"');
		// Starting content:
		$content = $this->doc->startPage('TBE file selector');
		// Add the FlashMessages if any
		$content .= $this->doc->getFlashMessages();
		// Init variable:
		$pArr = explode('|', $this->bparams);
		// The key number 3 of the pArr contains the "allowed" string. Disallowed is not passed to
		// the element browser at all but only filtered out in TCEMain afterwards
		$allowed = $pArr[3];
		if ($allowed !== 'sys_file' && $allowed !== '*' && !empty($allowed)) {
			$allowedFileExtensions = $allowed;
		}
		$backendUser = $this->getBackendUser();

		if (isset($allowedFileExtensions)) {
			// Create new filter object
			$filterObject = GeneralUtility::makeInstance(FileExtensionFilter::class);
			$filterObject->setAllowedFileExtensions($allowedFileExtensions);
			// Set file extension filters on all storages
			$storages = $backendUser->getFileStorages();
			/** @var $storage \TYPO3\CMS\Core\Resource\ResourceStorage */
			foreach ($storages as $storage) {
				$storage->addFileAndFolderNameFilter(array($filterObject, 'filterFileList'));
			}
		}
		// Create upload/create folder forms, if a path is given
		$this->selectedFolder = FALSE;
		if ($this->expandFolder) {
			$fileOrFolderObject = NULL;

			// Try to fetch the folder the user had open the last time he browsed files
			// Fallback to the default folder in case the last used folder is not existing
			try {
				$fileOrFolderObject = ResourceFactory::getInstance()->retrieveFileOrFolderObject($this->expandFolder);
			} catch (Exception $accessException) {
				// We're just catching the exception here, nothing to be done if folder does not exist or is not accessible.
			}

			if ($fileOrFolderObject instanceof Folder) {
				// It's a folder
				$this->selectedFolder = $fileOrFolderObject;
			} elseif ($fileOrFolderObject instanceof FileInterface) {
				// It's a file
				$this->selectedFolder = $fileOrFolderObject->getParentFolder();
			}
		}
		// Or get the user's default upload folder
		if (!$this->selectedFolder) {
			try {
				$this->selectedFolder = $backendUser->getDefaultUploadFolder();
			} catch (\Exception $e) {
				// The configured default user folder does not exist
			}
		}
			// Build the file upload and folder creation form
		$uploadForm = '';
		$createFolder = '';
		if ($this->selectedFolder) {
			$uploadForm = $this->uploadForm($this->selectedFolder);
			$createFolder = $this->createFolder($this->selectedFolder);
		}
		// Insert the upload form on top, if so configured
		if ($backendUser->getTSConfigVal('options.uploadFieldsInTopOfEB')) {
			$content .= $uploadForm;
		}
		// Getting flag for showing/not showing thumbnails:
		$noThumbs = $backendUser->getTSConfigVal('options.noThumbsInEB');
		$_MOD_SETTINGS = array();
		if (!$noThumbs) {
			// MENU-ITEMS, fetching the setting for thumbnails from File>List module:
			$_MOD_MENU = array('displayThumbs' => '');
			$_MCONF['name'] = 'file_list';
			$_MOD_SETTINGS = BackendUtility::getModuleData($_MOD_MENU, GeneralUtility::_GP('SET'), $_MCONF['name']);
		}
		$noThumbs = $noThumbs ?: !$_MOD_SETTINGS['displayThumbs'];
		// Create folder tree:
		/** @var ElementBrowserFolderTreeView $folderTree */
		$folderTree = GeneralUtility::makeInstance(ElementBrowserFolderTreeView::class);
		$folderTree->setElementBrowser($this);
		$folderTree->thisScript = $this->thisScript;
		$folderTree->ext_noTempRecyclerDirs = $this->mode === 'filedrag';
		$tree = $folderTree->getBrowsableTree();
		if ($this->selectedFolder) {
			if ($this->mode === 'filedrag') {
				$files = $this->TBE_dragNDrop($this->selectedFolder, $pArr[3]);
			} else {
				$files = $this->TBE_expandFolder($this->selectedFolder, $pArr[3], $noThumbs);
			}
		} else {
			$files = '';
		}

		// Putting the parts together, side by side:
		$content .= '

			<!--
				Wrapper table for folder tree / filelist:
			-->
			<table border="0" cellpadding="0" cellspacing="0" id="typo3-EBfiles">
				<tr>
					<td class="c-wCell" valign="top">' . $this->barheader(($this->getLanguageService()->getLL('folderTree') . ':'))
						. $tree . '</td>
					<td class="c-wCell" valign="top">' . $files . '</td>
				</tr>
			</table>
			';


		// Adding create folder + upload forms if applicable:
		if (!$backendUser->getTSConfigVal('options.uploadFieldsInTopOfEB')) {
			$content .= $uploadForm;
		}
		$content .= $createFolder;
		// Add some space
		$content .= '<br /><br />';
		// Setup indexed elements:
		$this->doc->JScode .= $this->doc->wrapScriptTags('
		require(["TYPO3/CMS/Backend/BrowseLinks"], function(BrowseLinks) {
			BrowseLinks.addElements(' . json_encode($this->elements) . ');
		});');
		// Ending page, returning content:
		$content .= $this->doc->endPage();
		$content = $this->doc->insertStylesAndJS($content);
		return $content;
	}

	/**
	 * TYPO3 Element Browser: Showing a folder tree, allowing you to browse for folders.
	 *
	 * @return string HTML content for the module
	 */
	protected function main_folder() {
		// include JS files
		// Setting prefs for foldertree
		$this->doc->getDragDropCode('folders', 'Tree.ajaxID = "sc_alt_file_navframe_expandtoggle";');
		// Starting content:
		$content = $this->doc->startPage('TBE folder selector');
		// Add the FlashMessages if any
		$content .= $this->doc->getFlashMessages();
		// Init variable:
		$parameters = explode('|', $this->bparams);
		if ($this->expandFolder) {
			$this->selectedFolder = ResourceFactory::getInstance()->getFolderObjectFromCombinedIdentifier($this->expandFolder);
		}
		if ($this->selectedFolder) {
			$createFolder = $this->createFolder($this->selectedFolder);
		} else {
			$createFolder = '';
		}
		// Create folder tree:
		/** @var ElementBrowserFolderTreeView $folderTree */
		$folderTree = GeneralUtility::makeInstance(ElementBrowserFolderTreeView::class);
		$folderTree->setElementBrowser($this);
		$folderTree->thisScript = $this->thisScript;
		$folderTree->ext_noTempRecyclerDirs = $this->mode === 'filedrag';
		$tree = $folderTree->getBrowsableTree();
		$folders = '';
		if ($this->selectedFolder) {
			if ($this->mode === 'filedrag') {
				$folders = $this->TBE_dragNDrop($this->selectedFolder, $parameters[3]);
			} else {
				$folders = $this->TBE_expandSubFolders($this->selectedFolder);
			}
		}
		// Putting the parts together, side by side:
		$content .= '

			<!--
				Wrapper table for folder tree / folder list:
			-->
			<table border="0" cellpadding="0" cellspacing="0" id="typo3-EBfiles">
				<tr>
					<td class="c-wCell" valign="top">' . $this->barheader(($this->getLanguageService()->getLL('folderTree') . ':'))
						. $tree . '</td>
					<td class="c-wCell" valign="top">' . $folders . '</td>
				</tr>
			</table>
			';
		// Adding create folder if applicable:
		$content .= $createFolder;
		// Add some space
		$content .= '<br /><br />';
		// Ending page, returning content:
		$content .= $this->doc->endPage();
		$content = $this->doc->insertStylesAndJS($content);
		return $content;
	}

	/******************************************************************
	 *
	 * Record listing
	 *
	 ******************************************************************/
	/**
	 * For RTE: This displays all content elements on a page and lets you create a link to the element.
	 *
	 * @return string HTML output. Returns content only if the ->expandPage value is set (pointing to a page uid to show tt_content records from ...)
	 */
	public function expandPage() {
		$out = '';
		// Set page id (if any) to expand
		$expPageId = $this->expandPage;
		// If there is an anchor value (content element reference) in the element reference, then force an ID to expand:
		if (!$this->expandPage && $this->curUrlInfo['cElement']) {
			// Set to the current link page id.
			$expPageId = $this->curUrlInfo['pageid'];
		}
		// Draw the record list IF there is a page id to expand:
		if (
			$expPageId
			&& MathUtility::canBeInterpretedAsInteger($expPageId)
			&& $this->getBackendUser()->isInWebMount($expPageId)
		) {
			// Set header:
			$out .= $this->barheader($this->getLanguageService()->getLL('contentElements') . ':');
			// Create header for listing, showing the page title/icon:
			$mainPageRec = BackendUtility::getRecordWSOL('pages', $expPageId);
			$db = $this->getDatabaseConnection();
			$out .= '
				<ul class="list-tree list-tree-root list-tree-root-clean">
					<li class="list-tree-control-open">
						<span class="list-tree-group">
							<span class="list-tree-icon">' . $this->iconFactory->getIconForRecord('pages', $mainPageRec, Icon::SIZE_SMALL)->render() . '</span>
							<span class="list-tree-title">' . htmlspecialchars(BackendUtility::getRecordTitle('pages', $mainPageRec, TRUE)) . '</span>
						</span>
						<ul>
				';

			// Look up tt_content elements from the expanded page:
			$res = $db->exec_SELECTquery(
				'uid,header,hidden,starttime,endtime,fe_group,CType,colPos,bodytext',
				'tt_content',
				'pid=' . (int)$expPageId . BackendUtility::deleteClause('tt_content')
					. BackendUtility::versioningPlaceholderClause('tt_content'),
				'',
				'colPos,sorting'
			);
			// Traverse list of records:
			$c = 0;
			while ($row = $db->sql_fetch_assoc($res)) {
				$c++;
				$icon = $this->iconFactory->getIconForRecord('tt_content', $row, Icon::SIZE_SMALL)->render();
				$selected = '';
				if ($this->curUrlInfo['act'] == 'page' && $this->curUrlInfo['cElement'] == $row['uid']) {
					$selected = ' class="active"';
				}
				// Putting list element HTML together:
				$out .= '
					<li' . $selected . '>
						<span class="list-tree-group">
							<span class="list-tree-icon">
								' . $icon . '
							</span>
							<span class="list-tree-title">
								<a href="#" onclick="return link_typo3Page(\'' . $expPageId . '\',\'#' . $row['uid'] . '\');">
									' . htmlspecialchars(BackendUtility::getRecordTitle('tt_content', $row, TRUE)) . '
								</a>
							</span>
						</span>
					</li>
					';
			}
			$out .= '
						</ul>
					</li>
				</ul>
				';
		}
		return $out;
	}

	/**
	 * For TYPO3 Element Browser: This lists all content elements from the given list of tables
	 *
	 * @param string $tables Comma separated list of tables. Set to "*" if you want all tables.
	 * @return string HTML output.
	 */
	public function TBE_expandPage($tables) {
		$backendUser = $this->getBackendUser();
		if (!MathUtility::canBeInterpretedAsInteger($this->expandPage)
			|| $this->expandPage < 0
			|| !$backendUser->isInWebMount($this->expandPage)
		) {
			return '';
		}
		// Set array with table names to list:
		if (trim($tables) === '*') {
			$tablesArr = array_keys($GLOBALS['TCA']);
		} else {
			$tablesArr = GeneralUtility::trimExplode(',', $tables, TRUE);
		}
		reset($tablesArr);
		// Headline for selecting records:
		$out = $this->barheader($this->getLanguageService()->getLL('selectRecords') . ':');
		// Create the header, showing the current page for which the listing is.
		// Includes link to the page itself, if pages are amount allowed tables.
		$titleLen = (int)$backendUser->uc['titleLen'];
		$mainPageRec = BackendUtility::getRecordWSOL('pages', $this->expandPage);
		$ATag = '';
		$ATag_e = '';
		$ATag2 = '';
		$picon = '';
		if (is_array($mainPageRec)) {
			$picon = $this->iconFactory->getIconForRecord('pages', $mainPageRec, Icon::SIZE_SMALL)->render();
			if (in_array('pages', $tablesArr)) {
				$ATag = '<a href="#" onclick="return insertElement(\'pages\', \'' . $mainPageRec['uid'] . '\', \'db\', '
					. GeneralUtility::quoteJSvalue($mainPageRec['title']) . ', \'\', \'\', \'\',\'\',1);">';
				$ATag2 = '<a href="#" onclick="return insertElement(\'pages\', \'' . $mainPageRec['uid'] . '\', \'db\', '
					. GeneralUtility::quoteJSvalue($mainPageRec['title']) . ', \'\', \'\', \'\',\'\',0);">';
				$ATag_e = '</a>';
			}
		}
		$pBicon = $ATag2 ? $this->iconFactory->getIcon('actions-edit-add', Icon::SIZE_SMALL)->render() : '';
		$pText = htmlspecialchars(GeneralUtility::fixed_lgd_cs($mainPageRec['title'], $titleLen));
		$out .= $picon . $ATag2 . $pBicon . $ATag_e . $ATag . $pText . $ATag_e . '<br />';
		// Initialize the record listing:
		$id = $this->expandPage;
		$pointer = MathUtility::forceIntegerInRange($this->pointer, 0, 100000);
		$perms_clause = $backendUser->getPagePermsClause(1);
		$pageInfo = BackendUtility::readPageAccess($id, $perms_clause);
		// Generate the record list:
		/** @var $dbList ElementBrowserRecordList */
		if (is_object($this->recordList)) {
			$dbList = $this->recordList;
		} else {
			$dbList = GeneralUtility::makeInstance(ElementBrowserRecordList::class);
		}
		$dbList->setElementBrowser($this);
		$dbList->thisScript = $this->thisScript;
		$dbList->thumbs = 0;
		$dbList->localizationView = 1;
		$dbList->setIsEditable(FALSE);
		$dbList->calcPerms = $backendUser->calcPerms($pageInfo);
		$dbList->noControlPanels = 1;
		$dbList->clickMenuEnabled = 0;
		$dbList->tableList = implode(',', $tablesArr);
		$pArr = explode('|', $this->bparams);
		// a string like "data[pages][79][storage_pid]"
		$fieldPointerString = $pArr[0];
		// parts like: data, pages], 79], storage_pid]
		$fieldPointerParts = explode('[', $fieldPointerString);
		$relatingTableName = substr($fieldPointerParts[1], 0, -1);
		$relatingFieldName = substr($fieldPointerParts[3], 0, -1);
		if ($relatingTableName && $relatingFieldName) {
			$dbList->setRelatingTableAndField($relatingTableName, $relatingFieldName);
		}
		$dbList->start($id, GeneralUtility::_GP('table'), $pointer, GeneralUtility::_GP('search_field'),
			GeneralUtility::_GP('search_levels'), GeneralUtility::_GP('showLimit')
		);
		$dbList->setDispFields();
		$dbList->generateList();
		$out .= $dbList->getSearchBox();
		$out .= "<script>document.getElementById('db_list-searchbox-toolbar').style.display = 'block';document.getElementById('db_list-searchbox-toolbar').style.position = 'relative';</script>";

		//	Add the HTML for the record list to output variable:
		$out .= $dbList->HTMLcode;
		// Add support for fieldselectbox in singleTableMode
		if ($dbList->table) {
			$out .= $dbList->fieldSelectBox($dbList->table);
		}

		// Return accumulated content:
		return $out;
	}

	/**
	 * Render list of folders inside a folder.
	 *
	 * @param Folder $folder Folder
	 * @return string HTML output
	 */
	public function TBE_expandSubFolders(Folder $folder) {
		$content = '';
		if ($folder->checkActionPermission('read')) {
			$content .= $this->folderList($folder);
		}
		// Return accumulated content for folderlisting:
		return $content;
	}

	/******************************************************************
	 *
	 * Filelisting
	 *
	 ******************************************************************/
	/**
	 * For RTE: This displays all files from folder. No thumbnails shown
	 *
	 * @param Folder $folder The folder path to expand
	 * @param string $extensionList List of file extensions to show
	 * @return string HTML output
	 */
	public function expandFolder(Folder $folder, $extensionList = '') {
		if (!$folder->checkActionPermission('read')) {
			return '';
		}
		$lang = $this->getLanguageService();
		$renderFolders = $this->act === 'folder';
		// Create header for file/folder listing:
		if ($renderFolders) {
			$out = $this->barheader($lang->getLL('folders') . ':');
		} else {
			$out = $this->barheader($lang->getLL('files') . ':');
		}
		// Prepare current path value for comparison (showing red arrow)
		$currentIdentifier = '';
		if ($this->curUrlInfo['value']) {
			$currentIdentifier = $this->curUrlInfo['info'];
		}
		// Create header element; The folder from which files are listed.
		$titleLen = (int)$this->getBackendUser()->uc['titleLen'];
		$folderIcon = $this->iconFactory->getIconForResource($folder, Icon::SIZE_SMALL)->render();
		$folderIcon .= htmlspecialchars(GeneralUtility::fixed_lgd_cs($folder->getIdentifier(), $titleLen));
		$selected = '';
		if ($this->curUrlInfo['act'] == 'folder' && $currentIdentifier == $folder->getCombinedIdentifier()) {
			$selected = ' class="bg-success"';
		}
		$out .= '
			<a href="#"' . $selected . ' title="' . htmlspecialchars($folder->getIdentifier()) . '" onclick="return link_folder(\'file:' . $folder->getCombinedIdentifier() . '\');">
				' . $folderIcon . '
			</a>
			';
		// Get files from the folder:
		if ($renderFolders) {
			$items = $folder->getSubfolders();
		} else {
			$items = $this->getFilesInFolder($folder, $extensionList);
		}
		$c = 0;

		if (!empty($items)) {
			$out .= '<ul class="list-tree list-tree-root">';
			foreach ($items as $fileOrFolderObject) {
				$c++;
				if ($renderFolders) {
					$fileIdentifier = $fileOrFolderObject->getCombinedIdentifier();
					$overlay = NULL;
					if ($fileOrFolderObject instanceof InaccessibleFolder) {
						$overlay = array('status-overlay-locked' => array());
					}
					$icon = '<span title="' . htmlspecialchars($fileOrFolderObject->getName()) . '">'
						. $this->iconFactory->getIcon('apps-filetree-folder-default', Icon::SIZE_SMALL, $overlay)->render()
						. '</span>';
					$itemUid = 'file:' . $fileIdentifier;
				} else {
					$fileIdentifier = $fileOrFolderObject->getUid();
					// Get size and icon:
					$size = ' (' . GeneralUtility::formatSize($fileOrFolderObject->getSize()) . 'bytes)';
					$icon = '<span title="' . htmlspecialchars($fileOrFolderObject->getName() . $size) . '">' . $this->iconFactory->getIconForResource($fileOrFolderObject, Icon::SIZE_SMALL)->render() . '</span>';
					$itemUid = 'file:' . $fileIdentifier;
				}
				$selected = '';
				if (($this->curUrlInfo['act'] == 'file' || $this->curUrlInfo['act'] == 'folder')
					&& $currentIdentifier == $fileIdentifier
				) {
					$selected = ' class="active"';
				}
				// Put it all together for the file element:
				$out .=
					'<li' . $selected . '>
						<a href="#"title="' . htmlspecialchars($fileOrFolderObject->getName()) . '" onclick="return link_folder(\'' . $itemUid . '\');">
							' .	$icon . '
							' . htmlspecialchars(GeneralUtility::fixed_lgd_cs($fileOrFolderObject->getName(), $titleLen)) . '
						</a>
					</li>';
			}
			$out .= '</ul>';
		}
		return $out;
	}

	/**
	 * For TYPO3 Element Browser: Expand folder of files.
	 *
	 * @param Folder $folder The folder path to expand
	 * @param string $extensionList List of fileextensions to show
	 * @param bool $noThumbs Whether to show thumbnails or not. If set, no thumbnails are shown.
	 * @return string HTML output
	 */
	public function TBE_expandFolder(Folder $folder, $extensionList = '', $noThumbs = FALSE) {
		if (!$folder->checkActionPermission('read')) {
			return '';
		}
		$extensionList = $extensionList == '*' ? '' : $extensionList;
		if ($this->searchWord !== '') {
			$files = $this->fileRepository->searchByName($folder, $this->searchWord);
		} else {
			$files = $this->getFilesInFolder($folder, $extensionList);
		}

		return $this->fileList($files, $folder, $noThumbs);
	}

	/**
	 * Render list of files.
	 *
	 * @param File[] $files List of files
	 * @param Folder $folder If set a header with a folder icon and folder name are shown
	 * @param bool $noThumbs Whether to show thumbnails or not. If set, no thumbnails are shown.
	 * @return string HTML output
	 */
	protected function fileList(array $files, Folder $folder = NULL, $noThumbs = FALSE) {
		$out = '';

		$lang = $this->getLanguageService();
		$lines = array();
		// Create headline (showing number of files):
		$filesCount = count($files);
		$out .= $this->barheader(sprintf($lang->getLL('files') . ' (%s):', $filesCount));
		$out .= $this->getFileSearchField();
		$out .= '<div id="filelist">';
		$out .= $this->getBulkSelector($filesCount);
		$titleLen = (int)$this->getBackendUser()->uc['titleLen'];
		// Create the header of current folder:
		if ($folder) {
			$folderIcon = $this->iconFactory->getIconForResource($folder, Icon::SIZE_SMALL);
			$lines[] = '<tr class="t3-row-header">
				<td colspan="4">' . $folderIcon->render()
				. htmlspecialchars(GeneralUtility::fixed_lgd_cs($folder->getIdentifier(), $titleLen)) . '</td>
			</tr>';
		}
		if ($filesCount == 0) {
			$lines[] = '
				<tr class="file_list_normal">
					<td colspan="4">No files found.</td>
				</tr>';
		}
		// Traverse the filelist:
		/** @var $fileObject File */
		foreach ($files as $fileObject) {
			$fileExtension = $fileObject->getExtension();
			// Thumbnail/size generation:
			$imgInfo = array();
			if (GeneralUtility::inList(strtolower($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] . ',' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['mediafile_ext']), strtolower($fileExtension)) && !$noThumbs) {
				$processedFile = $fileObject->process(
					ProcessedFile::CONTEXT_IMAGEPREVIEW,
					array('width' => 64, 'height' => 64)
				);
				$imageUrl = $processedFile->getPublicUrl(TRUE);
				$imgInfo = array(
					$fileObject->getProperty('width'),
					$fileObject->getProperty('height')
				);
				$pDim = $imgInfo[0] . 'x' . $imgInfo[1] . ' pixels';
				$clickIcon = '<img src="' . $imageUrl . '" ' .
							'width="' . $processedFile->getProperty('width') . '" ' .
							'height="' . $processedFile->getProperty('height') . '" ' .
							'hspace="5" vspace="5" border="1" />';
			} else {
				$clickIcon = '';
				$pDim = '';
			}
			// Create file icon:
			$size = ' (' . GeneralUtility::formatSize($fileObject->getSize()) . 'bytes' . ($pDim ? ', ' . $pDim : '') . ')';
			$icon = '<span title="' . htmlspecialchars($fileObject->getName() . $size) . '">' . $this->iconFactory->getIconForResource($fileObject, Icon::SIZE_SMALL)->render() . '</span>';
			// Create links for adding the file:
			$filesIndex = count($this->elements);
			$this->elements['file_' . $filesIndex] = array(
				'type' => 'file',
				'table' => 'sys_file',
				'uid' => $fileObject->getUid(),
				'fileName' => $fileObject->getName(),
				'filePath' => $fileObject->getUid(),
				'fileExt' => $fileExtension,
				'fileIcon' => $icon
			);
			if ($this->fileIsSelectableInFileList($fileObject, $imgInfo)) {
				$ATag = '<a href="#" title="' . htmlspecialchars($fileObject->getName()) . '" onclick="return BrowseLinks.File.insertElement(\'file_' . $filesIndex . '\');">';
				$ATag_alt = substr($ATag, 0, -4) . ',1);">';
				$bulkCheckBox = '<input type="checkbox" class="typo3-bulk-item" name="file_' . $filesIndex . '" value="0" /> ';
				$ATag_e = '</a>';
			} else {
				$ATag = '';
				$ATag_alt = '';
				$ATag_e = '';
				$bulkCheckBox = '';
			}
			// Create link to showing details about the file in a window:
			$Ahref = BackendUtility::getModuleUrl('show_item', array(
				'type' => 'file',
				'table' => '_FILE',
				'uid' => $fileObject->getCombinedIdentifier(),
				'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI')
			));
			$ATag2_e = '</a>';
			// Combine the stuff:
			$filenameAndIcon = $bulkCheckBox . $ATag_alt . $icon
				. htmlspecialchars(GeneralUtility::fixed_lgd_cs($fileObject->getName(), $titleLen)) . $ATag_e;
			// Show element:
			if ($pDim) {
				// Image...
				$lines[] = '
					<tr class="file_list_normal">
						<td nowrap="nowrap">' . $filenameAndIcon . '&nbsp;</td>
						<td>' . $ATag . '<span title="' .  $lang->getLL('addToList', TRUE) . '">' . $this->iconFactory->getIcon('actions-edit-add', Icon::SIZE_SMALL)->render() . '</span>' . $ATag_e . '</td>
						<td nowrap="nowrap"><a href="' . htmlspecialchars($Ahref) . '" title="' . $lang->getLL('info', TRUE) . '">' . $this->iconFactory->getIcon('actions-document-info', Icon::SIZE_SMALL)->render() . $lang->getLL('info', TRUE) . $ATag2_e . '</td>
						<td nowrap="nowrap">&nbsp;' . $pDim . '</td>
					</tr>';
				$lines[] = '
					<tr>
						<td class="filelistThumbnail" colspan="4">' . $ATag_alt . $clickIcon . $ATag_e . '</td>
					</tr>';
			} else {
				$lines[] = '
					<tr class="file_list_normal">
						<td nowrap="nowrap">' . $filenameAndIcon . '&nbsp;</td>
						<td>' . $ATag . '<span title="' . $lang->getLL('addToList', TRUE) . '">' . $this->iconFactory->getIcon('actions-edit-add', Icon::SIZE_SMALL)->render() . '</span>' . $ATag_e . '</td>
						<td nowrap="nowrap"><a href="' . htmlspecialchars($Ahref) . '" title="' . $lang->getLL('info', TRUE) . '">' . $this->iconFactory->getIcon('actions-document-info', Icon::SIZE_SMALL)->render() . $lang->getLL('info', TRUE) . $ATag2_e . '</td>
						<td>&nbsp;</td>
					</tr>';
			}
		}
		// Wrap all the rows in table tags:
		$out .= '

	<!--
		Filelisting
	-->
			<table cellpadding="0" cellspacing="0" id="typo3-filelist">
				' . implode('', $lines) . '
			</table>';
		// Return accumulated content for filelisting:
		$out .= '</div>';
		return $out;
	}

	/**
	 * Checks if the given file is selectable in the filelist.
	 *
	 * By default all files are selectable. This method may be overwritten in child classes.
	 *
	 * @param FileInterface $file
	 * @param mixed[] $imgInfo Image dimensions from \TYPO3\CMS\Core\Imaging\GraphicalFunctions::getImageDimensions()
	 * @return bool TRUE if file is selectable.
	 */
	protected function fileIsSelectableInFileList(FileInterface $file, array $imgInfo) {
		return TRUE;
	}

	/**
	 * Render list of folders.
	 *
	 * @param Folder $baseFolder
	 * @return string HTML output
	 */
	public function folderList(Folder $baseFolder) {
		$content = '';
		$lang = $this->getLanguageService();
		$folders = $baseFolder->getSubfolders();
		$folderIdentifier = $baseFolder->getCombinedIdentifier();
		// Create headline (showing number of folders):
		$content .= $this->barheader(sprintf($lang->getLL('folders') . ' (%s):', count($folders)));
		$titleLength = (int)$this->getBackendUser()->uc['titleLen'];
		// Create the header of current folder:
		$aTag = '<a href="#" onclick="return insertElement(\'\',' . GeneralUtility::quoteJSvalue($folderIdentifier)
			. ', \'folder\', ' . GeneralUtility::quoteJSvalue($folderIdentifier) . ', ' . GeneralUtility::quoteJSvalue($folderIdentifier)
			. ', \'\', \'\',\'\',1);">';
		// Add the foder icon
		$folderIcon = $aTag;
		$folderIcon .= $this->iconFactory->getIcon('apps-filetree-folder-default', Icon::SIZE_SMALL)->render();
		$folderIcon .= htmlspecialchars(GeneralUtility::fixed_lgd_cs($baseFolder->getName(), $titleLength));
		$folderIcon .= '</a>';
		$content .= $folderIcon . '<br />';

		$lines = array();
		// Traverse the folder list:
		foreach ($folders as $subFolder) {
			$subFolderIdentifier = $subFolder->getCombinedIdentifier();
			// Create folder icon:
			$icon = '<span style="width: 16px; height: 16px; display: inline-block;"></span>';
			$icon .= '<span title="' . htmlspecialchars($subFolder->getName()) . '">' . $this->iconFactory->getIcon('apps-filetree-folder-default', Icon::SIZE_SMALL)->render() . '</span>';
			// Create links for adding the folder:
			if ($this->P['itemName'] != '' && $this->P['formName'] != '') {
				$aTag = '<a href="#" onclick="return set_folderpath(' . GeneralUtility::quoteJSvalue($subFolderIdentifier)
					. ');">';
			} else {
				$aTag = '<a href="#" onclick="return insertElement(\'\',' . GeneralUtility::quoteJSvalue($subFolderIdentifier)
					. ', \'folder\', ' . GeneralUtility::quoteJSvalue($subFolderIdentifier) . ', '
					. GeneralUtility::quoteJSvalue($subFolderIdentifier) . ', \'\', \'\');">';
			}
			if (strstr($subFolderIdentifier, ',') || strstr($subFolderIdentifier, '|')) {
				// In case an invalid character is in the filepath, display error message:
				$errorMessage = GeneralUtility::quoteJSvalue(sprintf($lang->getLL('invalidChar'), ', |'));
				$aTag = ($aTag_alt = '<a href="#" onclick="alert(' . $errorMessage . ');return false;">');
			} else {
				// If foldername is OK, just add it:
				$aTag_alt = substr($aTag, 0, -4) . ',\'\',1);">';
			}
			$aTag_e = '</a>';
			// Combine icon and folderpath:
			$foldernameAndIcon = $aTag_alt . $icon
				. htmlspecialchars(GeneralUtility::fixed_lgd_cs($subFolder->getName(), $titleLength)) . $aTag_e;
			if ($this->P['itemName'] != '') {
				$lines[] = '
					<tr class="bgColor4">
						<td nowrap="nowrap">' . $foldernameAndIcon . '&nbsp;</td>
						<td>&nbsp;</td>
					</tr>';
			} else {
				$lines[] = '
					<tr class="bgColor4">
						<td nowrap="nowrap">' . $foldernameAndIcon . '&nbsp;</td>
						<td>' . $aTag . '<span title="' . $lang->getLL('addToList', TRUE) . '">' . $this->iconFactory->getIcon('actions-edit-add', Icon::SIZE_SMALL)->render() . '</span>' . $aTag_e . ' </td>
						<td>&nbsp;</td>
					</tr>';
			}
			$lines[] = '
					<tr>
						<td colspan="3"><span style="width: 1px; height: 3px; display: inline-block;"></span></td>
					</tr>';
		}
		// Wrap all the rows in table tags:
		$content .= '

	<!--
		Folder listing
	-->
			<table border="0" cellpadding="0" cellspacing="1" id="typo3-folderList">
				' . implode('', $lines) . '
			</table>';
		// Return accumulated content for folderlisting:
		return $content;
	}

	/**
	 * For RTE: This displays all IMAGES (gif,png,jpg) (from extensionList) from folder. Thumbnails are shown for images.
	 * This listing is of images located in the web-accessible paths ONLY - the listing is for drag-n-drop use in the RTE
	 *
	 * @param Folder $folder The folder path to expand
	 * @param string $extensionList List of file extensions to show
	 * @return string HTML output
	 */
	public function TBE_dragNDrop(Folder $folder, $extensionList = '') {
		if (!$folder) {
			return '';
		}
		$lang = $this->getLanguageService();
		if (!$folder->getStorage()->isPublic()) {
			// Print this warning if the folder is NOT a web folder
			return GeneralUtility::makeInstance(FlashMessage::class, $lang->getLL('noWebFolder'), $lang->getLL('files'), FlashMessage::WARNING)
				->render();
		}
		$out = '';

		// Read files from directory:
		$extensionList = $extensionList == '*' ? '' : $extensionList;
		$files = $this->getFilesInFolder($folder, $extensionList);

		$out .= $this->barheader(sprintf($lang->getLL('files') . ' (%s):', count($files)));
		$titleLen = (int)$this->getBackendUser()->uc['titleLen'];
		$picon = $this->iconFactory->getIcon('apps-filetree-folder-default', Icon::SIZE_SMALL)->render();
		$picon .= htmlspecialchars(GeneralUtility::fixed_lgd_cs(basename($folder->getName()), $titleLen));
		$out .= $picon . '<br />';
		// Init row-array:
		$lines = array();
		// Add "drag-n-drop" message:
		$infoText = GeneralUtility::makeInstance(FlashMessage::class, $lang->getLL('findDragDrop'), '', FlashMessage::INFO)
			->render();
		$lines[] = '
			<tr>
				<td colspan="2">' . $infoText . '</td>
			</tr>';
		// Traverse files:
		foreach ($files as $fileObject) {
			// URL of image:
			$iUrl = GeneralUtility::rawurlencodeFP($fileObject->getPublicUrl(TRUE));
			// Show only web-images
			$fileExtension = strtolower($fileObject->getExtension());
			if (GeneralUtility::inList('gif,jpeg,jpg,png', $fileExtension)) {
				$imgInfo = array(
					$fileObject->getProperty('width'),
					$fileObject->getProperty('height')
				);
				$pDim = $imgInfo[0] . 'x' . $imgInfo[1] . ' pixels';
				$size = ' (' . GeneralUtility::formatSize($fileObject->getSize()) . 'bytes' . ($pDim ? ', ' . $pDim : '') . ')';
				$filenameAndIcon = '<span title="' . htmlspecialchars($fileObject->getName() . $size) . '">' . $this->iconFactory->getIconForResource($fileObject, Icon::SIZE_SMALL)->render() . '</span>';
				if (GeneralUtility::_GP('noLimit')) {
					$maxW = 10000;
					$maxH = 10000;
				} else {
					$maxW = 380;
					$maxH = 500;
				}
				$IW = $imgInfo[0];
				$IH = $imgInfo[1];
				if ($IW > $maxW) {
					$IH = ceil($IH / $IW * $maxW);
					$IW = $maxW;
				}
				if ($IH > $maxH) {
					$IW = ceil($IW / $IH * $maxH);
					$IH = $maxH;
				}
				// Make row:
				$lines[] = '
					<tr class="bgColor4">
						<td nowrap="nowrap">' . $filenameAndIcon . '&nbsp;</td>
						<td nowrap="nowrap">' . ($imgInfo[0] != $IW
						? '<a href="' . htmlspecialchars(GeneralUtility::linkThisScript(array('noLimit' => '1')))
						. '" title="' . $lang->getLL('clickToRedrawFullSize', TRUE) . '">' . $this->iconFactory->getIcon('status-dialog-warning', Icon::SIZE_SMALL)->render()
						. '</a>'
						: '')
					. $pDim . '&nbsp;</td>
					</tr>';
				$lines[] = '
					<tr>
						<td colspan="2"><img src="' . htmlspecialchars($iUrl) . '" data-htmlarea-file-uid="' . $fileObject->getUid()
					. '" width="' . htmlspecialchars($IW) . '" height="' . htmlspecialchars($IH) . '" border="1" alt="" /></td>
					</tr>';
				$lines[] = '
					<tr>
						<td colspan="2"><span style="width: 1px; height: 3px; display: inline-block;"></span></td>
					</tr>';
			}
		}
		// Finally, wrap all rows in a table tag:
		$out .= '


<!--
	Filelisting / Drag-n-drop
-->
			<table border="0" cellpadding="0" cellspacing="1" id="typo3-dragBox">
				' . implode('', $lines) . '
			</table>';

		return $out;
	}

	/******************************************************************
	 *
	 * Miscellaneous functions
	 *
	 ******************************************************************/

	/**
	 * Prints a 'header' where string is in a tablecell
	 *
	 * @param string $str The string to print in the header. The value is htmlspecialchars()'ed before output.
	 * @return string The header HTML (wrapped in a table)
	 */
	public function barheader($str) {
		return '
			<!-- Bar header: -->
			<h3>' . htmlspecialchars($str) . '</h3>
			';
	}

	/**
	 * For RTE/link: This prints the 'currentUrl'
	 *
	 * @param string $str URL value. The value is htmlspecialchars()'ed before output.
	 * @return string HTML content, wrapped in a table.
	 */
	public function printCurrentUrl($str) {
		// Output the folder or file identifier, when working with files
		if (isset($str) && MathUtility::canBeInterpretedAsInteger($str)) {
			try {
				$fileObject = ResourceFactory::getInstance()->retrieveFileOrFolderObject($str);
			} catch (Exception\FileDoesNotExistException $e) {
				$fileObject = NULL;
			}
			$str = is_object($fileObject) ? $fileObject->getIdentifier() : '';
		}
		if ($str !== '') {
			return '
				<!-- Print current URL -->
				<table border="0" cellpadding="0" cellspacing="0" id="typo3-curUrl">
					<tr>
						<td>' . $this->getLanguageService()->getLL('currentLink', TRUE) . ': '
							. htmlspecialchars(rawurldecode($str)) . '</td>
					</tr>
				</table>';
		} else {
			return '';
		}
	}

	/**
	 * For RTE/link: Parses the incoming URL and determines if it's a page, file, external or mail address.
	 *
	 * @param string $href HREF value tp analyse
	 * @param string $siteUrl The URL of the current website (frontend)
	 * @return array[] Array with URL information stored in assoc. keys: value, act (page, file, mail), pageid, cElement, info
	 */
	public function parseCurUrl($href, $siteUrl) {
		$lang = $this->getLanguageService();
		$href = trim($href);
		if ($href) {
			$info = array();
			// Default is "url":
			$info['value'] = $href;
			$info['act'] = 'url';
			if (!StringUtility::beginsWith($href, 'file://') && strpos($href, 'file:') !== FALSE) {
				$rel = substr($href, strpos($href, 'file:') + 5);
				$rel = rawurldecode($rel);
				try {
					// resolve FAL-api "file:UID-of-sys_file-record" and "file:combined-identifier"
					$fileOrFolderObject = ResourceFactory::getInstance()->retrieveFileOrFolderObject($rel);
					if ($fileOrFolderObject instanceof Folder) {
						$info['act'] = 'folder';
						$info['value'] = $fileOrFolderObject->getCombinedIdentifier();
					} elseif ($fileOrFolderObject instanceof File) {
						$info['act'] = 'file';
						$info['value'] = $fileOrFolderObject->getUid();
					} else {
						$info['value'] = $rel;
					}
				} catch (Exception\FileDoesNotExistException $e) {
					// file was deleted or any other reason, don't select any item
					if (MathUtility::canBeInterpretedAsInteger($rel)) {
						$info['act'] = 'file';
					} else {
						$info['act'] = 'folder';
					}
					$info['value'] = '';
				}
			} elseif (StringUtility::beginsWith($href, $siteUrl)) {
				// If URL is on the current frontend website:
				// URL is a file, which exists:
				if (file_exists(PATH_site . rawurldecode($href))) {
					$info['value'] = rawurldecode($href);
					if (@is_dir((PATH_site . $info['value']))) {
						$info['act'] = 'folder';
					} else {
						$info['act'] = 'file';
					}
				} else {
					// URL is a page (id parameter)
					$uP = parse_url($href);

					$pp = preg_split('/^id=/', $uP['query']);
					$pp[1] = preg_replace('/&id=[^&]*/', '', $pp[1]);
					$parameters = explode('&', $pp[1]);
					$id = array_shift($parameters);
					if ($id) {
						// Checking if the id-parameter is an alias.
						if (!MathUtility::canBeInterpretedAsInteger($id)) {
							list($idPartR) = BackendUtility::getRecordsByField('pages', 'alias', $id);
							$id = (int)$idPartR['uid'];
						}
						$pageRow = BackendUtility::getRecordWSOL('pages', $id);
						$titleLen = (int)$this->getBackendUser()->uc['titleLen'];
						$info['value'] = ((((($lang->getLL('page', TRUE) . ' \'')
										. htmlspecialchars(GeneralUtility::fixed_lgd_cs($pageRow['title'], $titleLen)))
										. '\' (ID:') . $id) . ($uP['fragment'] ? ', #' . $uP['fragment'] : '')) . ')';
						$info['pageid'] = $id;
						$info['cElement'] = $uP['fragment'];
						$info['act'] = 'page';
						$info['query'] = $parameters[0] ? '&' . implode('&', $parameters) : '';
					}
				}
			} else {
				// Email link:
				if (strtolower(substr($href, 0, 7)) === 'mailto:') {
					$info['value'] = trim(substr($href, 7));
					$info['act'] = 'mail';
				}
			}
			$info['info'] = $info['value'];
		} else {
			// NO value input:
			$info = array();
			$info['info'] = $lang->getLL('none');
			$info['value'] = '';
			$info['act'] = 'page';
		}
		// let the hook have a look
		foreach ($this->hookObjects as $hookObject) {
			$info = $hookObject->parseCurrentUrl($href, $siteUrl, $info);
		}
		return $info;
	}

	/**
	 * Setter for the class that should be used by TBE_expandPage() to generate the record list.
	 * This method is intended to be used by Extensions that implement their own browsing functionality.
	 *
	 * @param ElementBrowserRecordList $recordList
	 * @return void
	 * @api
	 */
	public function setRecordList(ElementBrowserRecordList $recordList) {
		$this->recordList = $recordList;
	}

	/**
	 * For TBE: Makes an upload form for uploading files to the filemount the user is browsing.
	 * The files are uploaded to the tce_file.php script in the core which will handle the upload.
	 *
	 * @param Folder $folderObject Absolute filepath on server to which to upload.
	 * @return string HTML for an upload form.
	 */
	public function uploadForm(Folder $folderObject) {
		if (!$folderObject->checkActionPermission('write')) {
			return '';
		}
		// Read configuration of upload field count
		$userSetting = $this->getBackendUser()->getTSConfigVal('options.folderTree.uploadFieldsInLinkBrowser');
		$count = isset($userSetting) ? $userSetting : 1;
		if ($count === '0') {
			return '';
		}
		$pArr = explode('|', $this->bparams);
		$allowedExtensions = isset($pArr[3]) ? GeneralUtility::trimExplode(',', $pArr[3], TRUE) : [];

		$count = (int)$count === 0 ? 1 : (int)$count;
		// Create header, showing upload path:
		$header = $folderObject->getIdentifier();
		$lang = $this->getLanguageService();
		// Create a list of allowed file extensions with the readable format "youtube, vimeo" etc.
		$fileExtList = array();
		foreach ($allowedExtensions as $fileExt) {
			if (GeneralUtility::verifyFilenameAgainstDenyPattern($fileExt)) {
				$fileExtList[] = '<span class="label label-success">' . strtoupper(htmlspecialchars($fileExt)) . '</span>';
			}
		}
		$code = '
			<br />
			<!--
				Form, for uploading files:
			-->
			<form action="' . htmlspecialchars(BackendUtility::getModuleUrl('tce_file')) . '" method="post" name="editform"'
			. ' id="typo3-uplFilesForm" enctype="multipart/form-data">
				<table border="0" cellpadding="0" cellspacing="0" id="typo3-uplFiles">
					<tr>
						<td>' . $this->barheader($lang->sL(
								'LLL:EXT:lang/locallang_core.xlf:file_upload.php.pagetitle', TRUE) . ':') . '</td>
					</tr>
					<tr>
						<td class="c-wCell c-hCell"><strong>' . $lang->getLL('path', TRUE) . ':</strong> '
							. htmlspecialchars($header) . '</td>
					</tr>
					<tr>
						<td class="c-wCell c-hCell">';
		// Traverse the number of upload fields (default is 3):
		for ($a = 1; $a <= $count; $a++) {
			$code .= '<input type="file" multiple="multiple" name="upload_' . $a . '[]"' . $this->doc->formWidth(35)
					. ' size="50" />
				<input type="hidden" name="file[upload][' . $a . '][target]" value="'
					. htmlspecialchars($folderObject->getCombinedIdentifier()) . '" />
				<input type="hidden" name="file[upload][' . $a . '][data]" value="' . $a . '" /><br />';
		}
		// Make footer of upload form, including the submit button:
		$redirectValue = $this->getThisScript() . 'act=' . $this->act . '&mode=' . $this->mode
			. '&expandFolder=' . rawurlencode($folderObject->getCombinedIdentifier())
			. '&bparams=' . rawurlencode($this->bparams)
			. (is_array($this->P) ? GeneralUtility::implodeArrayForUrl('P', $this->P) : '');
		$code .= '<input type="hidden" name="redirect" value="' . htmlspecialchars($redirectValue) . '" />';

		if (!empty($fileExtList)) {
			$code .= '
				<div class="help-block">
					' . $lang->sL('LLL:EXT:lang/locallang_core.xlf:cm.allowedFileExtensions', TRUE) . '<br>
					' . implode(' ', $fileExtList) . '
				</div>
			';
		}

		$code .= '
			<div id="c-override">
				<label>
					<input type="checkbox" name="overwriteExistingFiles" id="overwriteExistingFiles" value="1" /> '
					. $lang->sL('LLL:EXT:lang/locallang_misc.xlf:overwriteExistingFiles', TRUE) . '
				</label>
			</div>
			<input class="btn btn-default" type="submit" name="submit" value="'
				. $lang->sL('LLL:EXT:lang/locallang_core.xlf:file_upload.php.submit', TRUE) . '" />
		';
		$code .= '</td>
					</tr>
				</table>
			</form>';

		// Add online media
		// Create a list of allowed file extensions in a readable format "youtube, vimeo" etc.
		$fileExtList = array();
		$onlineMediaFileExt = OnlineMediaHelperRegistry::getInstance()->getSupportedFileExtensions();
		foreach ($onlineMediaFileExt as $fileExt) {
			if (
				GeneralUtility::verifyFilenameAgainstDenyPattern($fileExt)
				&& (empty($allowedExtensions) || in_array($fileExt, $allowedExtensions, TRUE))
			) {
				$fileExtList[] = '<span class="label label-success">' . strtoupper(htmlspecialchars($fileExt)) . '</span>';
			}
		}
		if (!empty($fileExtList)) {
			$code .= '
				<!--
			Form, adding online media urls:
				-->
				<form action="' . htmlspecialchars(BackendUtility::getModuleUrl('online_media')) . '" method="post" name="editform1"'
				. ' id="typo3-addMediaForm">
					<table border="0" cellpadding="0" cellspacing="0" id="typo3-uplFiles">
						<tr>
							<td>' . $this->barheader($lang->sL('LLL:EXT:lang/locallang_core.xlf:online_media.new_media', TRUE) . ':') . '</td>
						</tr>
						<tr>
							<td class="c-wCell c-hCell"><strong>' . $lang->getLL('path', TRUE) . ':</strong> '
				. htmlspecialchars($header) . '</td>
						</tr>
						<tr>
							<td class="c-wCell c-hCell">
								<input type="text" name="file[newMedia][0][url]"' . $this->doc->formWidth(35)
				. ' size="50" placeholder="' . $lang->sL('LLL:EXT:lang/locallang_core.xlf:online_media.new_media.placeholder', TRUE) . '" />
					<input type="hidden" name="file[newMedia][0][target]" value="'
				. htmlspecialchars($folderObject->getCombinedIdentifier()) . '" />
					<input type="hidden" name="file[newMedia][0][allowed]" value="'
				. htmlspecialchars(implode(',', $allowedExtensions)) . '" />
					<button>' . $lang->sL('LLL:EXT:lang/locallang_core.xlf:online_media.new_media.submit', TRUE) . '</button>
					<div class="help-block">
						' . $lang->sL('LLL:EXT:lang/locallang_core.xlf:online_media.new_media.allowedProviders') . '<br>
						' . implode(' ', $fileExtList) . '
					</div>
						';
		}

		// Make footer of upload form, including the submit button:
		$redirectValue = $this->getThisScript()
			. 'act=' . $this->act
			. '&mode=' . $this->mode
			. '&expandFolder=' . rawurlencode($folderObject->getCombinedIdentifier())
			. '&bparams=' . rawurlencode($this->bparams)
			. (is_array($this->P) ? GeneralUtility::implodeArrayForUrl('P', $this->P) : '');
		$code .= '<input type="hidden" name="redirect" value="' . htmlspecialchars($redirectValue) . '" />';

		$code .= '</td>
					</tr>
				</table>
			</form><br />';


		return $code;
	}

	/**
	 * For TBE: Makes a form for creating new folders in the filemount the user is browsing.
	 * The folder creation request is sent to the tce_file.php script in the core which will handle the creation.
	 *
	 * @param Folder $folderObject Absolute filepath on server in which to create the new folder.
	 * @return string HTML for the create folder form.
	 */
	public function createFolder(Folder $folderObject) {
		if (!$folderObject->checkActionPermission('write')) {
			return '';
		}
		$backendUser = $this->getBackendUser();
		if (!($backendUser->isAdmin() || $backendUser->getTSConfigVal('options.createFoldersInEB'))) {
			return '';
		}
		// Don't show Folder-create form if it's denied
		if ($backendUser->getTSConfigVal('options.folderTree.hideCreateFolder')) {
			return '';
		}
		$lang = $this->getLanguageService();
		// Create header, showing upload path:
		$header = $folderObject->getIdentifier();
		$code = '

			<!--
				Form, for creating new folders:
			-->
			<form action="' . htmlspecialchars(BackendUtility::getModuleUrl('tce_file')) . '" method="post" name="editform2" id="typo3-crFolderForm">
				<table border="0" cellpadding="0" cellspacing="0" id="typo3-crFolder">
					<tr>
						<td>' . $this->barheader($lang->sL(
								'LLL:EXT:lang/locallang_core.xlf:file_newfolder.php.pagetitle') . ':') . '</td>
					</tr>
					<tr>
						<td class="c-wCell c-hCell"><strong>'
							. $lang->getLL('path', TRUE) . ':</strong> ' . htmlspecialchars($header) . '</td>
					</tr>
					<tr>
						<td class="c-wCell c-hCell">';
		// Create the new-folder name field:
		$a = 1;
		$code .= '<input' . $this->doc->formWidth(20) . ' type="text" name="file[newfolder][' . $a . '][data]" />'
				. '<input type="hidden" name="file[newfolder][' . $a . '][target]" value="'
				. htmlspecialchars($folderObject->getCombinedIdentifier()) . '" />';
		// Make footer of upload form, including the submit button:
		$redirectValue = $this->getThisScript() . 'act=' . $this->act . '&mode=' . $this->mode
			. '&expandFolder=' . rawurlencode($folderObject->getCombinedIdentifier())
			. '&bparams=' . rawurlencode($this->bparams)
			. (is_array($this->P) ? GeneralUtility::implodeArrayForUrl('P', $this->P) : '');
		$code .= '<input type="hidden" name="redirect" value="' . htmlspecialchars($redirectValue) . '" />'
			. '<input class="btn btn-default" type="submit" name="submit" value="'
			. $lang->sL('LLL:EXT:lang/locallang_core.xlf:file_newfolder.php.submit', TRUE) . '" />';
		$code .= '</td>
					</tr>
				</table>
			</form>';
		return $code;
	}

	/**
	 * Get the HTML data required for a bulk selection of files of the TYPO3 Element Browser.
	 *
	 * @param int $filesCount Number of files currently displayed
	 * @return string HTML data required for a bulk selection of files - if $filesCount is 0, nothing is returned
	 */
	public function getBulkSelector($filesCount) {
		if (!$filesCount) {
			return '';
		}

		$lang = $this->getLanguageService();
		$labelToggleSelection = $lang->sL('LLL:EXT:lang/locallang_browse_links.xlf:toggleSelection', TRUE);
		$labelImportSelection = $lang->sL('LLL:EXT:lang/locallang_browse_links.xlf:importSelection', TRUE);
		// Getting flag for showing/not showing thumbnails:
		$noThumbsInEB = $this->getBackendUser()->getTSConfigVal('options.noThumbsInEB');
		$out = $this->doc->spacer(10) . '<div>' . '<a href="#" onclick="BrowseLinks.Selector.handle()"'
			. 'title="' . $labelImportSelection . '">'
			. $this->iconFactory->getIcon('actions-document-import-t3d', Icon::SIZE_SMALL)->render()
			. $labelImportSelection . '</a>&nbsp;&nbsp;&nbsp;'
			. '<a href="#" onclick="BrowseLinks.Selector.toggle()" title="' . $labelToggleSelection . '">'
			. $this->iconFactory->getIcon('actions-document-select', Icon::SIZE_SMALL)->render()
			. $labelToggleSelection . '</a>' . '</div>';
		if (!$noThumbsInEB && $this->selectedFolder) {
			// MENU-ITEMS, fetching the setting for thumbnails from File>List module:
			$_MOD_MENU = array('displayThumbs' => '');
			$_MCONF['name'] = 'file_list';
			$_MOD_SETTINGS = BackendUtility::getModuleData($_MOD_MENU, GeneralUtility::_GP('SET'), $_MCONF['name']);
			$addParams = '&act=' . $this->act . '&mode=' . $this->mode
				. '&expandFolder=' . rawurlencode($this->selectedFolder->getCombinedIdentifier())
				. '&bparams=' . rawurlencode($this->bparams);
			$thumbNailCheck = '<div class="checkbox"><label for="checkDisplayThumbs">' . BackendUtility::getFuncCheck('', 'SET[displayThumbs]', $_MOD_SETTINGS['displayThumbs'],
					GeneralUtility::_GP('M') ? '' : $this->thisScript, $addParams, 'id="checkDisplayThumbs"')
				. $lang->sL('LLL:EXT:lang/locallang_mod_file_list.xlf:displayThumbs', TRUE) . '</label></div>';
			$out .= $this->doc->spacer(5) . $thumbNailCheck . $this->doc->spacer(15);
		} else {
			$out .= $this->doc->spacer(15);
		}
		return $out;
	}

	/**
	 * Get the HTML data required for the file search field of the TYPO3 Element Browser.
	 *
	 * @return string HTML data required for the search field in the file list of the Element Browser
	 */
	protected function getFileSearchField() {
		$action = $this->getThisScript() . 'act=' . $this->act . '&mode=' . $this->mode
			. '&bparams=' . rawurlencode($this->bparams)
			. (is_array($this->P) ? GeneralUtility::implodeArrayForUrl('P', $this->P) : '');
		$out = '
			<form method="post" action="' . htmlspecialchars($action) . '">
				<div class="input-group">
					<input class="form-control" type="text" name="searchWord" value="' . htmlspecialchars($this->searchWord) . '">
					<span class="input-group-btn">
						<button class="btn btn-default" type="submit">' . $this->getLanguageService()->sL('LLL:EXT:filelist/Resources/Private/Language/locallang.xlf:search', TRUE) .'</button>
					</span>
				</div>
			</form>';
		$out .= $this->doc->spacer(15);
		return $out;
	}

	/**
	 * Determines whether submitted field change functions are valid
	 * and are coming from the system and not from an external abuse.
	 *
	 * @param bool $handleFlexformSections Whether to handle flexform sections differently
	 * @return bool Whether the submitted field change functions are valid
	 */
	protected function areFieldChangeFunctionsValid($handleFlexformSections = FALSE) {
		$result = FALSE;
		if (isset($this->P['fieldChangeFunc']) && is_array($this->P['fieldChangeFunc']) && isset($this->P['fieldChangeFuncHash'])) {
			$matches = array();
			$pattern = '#\\[el\\]\\[(([^]-]+-[^]-]+-)(idx\\d+-)([^]]+))\\]#i';
			$fieldChangeFunctions = $this->P['fieldChangeFunc'];
			// Special handling of flexform sections:
			// Field change functions are modified in JavaScript, thus the hash is always invalid
			if ($handleFlexformSections && preg_match($pattern, $this->P['itemName'], $matches)) {
				$originalName = $matches[1];
				$cleanedName = $matches[2] . $matches[4];
				foreach ($fieldChangeFunctions as &$value) {
					$value = str_replace($originalName, $cleanedName, $value);
				}
				unset($value);
			}
			$result = $this->P['fieldChangeFuncHash'] === GeneralUtility::hmac(serialize($fieldChangeFunctions));
		}
		return $result;
	}

	/**
	 * Check if a temporary tree mount is set and return a cancel button
	 *
	 * @return string
	 */
	protected function getTemporaryTreeMountCancelNotice() {
		if ((int)$this->getBackendUser()->getSessionData('pageTree_temporaryMountPoint') === 0) {
			return '';
		}
		$link = '<a href="' . htmlspecialchars(GeneralUtility::linkThisScript(array('setTempDBmount' => 0))) . '">'
			. $this->getLanguageService()->sl('LLL:EXT:lang/locallang_core.xlf:labels.temporaryDBmount', TRUE) . '</a>';
		/** @var FlashMessage $flashMessage */
		$flashMessage = GeneralUtility::makeInstance(
			FlashMessage::class,
			$link,
			'',
			FlashMessage::INFO
		);
		return $flashMessage->render();
	}

	/**
	 * Get a list of Files in a folder filtered by extension
	 *
	 * @param Folder $folder
	 * @param string $extensionList
	 * @return File[]
	 */
	protected function getFilesInFolder(Folder $folder, $extensionList) {
		if ($extensionList !== '') {
			/** @var FileExtensionFilter $filter */
			$filter = GeneralUtility::makeInstance(FileExtensionFilter::class);
			$filter->setAllowedFileExtensions($extensionList);
			$folder->setFileAndFolderNameFilters(array(array($filter, 'filterFileList')));
		}
		return $folder->getFiles();
	}

	/**
	 * @return LanguageService
	 */
	protected function getLanguageService() {
		return $GLOBALS['LANG'];
	}

	/**
	 * @return BackendUserAuthentication
	 */
	protected function getBackendUser() {
		return $GLOBALS['BE_USER'];
	}

	/**
	 * @return DatabaseConnection
	 */
	protected function getDatabaseConnection() {
		return $GLOBALS['TYPO3_DB'];
	}

	/**
	 * @return PageRenderer
	 */
	protected function getPageRenderer() {
		if ($this->pageRenderer === NULL) {
			$this->pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
		}
		return $this->pageRenderer;
	}

}
