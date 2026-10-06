{* contactwiki's own section of contact's menu - contributed through the generic 'contact_menu_tpl'
   service (see contactwiki's bit_setup_inc.php), so contact's menu_contact.tpl never names this
   package. List items only - contact's own <ul> wraps them. *}
{strip}
<li class="divider"></li>
<li><a class="item" href="{$smarty.const.CONTACTWIKI_PKG_URL}list_wiki.php">{biticon ipackage="icons" iname="view-list" iexplain="Wiki Contacts" ilocation=menu}</a></li>
{if $gBitUser->isAdmin() || $gBitUser->hasPermission( 'p_contact_update' ) }
	<li><a class="item" href="{$smarty.const.CONTACTWIKI_PKG_URL}add_wiki_person.php">{biticon ipackage="icons" iname="contact-new-symbolic" iexplain="Add Wiki Individual" ilocation=menu}</a></li>
	<li><a class="item" href="{$smarty.const.CONTACTWIKI_PKG_URL}add_wiki_group.php">{biticon ipackage="icons" iname="address-book-new-symbolic" iexplain="Add Wiki Group" ilocation=menu}</a></li>
	<li><a class="item" href="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_artists.php">{biticon ipackage="icons" iname="folder-open" iexplain="Load Wiki Artist Contacts" ilocation=menu}</a></li>
	<li><a class="item" href="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_people.php">{biticon ipackage="icons" iname="system-users" iexplain="Load Wiki People" ilocation=menu}</a></li>
	<li><a class="item" href="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php">{biticon ipackage="icons" iname="video-x-generic" iexplain="Load Wiki Film People" ilocation=menu}</a></li>
	<li><a class="item" href="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php?scope=tv">{biticon ipackage="icons" iname="video-display" iexplain="Load Wiki TV People" ilocation=menu}</a></li>
{/if}
{/strip}
