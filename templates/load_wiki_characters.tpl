{* Link films' character rows to character contacts - see load_wiki_characters.php. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Load Wiki Characters{/tr}{if $programId} - {tr}one show{/tr}{/if}</h1>
	</div>

	<div class="body">
		{if $roleResult}
			<div class="alert {if $roleResult.errors}alert-warning{else}alert-success{/if}">
				<p>{$roleResult.created|@count} {tr}character contacts created{/tr}, {$roleResult.joined} {tr}roles joined to a contact they already had{/tr}, {$roleResult.linked} {tr}cast rows linked{/tr} ({$roleResult.seconds}s).{if $roleResult.left} {$roleResult.left} {tr}ticked roles are still to do - press Create again.{/tr}{/if}</p>
				{if $roleResult.created}<ul>{foreach from=$roleResult.created item=c}<li><a href="{$smarty.const.CONTACTWIKI_PKG_URL}view.php?content_id={$c.content_id}">{$c.title|escape}</a></li>{/foreach}</ul>{/if}
				{foreach from=$roleResult.errors item=e}<p class="text-danger">{$e|escape}</p>{/foreach}
			</div>
		{/if}
		{if $programId}
			<h2>{tr}Recurring roles{/tr}: {$showTitle|escape}</h2>
			<p>{tr}Roles Plex gives this show's cast that appear in at least{/tr} {$minSeasons} {tr}seasons and have no character contact yet. Ticking one makes a character contact named from the role (searchable by any word of it, and by the actor) and links every cast row for that role - across all seasons, and the shortened forms of it. Roles played by a different actor each time are probably a job, not a character, and are not ticked.{/tr}
				{tr}Show roles in at least{/tr}
				{foreach from=[2,3,4,5,8] item=m}{if $m == $minSeasons}<strong>{$m}</strong>{else}<a href="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_characters.php?program_id={$programId}&amp;min={$m}">{$m}</a>{/if} {/foreach}{tr}seasons{/tr}.</p>
			{if $roleGroups}
				{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_characters.php"}
					<input type="hidden" name="program_id" value="{$programId}" /><input type="hidden" name="min" value="{$minSeasons}" />
					<p><input type="submit" class="btn btn-primary" name="fCreateRoles" value="{tr}Create / Link Selected{/tr}" /></p>
					<table class="table table-condensed">
						<thead><tr><th><input type="checkbox" id="role-toggle-all" title="{tr}Tick or clear all{/tr}" onclick="var b=document.querySelectorAll('input.role-pick');for(var i=0;i<b.length;i++){ldelim}b[i].checked=this.checked;{rdelim}" /></th><th>{tr}Role{/tr}</th><th>{tr}Played by{/tr}</th><th>{tr}Seasons{/tr}</th><th>{tr}Cast rows to link{/tr}</th></tr></thead>
						<tbody>
						{foreach from=$roleGroups item=g}
							<tr>
								<td><input type="checkbox" class="role-pick" name="role[]" value="{$g.key|escape}"{if $g.actors|@count <= 2} checked="checked"{/if} /></td>
								<td>{$g.role|escape}{if $g.variants|@count > 1} <span class="text-muted">({tr}also{/tr} {foreach from=$g.variants item=v name=vv}{if !$smarty.foreach.vv.first}{$v|escape}{if !$smarty.foreach.vv.last}, {/if}{/if}{/foreach})</span>{/if}{if $g.existing} <span class="text-muted">- {tr}joins its existing contact{/tr}</span>{/if}</td>
								<td>{foreach from=$g.actors item=a name=aa}{if $smarty.foreach.aa.iteration <= 4}{$a|escape}{if !$smarty.foreach.aa.last}, {/if}{/if}{/foreach}{if $g.actors|@count > 4} <span class="text-muted"><span style="margin:0 0.4em;">...</span>{tr}and{/tr} {$g.actors|@count - 4} {tr}more{/tr}</span>{/if}{if $g.actors|@count > 2} <span class="text-warning" title="{tr}Several different actors - probably a job, not one character{/tr}">&#9888;</span>{/if}</td>
								<td>{$g.seasons}</td>
								<td>{$g.xref_ids|@count}</td>
							</tr>
						{/foreach}
						</tbody>
					</table>
				{/form}
			{else}
				<p>{tr}No recurring roles waiting for a character contact.{/tr}</p>
			{/if}
			<h2>{tr}Characters Wikidata knows{/tr}</h2>
		{/if}
		{if $result}
			<div class="alert {if $result.throttled}alert-warning{else}alert-success{/if}">
				<p>{$result.films} {tr}films checked{/tr}, {$result.linked} {tr}characters linked{/tr}{if $result.created}, {$result.created|@count} {tr}new character contacts{/tr}{/if}{if $result.ambiguous}, {$result.ambiguous} {tr}left alone (the actor plays several characters and none matches the role){/tr}{/if} ({$result.seconds}s).
				{if $result.throttled}{tr}Wikidata could not be asked just now - try again in a few minutes.{/tr}{/if}</p>
				{if $result.created}<ul>{foreach from=$result.created item=c}<li><a href="{$smarty.const.CONTACTWIKI_PKG_URL}view.php?content_id={$c.content_id}">{$c.title|escape}</a></li>{/foreach}</ul>{/if}
				{if $result.next !== null}
					{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_characters.php"}
						<input type="hidden" name="after" value="{$result.next}" />{if $programId}<input type="hidden" name="program_id" value="{$programId}" />{/if}
						<input type="submit" class="btn btn-primary" name="fLoad" value="{tr}Continue{/tr}" />
					{/form}
				{/if}
			</div>
		{/if}
		<p>{$linkedRows} {tr}character rows are linked to a character contact;{/tr} {$unlinked} {tr}are not yet. A character is matched through its film's Wikidata cast list, so the film's actors need to be linked to their contacts first (the film people pass).{/tr}</p>
		{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_characters.php"}
			{if $programId}<input type="hidden" name="program_id" value="{$programId}" />{/if}
			<input type="submit" class="btn btn-primary" name="fLoad" value="{tr}Link characters{/tr}" />
		{/form}
	</div>
</div>
{/strip}
