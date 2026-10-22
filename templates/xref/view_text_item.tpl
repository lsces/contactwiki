{* Row view for a wiki contact's text item - see edit_text_item.tpl: the value is xkey_ext (falling back to xkey for a row typed on
   the generic form), the note xkey. *}
{strip}
<td>{$xrefInfo.xref_title|escape}</td>
<td>{if $xrefInfo.xkey_ext ne ''}{$xrefInfo.xkey_ext|escape}{else}{$xrefInfo.xkey|escape}{/if}</td>
<td>{if $xrefInfo.xkey_ext ne ''}{$xrefInfo.xkey|escape}{/if}</td>
{include file="bitpackage:liberty/xref/dates_cell.tpl"}
{include file="bitpackage:liberty/xref/action_icons.tpl"}
{/strip}
