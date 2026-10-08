{strip}
<td>{$xrefInfo.xref_title|escape}</td>
<td>
	{* &name= carries the file name only so FancyZoom (which zooms links ending in an image extension) picks the link up *}
	<a href="{$smarty.const.CONTACT_PKG_URL}view_extra_image.php?xref_id={$xrefInfo.xref_id}&amp;name={$xrefInfo.xkey_ext|escape:'url'}" target="_blank" rel="noopener">
		<img src="{$smarty.const.CONTACT_PKG_URL}view_extra_image.php?xref_id={$xrefInfo.xref_id}" alt="{$xrefInfo.xkey_ext|escape}" style="max-height:90px; max-width:140px;" />
	</a>
</td>
<td>{$xrefInfo.data|escape}</td>
{include file="bitpackage:liberty/xref/dates_cell.tpl"}
{if $xrefAllowEdit|default:true}
<td>
	<span class="actionicon">
		{* promoteImageToThumbnail() is on the contact classes (ContactWikiTrait) - edit_xref.php's method_exists() check is the real guard. *}
		{if $gContent->hasUpdatePermission()}
			{smartlink ititle="Set as Thumbnail" ipackage="liberty" ifile="edit_xref.php" biticon="image-x-generic" content_id=$gContent->mInfo.content_id xref_id=$xrefInfo.xref_id fSetAsThumbnail=1}
		{/if}
		{if $gContent->hasUpdatePermission() && !$isHistory && $xrefInfo.multiple neq -1}
			{smartlink ititle="Edit" ipackage="liberty" ifile="edit_xref.php" biticon="edit" content_id=$gContent->mInfo.content_id xref_id=$xrefInfo.xref_id}
		{/if}
		{if $gContent->hasUpdatePermission() && !$xrefProtected|default:false}
			{if $isHistory}
				{smartlink ititle="Restore" ipackage="liberty" ifile="edit_xref.php" biticon="edit" content_id=$gContent->mInfo.content_id xref_id=$xrefInfo.xref_id expunge=-1}
			{else}
				{smartlink ititle="Archive" ipackage="liberty" ifile="edit_xref.php" biticon="archive-insert" content_id=$gContent->mInfo.content_id xref_id=$xrefInfo.xref_id expunge=1}
			{/if}
		{/if}
		{if $gContent->hasExpungePermission() && !$xrefProtected|default:false}
			{smartlink ititle="Delete" ipackage="liberty" ifile="edit_xref.php" biticon="user-trash" content_id=$gContent->mInfo.content_id xref_id=$xrefInfo.xref_id expunge=3}
		{/if}
	</span>
</td>
{/if}
{/strip}
