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

import * as $ from 'jquery';

interface LinkAttributes {
  [s: string]: any;
}

declare global {
  interface Window {
    jumpToUrl: Function;
  }
}

/**
 * Module: TYPO3/CMS/Recordlist/LinkBrowser
 * API for tooltip windows powered by Twitter Bootstrap.
 * @exports TYPO3/CMS/Recordlist/Tooltip
 */
class LinkBrowser {
  private thisScriptUrl: string = '';
  private urlParameters: Object = {};
  private parameters: Object = {};
  private addOnParams: string = '';
  private linkAttributeFields: Array<any>;
  private additionalLinkAttributes: LinkAttributes = {};

  constructor() {
    $((): void => {
      const data = $('body').data();

      this.thisScriptUrl = data.thisScriptUrl;
      this.urlParameters = data.urlParameters;
      this.parameters = data.parameters;
      this.addOnParams = data.addOnParams;
      this.linkAttributeFields = data.linkAttributeFields;

      $('.t3js-targetPreselect').on('change', this.loadTarget);
      $('form.t3js-dummyform').on('submit', (evt: JQueryEventObject): void => {
        evt.preventDefault();
      });
    });

    /**
     * Global jumpTo function
     *
     * Used by tree implementation
     *
     * @param {String} URL
     * @param {String} anchor
     * @returns {Boolean}
     */
    window.jumpToUrl = (URL: string, anchor?: string) => {
      if (URL.charAt(0) === '?') {
        URL = this.thisScriptUrl + URL.substring(1);
      }
      const urlParameters = this.encodeGetParameters(this.urlParameters, '', URL);
      const parameters = this.encodeGetParameters(this.getLinkAttributeValues(), 'linkAttributes', '');

      window.location.href = URL + urlParameters + parameters + this.addOnParams + (typeof(anchor) === 'string' ? anchor : '');
      return false;
    };
  }

  public getLinkAttributeValues(): Object {
    const attributeValues: LinkAttributes = {};
    $.each(this.linkAttributeFields, (index: number, fieldName: string) => {
      const val: string = $('[name="l' + fieldName + '"]').val();
      if (val) {
        attributeValues[fieldName] = val;
      }
    });
    $.extend(attributeValues, this.additionalLinkAttributes);
    return attributeValues;
  }

  public loadTarget = (evt: JQueryEventObject): void => {
    const $element = $(evt.currentTarget);
    $('.t3js-linkTarget').val($element.val());
    (<HTMLSelectElement>$element.get(0)).selectedIndex = 0;
  }

  /**
   * Encode objects to GET parameter arrays in PHP notation
   */
  public encodeGetParameters(obj: LinkAttributes, prefix: string, url: string): string {
    const str = [];
    for (let p in obj) {
      if (obj.hasOwnProperty(p)) {
        const k: string = prefix ? prefix + '[' + p + ']' : p;
        const v: any = obj[p];
        if (url.indexOf(k + '=') === -1) {
          str.push(
            typeof v === 'object'
              ? this.encodeGetParameters(v, k, url)
              : encodeURIComponent(k) + '=' + encodeURIComponent(v),
          );
        }
      }
    }
    return '&' + str.join('&');
  }

  /**
   * Set an additional attribute for the link
   */
  public setAdditionalLinkAttribute(name: string, value: any): void {
    this.additionalLinkAttributes[name] = value;
  }

  /**
   * Stores the final link
   *
   * This method MUST be overridden in the actual implementation of the link browser.
   * The function is responsible for encoding the link (and possible link attributes) and
   * returning it to the caller (e.g. FormEngine, RTE, etc)
   *
   * @param {String} link The select element or anything else which identifies the link (e.g. "page:<pageUid>" or "file:<uid>")
   */
  public finalizeFunction(link: string): void {
    throw 'The link browser requires the finalizeFunction to be set. Seems like you discovered a major bug.';
  }
}

export = new LinkBrowser();
