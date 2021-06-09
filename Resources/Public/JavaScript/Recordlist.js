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
var __importDefault=this&&this.__importDefault||function(t){return t&&t.__esModule?t:{default:t}};define(["require","exports","jquery","TYPO3/CMS/Backend/Icons","TYPO3/CMS/Backend/Storage/Persistent","TYPO3/CMS/Core/Event/RegularEvent","TYPO3/CMS/Backend/Tooltip","TYPO3/CMS/Core/DocumentService"],(function(t,e,a,i,l,n,s,d){"use strict";a=__importDefault(a);return new class{constructor(){this.identifier={entity:".t3js-entity",toggle:".t3js-toggle-recordlist",localize:".t3js-action-localize",icons:{collapse:"actions-view-list-collapse",expand:"actions-view-list-expand",editMultiple:".t3js-record-edit-multiple"}},this.toggleClick=t=>{t.preventDefault();const e=a.default(t.currentTarget),n=e.data("table"),s=a.default(e.data("bs-target")),d="expanded"===s.data("state"),r=e.find(".collapseIcon"),o=d?this.identifier.icons.expand:this.identifier.icons.collapse;i.getIcon(o,i.sizes.small).done(t=>{r.html(t)});let c={};l.isset("moduleData.list")&&(c=l.get("moduleData.list"));const u={};u[n]=d?1:0,a.default.extend(c,u),l.set("moduleData.list",c).done(()=>{s.data("state",d?"collapsed":"expanded")})},this.onEditMultiple=t=>{let e,i,l,n,s;t.preventDefault(),e=a.default(t.currentTarget).closest("[data-table]"),0!==e.length&&(n=a.default(t.currentTarget).data("uri"),i=e.data("table"),l=e.find(this.identifier.entity+'[data-uid][data-table="'+i+'"]').map((t,e)=>a.default(e).data("uid")).toArray().join(","),s=n.match(/{[^}]+}/g),a.default.each(s,(t,e)=>{const s=e.substr(1,e.length-2).split(":");let d;switch(s.shift()){case"entityIdentifiers":d=l;break;case"T3_THIS_LOCATION":d=T3_THIS_LOCATION;break;default:return}a.default.each(s,(t,e)=>{"editList"===e&&(d=this.editList(i,d))}),n=n.replace(e,d)}),window.location.href=n)},this.disableButton=t=>{a.default(t.currentTarget).prop("disable",!0).addClass("disabled")},this.deleteRow=t=>{const e=a.default(`table[data-table="${t.table}"]`),i=e.find(`tr[data-uid="${t.uid}"]`),l=e.closest(".panel"),n=l.find(".panel-heading"),s=e.find(`[data-l10nparent="${t.uid}"]`),d=a.default().add(i).add(s);if(d.fadeTo("slow",.4,()=>{d.slideUp("slow",()=>{d.remove(),0===e.find("tbody tr").length&&l.slideUp("slow")})}),"0"===i.data("l10nparent")||""===i.data("l10nparent")){const t=Number(n.find(".t3js-table-total-items").html());n.find(".t3js-table-total-items").text(t-1)}"pages"===t.table&&top.document.dispatchEvent(new CustomEvent("typo3:pagetree:refresh"))},this.registerPaginationEvents=()=>{document.querySelectorAll(".t3js-recordlist-paging").forEach(t=>{t.addEventListener("keyup",e=>{e.preventDefault();let a=parseInt(t.value,10);a<parseInt(t.min,10)&&(a=parseInt(t.min,10)),a>parseInt(t.max,10)&&(a=parseInt(t.max,10)),"Enter"===e.key&&a!==parseInt(t.dataset.currentpage,10)&&(window.location.href=t.dataset.currenturl+a.toString())})})},this.registerColumnSelectorEvents=()=>{document.querySelectorAll(".recordlist-select-allcolumns").forEach(t=>{t.addEventListener("change",e=>{t.closest("form").querySelectorAll(".recordlist-select-column").forEach(t=>{t.disabled||(t.checked=!t.checked)})})})},a.default(document).on("click",this.identifier.toggle,this.toggleClick),a.default(document).on("click",this.identifier.icons.editMultiple,this.onEditMultiple),a.default(document).on("click",this.identifier.localize,this.disableButton),d.ready().then(()=>{s.initialize(".table-fit a[title]"),this.registerPaginationEvents()}),d.ready().then(()=>{this.registerColumnSelectorEvents()}),new n("typo3:datahandler:process",this.handleDataHandlerResult.bind(this)).bindTo(document)}editList(t,e){const a=[];let i=0,l=e.indexOf(",");for(;-1!==l;)this.getCheckboxState(t+"|"+e.substr(i,l-i))&&a.push(e.substr(i,l-i)),i=l+1,l=e.indexOf(",",i);return this.getCheckboxState(t+"|"+e.substr(i))&&a.push(e.substr(i)),a.length>0?a.join(","):e}handleDataHandlerResult(t){const e=t.detail.payload;e.hasErrors||"datahandler"!==e.component&&"delete"===e.action&&this.deleteRow(e)}getCheckboxState(t){const e="CBC["+t+"]",a=document.querySelector('form[name="dblistForm"] [name="'+e+'"]');return null!==a&&a.checked}}}));