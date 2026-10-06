{* People pass for Film credits, or for one TV show (scope=tv) - see load_wiki_film_people.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{if $scope == 'tv'}{tr}Load Wiki TV People{/tr}{if $program}: {$program.title|escape}{/if}{else}{tr}Load Wiki Film People{/tr}{/if}</h1>
	</div>

	<div class="body">

		{if $scope == 'tv' && !$program}
			<p>{tr}Pick a show. Each season's credits are built from its episodes (a season reload does it automatically, or press Build on the show's page), then its people are matched to contacts or created.{/tr}</p>
			<table class="table table-condensed">
				<thead><tr><th>{tr}Show{/tr}</th><th>{tr}Seasons{/tr}</th><th>{tr}Seasons with credits{/tr}</th><th>{tr}Credit rows{/tr}</th><th>{tr}Not linked yet{/tr}</th></tr></thead>
				<tbody>
					{foreach from=$programs item=prog}
						<tr>
							<td><a href="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php?scope=tv&amp;program_id={$prog.content_id}">{$prog.title|escape}</a></td>
							<td>{$prog.seasons}</td>
							<td>{$prog.built}</td>
							<td>{$prog.credits}</td>
							<td>{$prog.unlinked}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		{else}

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

		{if $createResult}
			{if $createResult.created}
				<div class="alert alert-success">
					<p>{tr}Contacts created and credits linked{/tr}:</p>
					<ul>{foreach from=$createResult.created item=row}<li>{$row.name|escape} &rarr; <a href="{$row.view_url|escape}">{$row.title|escape}</a> ({$row.rows})</li>{/foreach}</ul>
				</div>
			{/if}
			{if $createResult.linked}
				<div class="alert alert-success">
					<p>{tr}Linked to an existing contact{/tr}:</p>
					<ul>{foreach from=$createResult.linked item=row}<li>{$row.name|escape} &rarr; <a href="{$row.view_url|escape}">{$row.title|escape}</a> ({$row.rows})</li>{/foreach}</ul>
				</div>
			{/if}
			{if $createResult.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$createResult.errors item=row}<li>{$row.name|escape}: {$row.error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
		{/if}

		{if $scope == 'tv'}
			{if $buildResult}
				<div class="alert alert-success">{$buildResult.seasons} {tr}seasons built{/tr}: {$buildResult.inserted} {tr}credit rows written{/tr}, {$buildResult.archived} {tr}archived{/tr}.</div>
			{/if}
			<p>{$program.seasons} {tr}seasons{/tr}, {$program.built} {tr}with credits built{/tr}.
				{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php"}
					{foreach from=$hiddenFields key=k item=v}{if $k != 'min'}<input type="hidden" name="{$k}" value="{$v|escape}" />{/if}{/foreach}
					<input type="submit" class="btn btn-default" name="fBuild" value="{tr}Build credit directories{/tr}" />
					&nbsp; {tr}Look up people credited on at least{/tr} <input type="number" min="1" name="min" value="{$min}" style="width:5em" /> {tr}episodes{/tr}
					<input type="submit" class="btn btn-default" value="{tr}Apply{/tr}" />
					&nbsp; <a href="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php?scope=tv">{tr}Pick another show{/tr}</a>
				{/form}</p>
		{/if}

		<p>{$survey.films} {if $scope == 'tv'}{tr}seasons and the show{/tr}{else}{tr}films{/tr}{/if}, {$survey.credits} {tr}credits{/tr}, {$survey.people} {tr}distinct people{/tr}:
			{$counts.linked} {tr}already linked to a contact{/tr}, {$counts.match} {tr}matching one contact by name{/tr},
			{$counts.choose} {tr}matching several{/tr}, {$counts.unmatched} {tr}with no contact yet{/tr}.</p>

		{if $reviewList}
			{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php"}
				{foreach from=$hiddenFields key=k item=v}<input type="hidden" name="{$k}" value="{$v|escape}" />{/foreach}
				<p>{tr}Next{/tr} {$reviewList|@count} {tr}of{/tr} {$reviewTotal}:&nbsp;
					<input type="submit" class="btn btn-primary" name="fLink" value="{tr}Link Selected{/tr}" /></p>
				<table class="table table-condensed">
					<thead>
						<tr>
							<th><input type="checkbox" id="film-people-toggle-all" title="{tr}Tick or clear all{/tr}" /></th>
							<th>{tr}Credited as{/tr}</th>
							<th>{tr}Credits{/tr}</th>
							<th>{if $scope == 'tv'}{tr}Seasons{/tr}{else}{tr}Films{/tr}{/if}</th>
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

		{if $counts.unmatched}
			<h2>{tr}People with no contact yet{/tr}</h2>
			{if $counts.belowMin}<p class="text-muted">{$counts.belowMin} {tr}more are credited on fewer than the minimum and are not listed - lower the minimum above to include them.{/tr}</p>{/if}
			{if !$lookup}
				<p>{tr}Most-credited first. Look up the next{/tr} {$lookupBatch} {tr}on TMDb and Wikidata (this contacts those services, so it takes a few seconds):{/tr}</p>
				{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php"}
				{foreach from=$hiddenFields key=k item=v}<input type="hidden" name="{$k}" value="{$v|escape}" />{/foreach}
					<input type="hidden" name="start" value="{$start}" />
					<input type="submit" class="btn btn-primary" name="fResolve" value="{tr}Look up next batch{/tr}" />
				{/form}
				<table class="table table-condensed">
					<thead><tr><th>{tr}Credited as{/tr}</th><th>{tr}Credits{/tr}</th><th>{if $scope == 'tv'}{tr}Seasons{/tr}{else}{tr}Films{/tr}{/if}</th></tr></thead>
					<tbody>
						{foreach from=$unmatchedShown item=person}
							<tr>
								<td>{$person.name|escape}</td>
								<td>{$person.credits} <span class="text-muted">({foreach from=$person.roles key=role item=n name=roles}{$n} {$role|escape}{if !$smarty.foreach.roles.last}, {/if}{/foreach})</span></td>
								<td>{foreach from=$person.film_titles item=title name=ft}{$title|escape}{if !$smarty.foreach.ft.last}; {/if}{/foreach}{if $person.more_films} <span class="text-muted">{tr}and{/tr} {$person.more_films} {tr}more{/tr}</span>{/if}</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{else}
				{if !$lookup.tokenSet}
					<div class="alert alert-warning">{tr}No TMDb access token is set (contactwiki admin settings) - nobody can be looked up.{/tr}</div>
				{/if}
				{if $lookup.wikidataError}
					<div class="alert alert-warning">{tr}The Wikidata lookup failed - TMDb-only creation is still offered, but nobody is matched to a Wikidata item. Try again later.{/tr}{if $lookup.wikidataErrorReason}<br /><small>{tr}Reason{/tr}: {$lookup.wikidataErrorReason|escape}</small>{/if}</div>
				{/if}
				{if $lookup.people}
					{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php"}
				{foreach from=$hiddenFields key=k item=v}<input type="hidden" name="{$k}" value="{$v|escape}" />{/foreach}
						<input type="hidden" name="start" value="{$start}" />
						{foreach from=$lookup.people item=person}<input type="hidden" name="batch[]" value="{$person.key|escape}" />{/foreach}
						<p>{$lookup.people|@count} {tr}looked up{/tr}{if $lookup.remaining}, {$lookup.remaining} {tr}more after these{/tr}{/if}:&nbsp;
							<input type="submit" class="btn btn-primary" name="fCreate" value="{tr}Create / Link Selected{/tr}" />
							<a class="btn btn-default" href="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php?fResolve=1&amp;start={$start+$lookup.people|@count}{foreach from=$hiddenFields key=k item=v}&amp;{$k}={$v|escape:'url'}{/foreach}">{tr}Skip these{/tr}</a></p>
						<table class="table table-condensed">
							<thead>
								<tr>
									<th></th>
									<th>{tr}Credited as{/tr}</th>
									<th>{tr}Credits{/tr}</th>
									<th>{if $scope == 'tv'}{tr}Seasons{/tr}{else}{tr}Films{/tr}{/if}</th>
									<th>{tr}Found{/tr}</th>
								</tr>
							</thead>
							<tbody>
								{foreach from=$lookup.people item=person}
									<tr>
										<td>
											{if $person.status == 'create' || $person.status == 'create_tmdb' || $person.status == 'link_existing'}
												<input type="checkbox" name="selected2[]" value="{$person.key|escape}" checked="checked" />
												<input type="hidden" name="pick[{$person.key|escape}]" value="{$person.options[0].value|escape}" />
											{elseif $person.status == 'choose'}
												<input type="checkbox" name="selected2[]" value="{$person.key|escape}" />
											{/if}
										</td>
										<td>{$person.name|escape}</td>
										<td>{$person.credits} <span class="text-muted">({foreach from=$person.roles key=role item=n name=roles}{$n} {$role|escape}{if !$smarty.foreach.roles.last}, {/if}{/foreach})</span></td>
										<td>{foreach from=$person.film_titles item=title name=ft}{$title|escape}{if !$smarty.foreach.ft.last}; {/if}{/foreach}{if $person.more_films} <span class="text-muted">{tr}and{/tr} {$person.more_films} {tr}more{/tr}</span>{/if}</td>
										<td>
											{if $person.status == 'unresolved'}
												<span class="text-muted">{tr}Not resolved{/tr}: {$person.reason|escape}</span>
											{else}
												{foreach from=$person.options item=o name=opts}
													{if $person.status == 'choose'}<label><input type="radio" name="pick[{$person.key|escape}]" value="{$o.value|escape}" {if $smarty.foreach.opts.first}checked="checked"{/if} />{/if}
													{if $person.status == 'choose'}<br />{/if}
													{if $o.existing}
														{tr}Link to existing contact{/tr}: <a href="{$o.existing.view_url|escape}">{$o.existing.title|escape}</a>
													{elseif $o.qid != ''}
														{tr}Create from Wikidata{/tr}: <a href="https://www.wikidata.org/wiki/{$o.qid|escape}" target="_blank" rel="noopener">{$o.label|escape} ({$o.qid|escape})</a>{if $o.from_tmdb} <span class="text-muted">({tr}Q-id from TMDb's external ids{/tr})</span>{/if}{if !$o.is_human} <span class="text-danger">({tr}not a human on Wikidata{/tr})</span>{/if}
													{else}
														{tr}Create from TMDb{/tr} <span class="text-muted">({tr}no Wikidata item{/tr}){if $o.details}: {$o.details.known_for|escape}{if $o.details.birthday}, {tr}born{/tr} {$o.details.birthday|escape}{/if}{/if}</span>
													{/if}
													<a class="small text-muted" href="https://www.themoviedb.org/person/{$o.tmdb_id}" target="_blank" rel="noopener">TMDb {$o.tmdb_id}</a>
													{if $person.status == 'choose'}</label>{/if}
												{/foreach}
											{/if}
										</td>
									</tr>
								{/foreach}
							</tbody>
						</table>
						<input type="submit" class="btn btn-primary" name="fCreate" value="{tr}Create / Link Selected{/tr}" />
					{/form}
				{else}
					<p>{tr}Nobody left to look up.{/tr}</p>
				{/if}
			{/if}
		{/if}

		{/if}

	</div>
</div>
{/strip}
