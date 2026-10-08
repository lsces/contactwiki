{* Add-form override for the contact 'images' group - a real file upload posting back to liberty/add_xref.php (addImageXrefFile() on the contact class stores the file). *}
{strip}
<div class="edit liberty">
	<div class="header">
		<h1>{tr}Add Image{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}

		{form id="addXrefForm" enctype="multipart/form-data"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
			<input type="hidden" name="group" value="{$group}" />
			<input type="hidden" name="item" value="image" />

			<div class="form-group">
				{formlabel label="Image" for="image_file"}
				{forminput}
					<input type="file" name="image_file" id="image_file" accept="image/*" />
					{formhelp note="Choose an image to add - stored as a new entry on this tab."}
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel" value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="fAddXref" value="{tr}Save{/tr}" />
			</div>
		{/form}
	</div><!-- end .body -->
</div><!-- end .liberty -->
{/strip}
