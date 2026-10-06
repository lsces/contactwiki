{* People pass for Film credits - see load_wiki_film_people.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Load Wiki Film People{/tr}</h1>
	</div>

	<div class="body">

		{if $result}
			{if $result.linked}
				<div class="alert alert-success">
					<p>{$result.rows} {tr}credits linked to contacts{/tr}:</p>
					<ul>{foreach from=$result.linked item=row}<li>{$row.name|escape} &rarr; <a href="{$row.view_url|escape}">{$row.contact|escape}</a> ({$row.rows})</li>{/foreach}</ul>
				</div>
			{/if}
			{if $result.remaining}
				<div class="alert alert-info">{$result.remaining} {tr}more ticked people were not done this time - they are listed again below, press Link Selected again to continue.{/tr}</div>
			{/if}
			{if $result.skipped}
				<div class="alert alert-warning">{$result.skipped} {tr}ticked people were skipped - the chosen contact did not match the credited name.{/tr}</div>
			{/if}
		{/if}

		<p>{$survey.films} {tr}films{/tr}, {$survey.credits} {tr}credits{/tr}, {$survey.people} {tr}distinct people{/tr}:
			{$counts.linked} {tr}already linked to a contact{/tr}, {$counts.match} {tr}matching one contact by name{/tr},
			{$counts.choose} {tr}matching several{/tr}, {$counts.unmatched} {tr}with no contact yet{/tr}.</p>

		{if $reviewList}
			{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php"}
				<p>{tr}Next{/tr} {$reviewList|@count} {tr}of{/tr} {$reviewTotal}:&nbsp;
					<input type="submit" class="btn btn-primary" name="fLink" value="{tr}Link Selected{/tr}" /></p>
				<table class="table table-condensed">
					<thead>
						<tr>
							<th><input type="checkbox" id="film-people-toggle-all" title="{tr}Tick or clear all{/tr}" /></th>
							<th>{tr}Credited as{/tr}</th>
							<th>{tr}Credits{/tr}</th>
							<th>{tr}Films{/tr}</th>
							<th>{tr}Contact{/tr}</th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$reviewList item=person}
							<tr>
								<td>
									{if $person.candidates|@count == 1}
										<input type="checkbox" name="selected[]" value="{$person.key|escape}" checked="checked" />
										<input type="hidden" name="contact[{$person.key|escape}]" value="{$person.candidates[0].content_id}" />
									{else}
										<input type="checkbox" name="selected[]" value="{$person.key|escape}" />
									{/if}
								</td>
								<td>{$person.name|escape}</td>
								<td>{$person.credits} <span class="text-muted">({foreach from=$person.roles key=role item=n name=roles}{$n} {$role|escape}{if !$smarty.foreach.roles.last}, {/if}{/foreach})</span></td>
								<td>{foreach from=$person.film_titles item=title name=ft}{$title|escape}{if !$smarty.foreach.ft.last}; {/if}{/foreach}{if $person.more_films} <span class="text-muted">{tr}and{/tr} {$person.more_films} {tr}more{/tr}</span>{/if}</td>
								<td>
									{if $person.candidates|@count == 1}
										<a href="{$person.candidates[0].view_url|escape}">{$person.candidates[0].title|escape}</a>
										{if $person.candidates[0].content_type_guid == 'contactwikigroup'}<span class="text-muted">({tr}group{/tr})</span>{/if}
										{if $person.candidates[0].qid}<a class="small text-muted" href="https://www.wikidata.org/wiki/{$person.candidates[0].qid|escape}" target="_blank" rel="noopener">{$person.candidates[0].qid|escape}</a>{/if}
									{else}
										{tr}Several contacts have this name - choose the right one{/tr}:
										{foreach from=$person.candidates item=c name=choices}
											<br /><label><input type="radio" name="contact[{$person.key|escape}]" value="{$c.content_id}" {if $smarty.foreach.choices.first}checked="checked"{/if} />
											<a href="{$c.view_url|escape}">{$c.title|escape}</a>{if $c.content_type_guid == 'contactwikigroup'} ({tr}group{/tr}){/if}{if $c.qid} <span class="small text-muted">{$c.qid|escape}</span>{/if}</label>
										{/foreach}
									{/if}
								</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
				<input type="submit" class="btn btn-primary" name="fLink" value="{tr}Link Selected{/tr}" />
			{/form}
			<script>
			/* Header box ticks or clears every person row; it shows ticked when they all are. Block comments only - Smarty strip may join these lines. */
			( function() {
				var all = document.getElementById( 'film-people-toggle-all' );
				var boxes = Array.prototype.slice.call( document.querySelectorAll( 'input[name="selected[]"]' ) );
				var sync = function() { all.checked = boxes.length > 0 && boxes.every( function( b ) { return b.checked; } ); };
				all.addEventListener( 'change', function() { boxes.forEach( function( b ) { b.checked = all.checked; } ); } );
				boxes.forEach( function( b ) { b.addEventListener( 'change', sync ); } );
				sync();
			} )();
			</script>
		{else}
			<p>{tr}Nothing left to link by name.{/tr}</p>
		{/if}

		{if $unmatched}
			<h2>{tr}Most-credited people with no contact yet{/tr}</h2>
			<p class="text-muted">{tr}Listed only so you can see what is left - creating contacts for them (through Wikidata and TMDb) is the next stage.{/tr}</p>
			<table class="table table-condensed">
				<thead><tr><th>{tr}Credited as{/tr}</th><th>{tr}Credits{/tr}</th><th>{tr}Films{/tr}</th></tr></thead>
				<tbody>
					{foreach from=$unmatched item=person}
						<tr>
							<td>{$person.name|escape}</td>
							<td>{$person.credits} <span class="text-muted">({foreach from=$person.roles key=role item=n name=roles}{$n} {$role|escape}{if !$smarty.foreach.roles.last}, {/if}{/foreach})</span></td>
							<td>{foreach from=$person.film_titles item=title name=ft}{$title|escape}{if !$smarty.foreach.ft.last}; {/if}{/foreach}{if $person.more_films} <span class="text-muted">{tr}and{/tr} {$person.more_films} {tr}more{/tr}</span>{/if}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		{/if}

	</div>
</div>
{/strip}
