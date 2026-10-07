{* Fill in missing wiki contact photos - see load_wiki_photos.php. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Load Wiki Photos{/tr}</h1>
	</div>

	<div class="body">
		{if $result}
			<div class="alert {if $result.throttled}alert-warning{else}alert-success{/if}">
				<p>{$result.done} {tr}photos loaded{/tr}{if $result.failed}, {$result.failed} {tr}could not be loaded{/tr}{/if} ({$result.seconds}s).
				{if $result.throttled}{tr}Wikimedia is refusing photo downloads at the moment - try again in a few minutes; nothing is lost.{/tr}{/if}</p>
				{if $result.contacts}<ul>{foreach from=$result.contacts item=c}<li><a href="{$smarty.const.CONTACTWIKI_PKG_URL}view.php?content_id={$c.content_id}">{$c.title|escape}</a></li>{/foreach}</ul>{/if}
				<p>{$result.missing} {tr}contacts still have a Wikidata photo not yet loaded.{/tr}</p>
				{if $result.next !== null}
					{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_photos.php"}
						<input type="hidden" name="after" value="{$result.next}" />
						<input type="submit" class="btn btn-primary" name="fLoad" value="{tr}Continue{/tr}" />
					{/form}
				{/if}
			</div>
		{else}
			<p>{$missing} {tr}wiki contacts have a photo on Wikidata that has not been loaded (contacts created while Wikimedia was throttling photo downloads, or without the Fetch photos option).{/tr}</p>
			{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_photos.php"}
				<input type="submit" class="btn btn-primary" name="fLoad" value="{tr}Load missing photos{/tr}" title="{tr}Downloads them a few at a time; stops by itself if Wikimedia starts refusing{/tr}" />
			{/form}
		{/if}
	</div>
</div>
{/strip}
