{* Wiki individuals and groups only - see list_wiki.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="listing contacts">
	<div class="header">
		<h1>{tr}Wiki Contacts{/tr}</h1>
	</div>

	<div class="body">
		<div class="navbar">
			{form class="find" legend="Find in Wiki Contacts" id="data_options"}
				<input type="hidden" name="sort_mode" value="{$smarty.request.sort_mode|escape}" />
				<label class="col-md-6 col-sm-6 col-xs-12">{tr}Name{/tr}:&nbsp;<input size="24" type="text" name="find_title" value="{$smarty.request.find_title|escape}" /></label>
				<div class="col-md-3 col-sm-3 col-xs-12">
					<input type="submit" name="search" value="{tr}Find{/tr}" />&nbsp;
					<input type="button" onclick="location.href='{$smarty.const.CONTACTWIKI_PKG_URL}list_wiki.php'" value="{tr}Reset{/tr}" />
				</div>
			{/form}
		</div>

		<div class="table-responsive">
			<table class="table table-condensed">
				<caption>{tr}Wiki contacts{/tr} <span class="total">[ {$listInfo.total_records} ]</span></caption>
				<tr>
					<th>{smartlink ititle="Name" isort="title" idefault=1 iorder=asc ihash=$listInfo.ihash|default:''}</th>
					<th>{tr}Type{/tr}</th>
					<th>{tr}Dates{/tr}</th>
					<th>{tr}Wikidata{/tr}</th>
				</tr>
				{foreach from=$listWiki item=row}
					<tr>
						<td><a href="{$row.view_url|escape}">{$row.title|escape}</a></td>
						<td>{if $row.is_group}{tr}Group{/tr}{else}{tr}Individual{/tr}{/if}</td>
						<td>{if $row.date_from || $row.date_to}{$row.date_from|escape}{if $row.date_to} &ndash; {$row.date_to|escape}{/if}{/if}</td>
						<td>{if $row.wikidata_qid}<a href="https://www.wikidata.org/wiki/{$row.wikidata_qid|escape}" target="_blank" rel="noopener">{$row.wikidata_qid|escape}</a>{/if}</td>
					</tr>
				{foreachelse}
					<tr class="norecords"><td colspan="4">{tr}No records found{/tr}</td></tr>
				{/foreach}
			</table>
		</div>
		{pagination}
	</div>
</div>
{/strip}
