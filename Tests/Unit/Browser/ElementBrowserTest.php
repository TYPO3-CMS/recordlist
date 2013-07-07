<?php
namespace TYPO3\CMS\Recordlist\Tests\Unit\Browser;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Philipp Gampe <philipp.gampe@typo3.org>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * Test case
 */
class ElementBrowserTest extends \TYPO3\CMS\Core\Tests\UnitTestCase {

	/**
	 * @test
	 */
	public function printCurrentUrlWithAnyTextReturnsThatText() {
		$fixture = new \TYPO3\CMS\Recordlist\Browser\ElementBrowser();
		$fixture->act = 'file';
		$result = $fixture->printCurrentUrl('Teststring');
		$this->assertContains('Teststring', $result);
	}
}
?>