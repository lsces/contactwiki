{strip}
<div class="edit liberty">
	<div class="header">
		<h1>{tr}Replace Image{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}

		{form id="editImageXrefForm" enctype="multipart/form-data"}
			<input type="hidden" name="content_id" value="{$xrefInfo.content_id}" />
			<input type="hidden" name="xref_id"    value="{$xrefInfo.xref_id}" />
			<input type="hidden" name="item"        value="{$xrefInfo.item|escape}" />

			<div class="form-group">
				{formlabel label="Current Image"}
				{forminput}
					<img src="{$smarty.const.CONTACT_PKG_URL}view_extra_image.php?xref_id={$xrefInfo.xref_id}" alt="{$xrefInfo.xkey_ext|escape}" class="img-responsive" style="max-width:300px;" />
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Replace with" for="image_file"}
				{forminput}
					<input type="file" name="image_file" id="image_file" accept="image/*" />
					{formhelp note="Choose a new image to replace this one - the existing file is overwritten in place, nothing else about this row changes."}
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel" value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="fSaveXref" value="{tr}Save{/tr}" />
				<input type="submit" class="btn btn-secondary" name="fSetAsThumbnail" value="{tr}Set as Thumbnail{/tr}" />
				{formhelp note="Makes this the real, single thumbnail shown in galleries - overrides whichever image was picked automatically."}
			</div>
		{/form}
	</div><!-- end .body -->
</div><!-- end .liberty -->
{/strip}
