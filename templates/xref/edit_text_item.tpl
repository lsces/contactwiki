{* A wiki contact's text item (date of birth, pen / other name, place, formed ...): the value is held in xkey_ext, where the Wikidata
   reload and the credit passes write it and where name matching reads it, with an optional note in xkey. Overrides liberty's generic
   'text' form (Value = xkey, Notes = xkey_ext, Detail = data) for contactwiki content only. A row typed on the generic form has its
   value in xkey: that shows as the Value here and is moved to xkey_ext on the next Save. *}
{strip}
{if $xrefInfo.xkey_ext ne ''}{assign var=wikiValue value=$xrefInfo.xkey_ext}{assign var=wikiNote value=$xrefInfo.xkey}{else}{assign var=wikiValue value=$xrefInfo.xkey}{assign var=wikiNote value=''}{/if}
<div class="edit liberty">
	<div class="header">
		<h1>{tr}Edit{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>
	<div class="body">
		{formfeedback error=$errors}
		{form id="editXrefForm"}
			<input type="hidden" name="content_id" value="{$xrefInfo.content_id|escape}" />
			<input type="hidden" name="xref_id"    value="{$xrefInfo.xref_id|escape}" />
			<input type="hidden" name="item"       value="{$xrefInfo.item|escape}" />

			<div class="form-group">
				{formlabel label="Type"}
				{forminput}
					<p class="form-control-static">{$xrefInfo.template_title|escape}</p>
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Value" for="xkey_ext"}
				{forminput}
					<input type="text" class="form-control input-small" name="xkey_ext" id="xkey_ext" value="{$wikiValue|escape}" />
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Notes" for="xkey"}
				{forminput}
					<input type="text" class="form-control input-small" name="xkey" id="xkey" value="{$wikiNote|escape}" />
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel"   value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="fSaveXref" value="{tr}Save{/tr}" />
			</div>
		{/form}
	</div>
</div>
{/strip}
