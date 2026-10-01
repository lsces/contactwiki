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
				{include file="bitpackage:contact/list_filter_inc.tpl"}
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
					<th>{tr}Types{/tr}</th>
					<th>{tr}Dates{/tr}</th>
					<th>{tr}Wikidata{/tr}</th>
					<th>{tr}Gallery{/tr}</th>
				</tr>
				{foreach from=$listWiki item=row}
					{assign var=ws value=$row.wiki_summary}
					<tr>
						<td><a href="{$row.view_url|escape}">{$row.title|escape}</a></td>
						<td>{if $ws.types}{$ws.types|join:', '|escape}{else}<span class="text-muted">{if $ws.is_group}{tr}Group{/tr}{else}{tr}Individual{/tr}{/if}</span>{/if}</td>
						<td>{if $ws.date_from || $ws.date_to}{$ws.date_from|escape}{if $ws.date_to} &ndash; {$ws.date_to|escape}{/if}{/if}</td>
						<td>{if $ws.qid}<a href="https://www.wikidata.org/wiki/{$ws.qid|escape}" target="_blank" rel="noopener">{$ws.qid|escape}</a>{elseif $ws.mbid}<a class="text-muted" href="https://musicbrainz.org/artist/{$ws.mbid|escape}" target="_blank" rel="noopener">{tr}MusicBrainz only{/tr}</a>{/if}</td>
						<td>{if $ws.gallery_url}<a href="{$ws.gallery_url|escape}">{tr}Gallery{/tr}</a>{/if}</td>
					</tr>
				{foreachelse}
					<tr class="norecords"><td colspan="5">{tr}No records found{/tr}</td></tr>
				{/foreach}
			</table>
		</div>
		{pagination}
	</div>
</div>
{/strip}
