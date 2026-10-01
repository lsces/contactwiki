{* One wiki contact's list summary - types, dates, Wikidata - from ContactWikiTrait::enrichListRows()
   ($row.wiki_summary). Used in contact's list_contacts.php Information cell. *}
{strip}
{assign var=ws value=$row.wiki_summary}
{if $ws.types}{$ws.types|join:', '|escape}{else}{if $ws.is_group}{tr}Group{/tr}{else}{tr}Individual{/tr}{/if}{/if}
{if $ws.date_from || $ws.date_to}
	&nbsp;&middot;&nbsp;{if $ws.is_group && !$ws.date_to}{tr}formed{/tr} {/if}{$ws.date_from|escape}{if $ws.date_to} &ndash; {$ws.date_to|escape}{/if}
{/if}
{if $ws.qid}&nbsp;&middot;&nbsp;<a href="https://www.wikidata.org/wiki/{$ws.qid|escape}" target="_blank" rel="noopener">{$ws.qid|escape}</a>{elseif $ws.mbid}&nbsp;&middot;&nbsp;<a href="https://musicbrainz.org/artist/{$ws.mbid|escape}" target="_blank" rel="noopener">MusicBrainz</a>{/if}
{if $ws.gallery_url}&nbsp;&middot;&nbsp;<a href="{$ws.gallery_url|escape}">{tr}Gallery{/tr}</a>{/if}
{/strip}
