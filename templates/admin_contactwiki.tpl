{strip}
{form legend="Integration Settings"}
	<input type="hidden" name="page" value="{$page}" />

	{foreach from=$formContactWikiGeneral key=item item=output}
		{if $output.heading}<h4>{tr}{$output.heading}{/tr}</h4>{/if}
		<div class="form-group">
			{formlabel label=$output.label for=$item}
			{forminput}
				<input type="text" class="form-control" name="{$item}" id="{$item}" value="{$gBitSystem->getConfig($item)|escape}" />
				{formhelp note=$output.note}
			{/forminput}
		</div>
	{/foreach}

	<div class="form-group submit">
		<input type="submit" name="contactWikiGeneralSubmit" value="{tr}Change preferences{/tr}" />
	</div>
{/form}
{/strip}
