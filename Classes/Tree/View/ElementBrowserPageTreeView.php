<?php
namespace TYPO3\CMS\Recordlist\Tree\View;

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

use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Extension class for the TBE record browser
 */
class ElementBrowserPageTreeView extends \TYPO3\CMS\Backend\Tree\View\ElementBrowserPageTreeView {

	/**
	 * Returns TRUE if a doktype can be linked (which is always the case here).
	 *
	 * @param int $doktype Doktype value to test
	 * @param int $uid uid to test.
	 * @return bool
	 */
	public function ext_isLinkable($doktype, $uid) {
		return TRUE;
	}

	/**
	 * Wrapping the title in a link, if applicable.
	 *
	 * @param string $title Title, ready for output.
	 * @param array $row The record
	 * @param bool $ext_pArrPages If set, pages clicked will return immediately, otherwise reload page.
	 * @return string Wrapping title string.
	 */
	public function wrapTitle($title, $row, $ext_pArrPages) {
		if ($ext_pArrPages && $row['uid']) {
			$iconFactory = GeneralUtility::makeInstance(IconFactory::class);
			$ficon = $iconFactory->getIconForRecord('pages', $row, Icon::SIZE_SMALL)->render();
			$out = '<span data-uid="' . htmlspecialchars($row['uid']) . '" data-table="pages" data-title="' . htmlspecialchars($row['title']) . '" data-icon="' . htmlspecialchars($ficon) . '">';
			$out .= '<a href="#" data-close="1">' . $title . '</a>';
			$out .= '</span>';
			return $out;
		}

		$parameters = GeneralUtility::implodeArrayForUrl('', $this->linkParameterProvider->getUrlParameters(['pid' => $row['uid']]));
		return '<a href="#" onclick="return jumpToUrl(' . htmlspecialchars(GeneralUtility::quoteJSvalue($this->getThisScript() . ltrim($parameters, '&'))) . ');">' . $title . '</a>';
	}

}
