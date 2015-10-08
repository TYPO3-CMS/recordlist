/**
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

/**
 * Mail link interaction
 */
define('TYPO3/CMS/Recordlist/MailLinkHandler', ['jquery', 'TYPO3/CMS/Recordlist/LinkBrowser'], function($, LinkBrowser) {
	"use strict";

	var MailLinkHandler = {};

	MailLinkHandler.link = function(event) {
		event.preventDefault();

		var value = $(this).find('[name="lemail"]').val();
		if (value === "mailto:") {
			return;
		}

		while (value.substr(0, 7) === "mailto:") {
			value = value.substr(7);
		}

		LinkBrowser.updateValueInMainForm(value);

		close();
	};

	$(function() {
		$('#lmailform').on('submit', MailLinkHandler.link);
	});

	return MailLinkHandler;
});
