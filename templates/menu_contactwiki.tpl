{strip}
{if $packageMenuTitle}<a class="dropdown-toggle" data-toggle="dropdown" href="#"> {tr}{$packageMenuTitle}{/tr} <b class="caret"></b></a>{/if}
<ul class="{$packageMenuClass}">
	{if $gBitUser->isAdmin() || $gBitUser->hasPermission( 'p_contact_update' ) }
		<li><a class="item" href="{$smarty.const.CONTACTWIKI_PKG_URL}add_wiki_person.php">{biticon ipackage="icons" iname="contact-new-symbolic" iexplain="Add Wiki Individual" ilocation=menu}</a></li>
		<li><a class="item" href="{$smarty.const.CONTACTWIKI_PKG_URL}add_wiki_group.php">{biticon ipackage="icons" iname="address-book-new-symbolic" iexplain="Add Wiki Group" ilocation=menu}</a></li>
		<li><a class="item" href="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_artists.php">{biticon ipackage="icons" iname="folder-open" iexplain="Load Wiki Artist Contacts" ilocation=menu}</a></li>
	{/if}
</ul>
{/strip}
