{* Rendered generically by liberty/edit_services_inc.tpl (serviceFile="content_edit_mini_tpl",
   called from contact/edit.tpl) for every contact's edit page regardless of type - self-gated here
   rather than in contact/edit.tpl, since base contact has no reason to know this package exists.
   Safe to reference this package's own classes directly: this fragment is only ever reachable via a
   service registration contactwiki itself made, so if it's rendering at all, contactwiki is active. *}
{if $gContent instanceof \Bitweaver\Contactwiki\ContactWikiIndividual || $gContent instanceof \Bitweaver\Contactwiki\ContactWikiGroup}
	{if $wikiReloadResult}
		<div class="form-group">
			{if $wikiReloadResult.items}
				<p>{$wikiReloadLabel|escape}:</p>
				<ul>{foreach from=$wikiReloadResult.items item=line}<li>{$line|escape}</li>{/foreach}</ul>
			{else}
				<p>{$wikiReloadResult.error|escape}</p>
			{/if}
		</div>
	{/if}
	{if $gContent->mInfo.content_id}
		<input type="submit" class="btn btn-secondary" name="fReloadWikidata" value="{tr}Reload from Wikidata{/tr}" />
	{/if}
{/if}
