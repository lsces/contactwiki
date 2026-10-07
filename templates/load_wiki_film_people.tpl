{* People pass for Film credits, or for one TV show (scope=tv) - see load_wiki_film_people.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{if $scope == 'tv'}{tr}Load Wiki TV People{/tr}{if $program}: <a href="{$programUrl|escape}" title="{tr}Back to the show{/tr}">{$program.title|escape}</a>{/if}{else}{tr}Load Wiki Film People{/tr}{/if}</h1>
	</div>

	<div class="body">

		{if $scope == 'tv' && !$program}
			<p>{tr}Pick a show (or open this from a show's own page). Reload it from Plex to build its credits from the episodes, then its people are matched to contacts or created.{/tr}</p>
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
					<p>{tr}Contacts created and credits linked{/tr} <span class="text-muted">({$createResult.seconds}s {tr}creating{/tr}: {$createResult.kinds.wikidata} {tr}from Wikidata{/tr}, {$createResult.kinds.tmdb} {tr}from TMDb only{/tr}, {$createResult.kinds.nameonly} {tr}from the name only{/tr}, {$createResult.kinds.existing} {tr}linked to an existing contact{/tr}{if $createResult.prefetch}, {tr}Wikimedia data fetched together in{/tr} {$createResult.prefetch.seconds}s{/if})</span>:</p>
					<ul>{foreach from=$createResult.created item=row}<li>{$row.name|escape} &rarr; <a href="{$row.view_url|escape}">{$row.title|escape}</a> ({$row.rows}) <span class="text-muted">{$row.seconds}s</span></li>{/foreach}</ul>
				</div>
			{/if}
			{if $createResult.linked}
				<div class="alert alert-success">
					<p>{tr}Linked to an existing contact{/tr}:</p>
					<ul>{foreach from=$createResult.linked item=row}<li>{$row.name|escape} &rarr; <a href="{$row.view_url|escape}">{$row.title|escape}</a> ({$row.rows}) <span class="text-muted">{$row.seconds}s</span></li>{/foreach}</ul>
				</div>
			{/if}
			{if $createResult.remaining}
				<div class="alert alert-info">{$createResult.remaining} {tr}more ticked people were not done this time (time limit) - they are still listed and ticked below, press Create / Link Selected again to continue.{/tr}</div>
			{/if}
			{if $createResult.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$createResult.errors item=row}<li>{$row.name|escape}: {$row.error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
		{/if}

		{if $scope == 'tv'}
			{if $reloadResult}
				<div class="alert alert-success">
					<p>{$reloadResult.seasons|@count} {tr}seasons reloaded from Plex{/tr} ({$reloadResult.episodes} {tr}episodes{/tr}), {tr}credit directories rebuilt{/tr}:</p>
					{if $reloadResult.creators}<p>{tr}Created by{/tr}: {if $reloadResult.creators.names}{$reloadResult.creators.names|@implode:', '|escape}{else}<span class="text-muted">{tr}none found on TMDb{/tr}{if $reloadResult.creators.note} ({$reloadResult.creators.note|escape}){/if}</span>{/if}</p>{/if}
					<ul>{foreach from=$reloadResult.seasons item=rs}<li>{$rs.title|escape} - {if $rs.matched}{$rs.episodes} {tr}episodes{/tr}, {$rs.seconds}s{else}{tr}no Plex match, episodes re-read from disk{/tr}{/if}</li>{/foreach}</ul>
					{if $reloadResult.next !== null}
						{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php"}
							{foreach from=$hiddenFields key=k item=v}<input type="hidden" name="{$k}" value="{$v|escape}" />{/foreach}
							<input type="hidden" name="rl" value="{$reloadResult.next}" />
							<p>{$reloadResult.next} {tr}of{/tr} {$reloadResult.total} {tr}seasons done.{/tr} <input type="submit" class="btn btn-primary" name="fReload" value="{tr}Continue reloading{/tr}" /></p>
						{/form}
					{/if}
				</div>
			{/if}
			<p>{$program.seasons} {tr}seasons{/tr}, {$program.built} {tr}with credits built{/tr}.
				{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php"}
					{foreach from=$hiddenFields key=k item=v}<input type="hidden" name="{$k}" value="{$v|escape}" />{/foreach}
					<input type="submit" class="btn btn-default" name="fReload" value="{tr}Reload from Plex{/tr}" title="{tr}Refreshes every season's episode details and full cast from Plex (thumbnails are kept) and rebuilds the show's credits{/tr}" />
				{/form}</p>
			{if $finished}
				<div class="alert alert-success">{tr}Every credit on this show is linked to a contact.{/tr} <a class="btn btn-default" href="{$programUrl|escape}">{tr}Back to{/tr} {$program.title|escape}</a></div>
			{/if}
		{/if}

		<p>{if $scope == 'tv'}{$program.seasons} {tr}seasons and the show{/tr}{else}{$survey.films} {tr}films{/tr}{/if}, {$survey.credits} {tr}credits{/tr}, {$survey.people} {tr}distinct people{/tr}:
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
							<th>{if $scope == 'tv'}{tr}Episodes{/tr}{else}{tr}Credits{/tr}{/if}</th>
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
								<td>{if $scope == 'tv' && $person.episodes}{$person.episodes} {tr}episodes{/tr}{else}{$person.credits}{/if} <span class="text-muted">({foreach from=$person.roles key=role item=n name=roles}{$n} {$role|escape}{if !$smarty.foreach.roles.last}, {/if}{/foreach})</span></td>
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
			{if !$lookup}
				<p>{tr}Most-credited first. Look up the next{/tr} {$lookupBatch} {tr}on TMDb and Wikidata (this contacts those services, so it takes a few seconds):{/tr}</p>
				{form legend="" action="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php"}
				{foreach from=$hiddenFields key=k item=v}<input type="hidden" name="{$k}" value="{$v|escape}" />{/foreach}
					<input type="hidden" name="start" value="{$start}" />
					<input type="submit" class="btn btn-primary" name="fResolve" value="{tr}Look up next batch{/tr}" />
				{/form}
				<table class="table table-condensed">
					<thead><tr><th>{tr}Credited as{/tr}</th><th>{if $scope == 'tv'}{tr}Episodes{/tr}{else}{tr}Credits{/tr}{/if}</th><th>{if $scope == 'tv'}{tr}Seasons{/tr}{else}{tr}Films{/tr}{/if}</th></tr></thead>
					<tbody>
						{foreach from=$unmatchedShown item=person}
							<tr>
								<td>{$person.name|escape}</td>
								<td>{if $scope == 'tv' && $person.episodes}{$person.episodes} {tr}episodes{/tr}{else}{$person.credits}{/if} <span class="text-muted">({foreach from=$person.roles key=role item=n name=roles}{$n} {$role|escape}{if !$smarty.foreach.roles.last}, {/if}{/foreach})</span></td>
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
									<th>{if $scope == 'tv'}{tr}Episodes{/tr}{else}{tr}Credits{/tr}{/if}</th>
									<th>{if $scope == 'tv'}{tr}Seasons{/tr}{else}{tr}Films{/tr}{/if}</th>
									<th>{tr}Found{/tr}</th>
								</tr>
							</thead>
							<tbody>
								{foreach from=$lookup.people item=person}
									<tr>
										<td>
											{if $person.status == 'unresolved'}<input type="hidden" name="unres[]" value="{$person.key|escape}" />{/if}
											{if $person.status == 'create' || $person.status == 'create_tmdb' || $person.status == 'link_existing'}
												<input type="checkbox" name="selected2[]" value="{$person.key|escape}" checked="checked" />
												<input type="hidden" name="pick[{$person.key|escape}]" value="{$person.options[0].value|escape}" />
												{if $person.options[0].aliases}<input type="hidden" name="also[{$person.key|escape}]" value="{$person.options[0].aliases|@implode:','}" />{/if}
											{elseif $person.status == 'choose' || $person.status == 'unresolved'}
												<input type="checkbox" name="selected2[]" value="{$person.key|escape}" />
											{/if}
										</td>
										<td>{$person.name|escape}</td>
										<td>{if $scope == 'tv' && $person.episodes}{$person.episodes} {tr}episodes{/tr}{else}{$person.credits}{/if} <span class="text-muted">({foreach from=$person.roles key=role item=n name=roles}{$n} {$role|escape}{if !$smarty.foreach.roles.last}, {/if}{/foreach})</span></td>
										<td>{foreach from=$person.film_titles item=title name=ft}{$title|escape}{if !$smarty.foreach.ft.last}; {/if}{/foreach}{if $person.more_films} <span class="text-muted">{tr}and{/tr} {$person.more_films} {tr}more{/tr}</span>{/if}</td>
										<td>
											{if $person.status == 'unresolved'}
												<span class="text-muted">{tr}Not resolved{/tr}: {$person.reason|escape}</span>
												<br />{tr}Tick to create a contact for this person - choose which:{/tr}
												{foreach from=$person.manual item=m name=man}
													<br /><label><input type="radio" name="pick[{$person.key|escape}]" value="{$m.value|escape}" {if $smarty.foreach.man.first}checked="checked"{/if} />
													{tr}Wikidata{/tr}: <a href="https://www.wikidata.org/wiki/{$m.qid|escape}" target="_blank" rel="noopener">{$m.label|escape} ({$m.qid|escape})</a>{if $m.description} - {$m.description|escape}{/if}{if $m.fit} <span class="text-success" title="{tr}the description fits the credited job{/tr}">&#10003;</span>{elseif $m.likely} <span class="text-muted">&#10003;</span>{/if}</label>
												{/foreach}
												<br /><label><input type="radio" name="pick[{$person.key|escape}]" value="0:" {if !$person.manual}checked="checked"{/if} /> {tr}A contact from the name only{/tr} <span class="text-muted">({tr}no Wikidata or TMDb id{/tr})</span></label>
											{else}
												{foreach from=$person.options item=o name=opts}
													{if $person.status == 'choose'}<label><input type="radio" name="pick[{$person.key|escape}]" value="{$o.value|escape}" {if $smarty.foreach.opts.first}checked="checked"{/if} />{/if}
													{if $person.status == 'choose'}<br />{/if}
													{if $o.existing}
														{tr}Link to existing contact{/tr}: <a href="{$o.existing.view_url|escape}">{$o.existing.title|escape}</a>
													{elseif $o.qid != ''}
														{tr}Create from Wikidata{/tr}: <a href="https://www.wikidata.org/wiki/{$o.qid|escape}" target="_blank" rel="noopener">{$o.label|escape} ({$o.qid|escape})</a>{if $o.description} - {$o.description|escape}{/if}{if $o.fit} <span class="text-success" title="{tr}the description fits the credited job{/tr}">&#10003;</span>{/if}{if $o.from_tmdb} <span class="text-muted">({tr}Q-id from TMDb's external ids{/tr})</span>{/if}{if !$o.is_human} <span class="text-danger">({tr}not a human on Wikidata{/tr})</span>{/if}
													{else}
														{tr}Create from TMDb{/tr} <span class="text-muted">({tr}no Wikidata item{/tr}){if $o.details}: {$o.details.known_for|escape}{if $o.details.birthday}, {tr}born{/tr} {$o.details.birthday|escape}{/if}{/if}</span>
													{/if}
													<a class="small text-muted" href="https://www.themoviedb.org/person/{$o.tmdb_id}" target="_blank" rel="noopener">TMDb {$o.tmdb_id}</a>
													{if $o.aliases}<span class="small text-muted">({tr}same person, also{/tr} {foreach from=$o.aliases item=a name=al}<a href="https://www.themoviedb.org/person/{$a}" target="_blank" rel="noopener">TMDb {$a}</a>{if !$smarty.foreach.al.last}, {/if}{/foreach})</span>{/if}
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
					<p>{tr}Nobody left to look up from here.{/tr}{if $unmatchedSteppedPast} {tr}Those stepped past are listed below.{/tr}{/if}</p>
				{/if}
				{if $unmatchedSteppedPast}
					<h3>{tr}Stepped past{/tr} ({$unmatchedSteppedPast|@count})</h3>
					<p><a class="btn btn-default" href="{$smarty.const.CONTACTWIKI_PKG_URL}load_wiki_film_people.php?fResolve=1&amp;start=0{foreach from=$hiddenFields key=k item=v}&amp;{$k}={$v|escape:'url'}{/foreach}">{tr}Look them up again from the top{/tr}</a>
						<span class="text-muted">{tr}Not found on TMDb, or skipped - they have no contact yet.{/tr}</span></p>
					<table class="table table-condensed">
						<thead><tr><th>{tr}Credited as{/tr}</th><th>{if $scope == 'tv'}{tr}Episodes{/tr}{else}{tr}Credits{/tr}{/if}</th><th>{if $scope == 'tv'}{tr}Seasons{/tr}{else}{tr}Films{/tr}{/if}</th></tr></thead>
						<tbody>
							{foreach from=$unmatchedSteppedPast item=person}
								<tr>
									<td>{$person.name|escape}</td>
									<td>{if $scope == 'tv' && $person.episodes}{$person.episodes} {tr}episodes{/tr}{else}{$person.credits}{/if} <span class="text-muted">({foreach from=$person.roles key=role item=n name=roles}{$n} {$role|escape}{if !$smarty.foreach.roles.last}, {/if}{/foreach})</span></td>
									<td>{foreach from=$person.film_titles item=title name=ft}{$title|escape}{if !$smarty.foreach.ft.last}; {/if}{/foreach}{if $person.more_films} <span class="text-muted">{tr}and{/tr} {$person.more_films} {tr}more{/tr}</span>{/if}</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				{/if}
			{/if}
		{/if}

		{/if}

	</div>
</div>
{/strip}
