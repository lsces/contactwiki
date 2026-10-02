{* People pass for one Music artist/composer gallery - see load_wiki_people.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Load Wiki People{/tr}{if $galleryTitle}: {$galleryTitle|escape}{/if}</h1>
	</div>

	<div class="body">

		{if !$galleryTitle}
			{if $galleries}
				<p>{tr}Pick an artist/composer gallery - every person credited across its albums on disk is listed, matched against existing contacts and Wikidata.{/tr}</p>
				<ul>
					{foreach from=$galleries item=g}
						<li><a href="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_people.php?gallery_id={$g.gallery_id}">{$g.title|escape}</a></li>
					{/foreach}
				</ul>
			{else}
				<p>{tr}No artist/composer galleries found under Music/.{/tr}</p>
			{/if}
		{else}

			{if $result}
				{if $result.created}
					<div class="alert alert-success">
						<p>{tr}Contacts created{/tr}:</p>
						<ul>{foreach from=$result.created item=row}<li><a href="{$row.view_url|escape}">{$row.title|escape}</a></li>{/foreach}</ul>
					</div>
				{/if}
				{if $result.remaining}
					<div class="alert alert-info">{$result.remaining} {tr}more ticked people still to create - they are still ticked below, press Create Selected Contacts again to continue.{/tr}</div>
				{/if}
				{if $result.errors}
					<div class="alert alert-danger">
						<p>{tr}Failed{/tr}:</p>
						<ul>{foreach from=$result.errors item=row}<li>{$row.mbid|escape}: {$row.error|escape}</li>{/foreach}</ul>
					</div>
				{/if}
			{/if}

			{if $survey.unreadable}
				<div class="alert alert-warning">
					{$survey.unreadable|@count} {tr}track files can't be read by the web server, so their credits are missing below - check their file permissions{/tr}:
					<ul>{foreach from=$survey.unreadable item=f name=unr}{if $smarty.foreach.unr.iteration <= 10}<li>{$f|escape}</li>{/if}{/foreach}</ul>
					{if $survey.unreadable|@count > 10}<p>... {tr}and{/tr} {$survey.unreadable|@count-10} {tr}more{/tr}</p>{/if}
				</div>
			{/if}
			{if $wikidataError}
				<div class="alert alert-warning">{tr}The Wikidata lookup failed - only people already held as contacts are resolved. Try again later.{/tr}{if $wikidataErrorReason}<br /><small>{tr}Reason{/tr}: {$wikidataErrorReason|escape}</small>{/if}</div>
			{/if}

			<p>{$survey.albums} {tr}album folders{/tr}, {$survey.tracks} {tr}tracks{/tr}, {$totalPeople} {tr}people credited{/tr}:
				{$counts.linked} {tr}already contacts{/tr}, {$counts.todo} {tr}still to create{/tr}{if $counts.unresolved}, {$counts.unresolved} {tr}not resolved{/tr}{/if}.
				<a href="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_people.php">{tr}Pick another gallery{/tr}</a></p>

			{if $people}
				{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_people.php"}
					<input type="hidden" name="gallery_id" value="{$galleryId}" />
					<p>{tr}Next{/tr} {$people|@count} {tr}of{/tr} {$counts.todo}:&nbsp;
						<input type="submit" class="btn btn-primary" name="fCreate" value="{tr}Create Selected Contacts{/tr}" /></p>
					<table class="table table-condensed">
						<thead>
							<tr>
								<th><input type="checkbox" id="people-toggle-all" title="{tr}Tick or clear all{/tr}" /></th>
								<th>{tr}Name in tags{/tr}</th>
								<th>{tr}Albums{/tr}</th>
								<th>{tr}Tracks{/tr}</th>
								<th>{tr}Credited as{/tr}</th>
								<th>{tr}Status{/tr}</th>
							</tr>
						</thead>
						<tbody>
							{foreach from=$people item=person}
								<tr>
									<td>
										{if $person.status == 'create'}
											<input type="checkbox" name="selected[]" value="{$person.mbid|escape}" checked="checked" />
											<input type="hidden" name="qid[{$person.mbid|escape}]" value="{$person.wikidata[0].qid|escape}" />
										{elseif $person.status == 'choose'}
											<input type="checkbox" name="selected[]" value="{$person.mbid|escape}" />
										{elseif $person.status == 'create_mb'}
											<input type="checkbox" name="selected[]" value="{$person.mbid|escape}" checked="checked" />
										{/if}
									</td>
									<td>
										{if $person.names}{$person.names|join:' / '|escape}{else}<span class="text-muted">{tr}(no name in tags){/tr}</span>{/if}
										<br /><a class="small text-muted" href="https://musicbrainz.org/artist/{$person.mbid|escape}" target="_blank" rel="noopener">{$person.mbid|escape}</a>
									</td>
									<td>{$person.albums}</td>
									<td>{$person.tracks}</td>
									<td>{if $person.album_artist}{tr}album artist{/tr}{/if}{if $person.album_artist && $person.track_artist}, {/if}{if $person.track_artist}{tr}track artist{/tr}{/if}</td>
									<td>
										{if $person.status == 'linked'}
											<a href="{$person.contact.view_url|escape}">{tr}Contact{/tr}: {$person.contact.title|escape}</a>
										{elseif $person.status == 'linked_by_qid'}
											<a href="{$person.contact.view_url|escape}">{tr}Contact{/tr}: {$person.contact.title|escape}</a>
											<span class="text-muted"> ({tr}matched by Wikidata id - it has no MusicBrainz id stored yet{/tr})</span>
										{elseif $person.status == 'create'}
											{tr}Create{/tr} {if $person.wikidata[0].is_human}{tr}individual{/tr}{else}{tr}group{/tr}{/if}:
											<a href="https://www.wikidata.org/wiki/{$person.wikidata[0].qid|escape}" target="_blank" rel="noopener">{$person.wikidata[0].label|escape} ({$person.wikidata[0].qid|escape})</a>
										{elseif $person.status == 'choose'}
											{tr}Wikidata has this MusicBrainz id on more than one item (a duplicate on Wikidata's side, not MusicBrainz) - choose the right one{/tr}:
											{foreach from=$person.wikidata item=w name=choices}
												<br /><label><input type="radio" name="qid[{$person.mbid|escape}]" value="{$w.qid|escape}" {if $smarty.foreach.choices.first}checked="checked"{/if} />
												<a href="https://www.wikidata.org/wiki/{$w.qid|escape}" target="_blank" rel="noopener">{$w.label|escape} ({$w.qid|escape})</a>, {if $w.is_human}{tr}individual{/tr}{else}{tr}group{/tr}{/if}</label>
											{/foreach}
										{elseif $person.status == 'create_mb'}
											{tr}Create from MusicBrainz{/tr} <span class="text-muted">({tr}not on Wikidata - a later Reload picks up a Wikidata item if one appears{/tr})</span>
										{else}
											<span class="text-muted">{tr}Not resolved{/tr}</span>
										{/if}
									</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
					<input type="submit" class="btn btn-primary" name="fCreate" value="{tr}Create Selected Contacts{/tr}" />
				{/form}
				<script>
				/* Header box ticks or clears every person row; it shows ticked when they all are. Block comments only - Smarty strip may join these lines. */
				( function() {
					var all = document.getElementById( 'people-toggle-all' );
					var boxes = Array.prototype.slice.call( document.querySelectorAll( 'input[name="selected[]"]' ) );
					var sync = function() { all.checked = boxes.length > 0 && boxes.every( function( b ) { return b.checked; } ); };
					all.addEventListener( 'change', function() { boxes.forEach( function( b ) { b.checked = all.checked; } ); } );
					boxes.forEach( function( b ) { b.addEventListener( 'change', sync ); } );
					sync();
				} )();
				</script>
			{elseif $totalPeople}
				<p>{tr}Nothing left to create for this gallery.{/tr}</p>
			{else}
				<p>{tr}No MusicBrainz-tagged people found in this gallery's albums.{/tr}</p>
			{/if}
			{if !$people}
				<p><a class="btn btn-primary" href="{$albumsUrl|escape}">{tr}Continue: Load albums{/tr}</a></p>
			{/if}
		{/if}

	</div>
</div>
{/strip}
