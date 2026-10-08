{* Link films' character rows to character contacts - see load_wiki_characters.php. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Load Wiki Characters{/tr}</h1>
	</div>

	<div class="body">
		{if $result}
			<div class="alert {if $result.throttled}alert-warning{else}alert-success{/if}">
				<p>{$result.films} {tr}films checked{/tr}, {$result.linked} {tr}characters linked{/tr}{if $result.created}, {$result.created|@count} {tr}new character contacts{/tr}{/if}{if $result.ambiguous}, {$result.ambiguous} {tr}left alone (the actor plays several characters and none matches the role){/tr}{/if} ({$result.seconds}s).
				{if $result.throttled}{tr}Wikidata could not be asked just now - try again in a few minutes.{/tr}{/if}</p>
				{if $result.created}<ul>{foreach from=$result.created item=c}<li><a href="{$smarty.const.CONTACTWIKI_PKG_URL}view.php?content_id={$c.content_id}">{$c.title|escape}</a></li>{/foreach}</ul>{/if}
				{if $result.next !== null}
					{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_characters.php"}
						<input type="hidden" name="after" value="{$result.next}" />
						<input type="submit" class="btn btn-primary" name="fLoad" value="{tr}Continue{/tr}" />
					{/form}
				{/if}
			</div>
		{/if}
		<p>{$linkedRows} {tr}character rows are linked to a character contact;{/tr} {$unlinked} {tr}are not yet. A character is matched through its film's Wikidata cast list, so the film's actors need to be linked to their contacts first (the film people pass).{/tr}</p>
		{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_characters.php"}
			<input type="submit" class="btn btn-primary" name="fLoad" value="{tr}Link characters{/tr}" />
		{/form}
	</div>
</div>
{/strip}
