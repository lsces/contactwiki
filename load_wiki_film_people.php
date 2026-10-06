<?php
/**
 * The people pass for Film credits - the film-side counterpart of load_wiki_people.php. Film cast and
 * crew are plain text on the film's director/writer/star xref rows; this page links each distinct
 * credited name to a wiki contact (xref = contact content_id, xkey = its Wikidata Q-id), the same shape
 * an album credit takes, creating the contact when there is none.
 *
 * Stage 1, no network - name matching against the existing contacts (accents/case/punctuation folded,
 * "Surname, Forename" flipped):
 *   - every credit already linked                                    -> done
 *   - a name matching exactly one contact                            -> link, pre-ticked
 *   - a name matching more than one                                  -> choose which, then link
 *
 * Stage 2, only when asked ("Look up next batch"), for the most-credited people still without a
 * contact, LOOKUP_BATCH at a time:
 *   - TMDb: the credits of the films the person appears in (their own `tmdb` ids) give the person's
 *     TMDb id; several ids = two people of that name, choose;
 *   - Wikidata: P4985 (TMDb person id) gives the Q-id - one SPARQL query for the batch; TMDb's own
 *     external ids are the fallback;
 *   - an existing contact already holding the tmdb id or Q-id is linked, not duplicated;
 *   - otherwise create from the Wikidata item, or from TMDb when Wikidata has none.
 * Nothing is created or linked until the reviewed list is submitted.
 *
 * @package contactwiki
 * @subpackage functions
 */

namespace Bitweaver\Contactwiki;

use Bitweaver\Fisheyemedia\FisheyeFilm;
use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPackage( 'fisheyemedia' );
$gBitSystem->verifyPermission( 'p_contact_update' );

// Name-matched people linked per submit. No network involved, so this is only a page-size limit.
const LOAD_WIKI_FILM_PEOPLE_BATCH = 100;
// People looked up (TMDb credits, Wikidata, contact creation) per submit - each is several network
// round trips, so a long list is done in small batches with a gap between creations, as the music pass does.
const LOAD_WIKI_FILM_PEOPLE_LOOKUP_BATCH = 10;
const LOAD_WIKI_FILM_PEOPLE_GAP_US = 500000;

$nameIndex = ContactWikiIndividual::nameIndex();

/** The contacts a credited name could be, from the name index. */
$candidatesFor = function( string $pName ) use ( $nameIndex ): array {
	return array_values( $nameIndex[ContactWikiIndividual::normaliseName( $pName )] ?? [] );
};

$result = null;
$createResult = null;
$start = max( 0, (int)( $_REQUEST['start'] ?? 0 ) );
$resolve = !empty( $_REQUEST['fResolve'] ) || !empty( $_REQUEST['fCreate'] );

// ---- Stage 1 write: link by name.
if( !empty( $_REQUEST['fLink'] ) ) {
	$result = [ 'linked' => [], 'rows' => 0, 'remaining' => 0, 'skipped' => 0 ];
	$survey = FisheyeFilm::surveyCredits();
	$chosen = (array)( $_REQUEST['contact'] ?? [] );
	$done = 0;
	foreach( (array)( $_REQUEST['selected'] ?? [] ) as $key ) {
		$key = (string)$key;
		$person = $survey['people'][$key] ?? null;
		$contactId = (int)( $chosen[$key] ?? 0 );
		if( !$person || !$person['unlinked_ids'] ) {
			continue;
		}
		if( $done >= LOAD_WIKI_FILM_PEOPLE_BATCH ) {
			$result['remaining']++;
			continue;
		}
		// The posted contact must be one this name really matches - never trust the form for the id.
		$contact = null;
		foreach( $candidatesFor( $person['name'] ) as $candidate ) {
			if( $candidate['content_id'] === $contactId ) {
				$contact = $candidate;
			}
		}
		if( !$contact ) {
			$result['skipped']++;
			continue;
		}
		$rows = FisheyeFilm::linkCreditRows( $person['unlinked_ids'], $contact['content_id'], $contact['qid'] );
		$done++;
		$result['rows'] += $rows;
		$result['linked'][] = [ 'name' => $person['name'], 'contact' => $contact['title'], 'rows' => $rows,
			'view_url' => CONTACTWIKI_PKG_URL.'view.php?content_id='.$contact['content_id'] ];
	}
}

// ---- Stage 2 write: create (or find) the contact for each ticked person and link their credits.
if( !empty( $_REQUEST['fCreate'] ) ) {
	$createResult = [ 'created' => [], 'linked' => [], 'errors' => [], 'rows' => 0 ];
	$survey = FisheyeFilm::surveyCredits();
	$picks = (array)( $_REQUEST['pick'] ?? [] );
	$attempted = 0;
	foreach( array_slice( array_map( 'strval', (array)( $_REQUEST['selected2'] ?? [] ) ), 0, LOAD_WIKI_FILM_PEOPLE_LOOKUP_BATCH ) as $key ) {
		$person = $survey['people'][$key] ?? null;
		// "<tmdb person id>:<Q-id or empty>" - both re-validated, the form is not trusted.
		if( !$person || !$person['unlinked_ids'] || !preg_match( '/^(\d+):(Q\d+)?$/', (string)( $picks[$key] ?? '' ), $m ) ) {
			continue;
		}
		$tmdbId = (int)$m[1];
		$qid = $m[2] ?? '';
		if( $attempted++ ) {
			usleep( LOAD_WIKI_FILM_PEOPLE_GAP_US );
		}
		$contact = ContactWikiIndividual::findContactByTmdbId( (string)$tmdbId )
			?: ( $qid !== '' ? ContactWikiIndividual::findContactByWikidataQid( $qid ) : null );
		$wasCreated = false;
		if( $contact ) {
			$gContent = new ContactWikiIndividual( null, $contact['content_id'] );
			$gContent->load();
		} else {
			$created = $qid !== '' ? ContactWikiIndividual::createFromWikidata( $qid, false ) : ContactWikiIndividual::createFromTmdb( $tmdbId );
			if( empty( $created['content'] ) ) {
				$createResult['errors'][] = [ 'name' => $person['name'], 'error' => $created['error'] ];
				continue;
			}
			$gContent = $created['content'];
			$wasCreated = true;
		}
		// The contact must carry the TMDb id it was found by (a Wikidata item reached through TMDb's own
		// external ids may not hold P4985 yet), so the next credit of this person matches it.
		if( !ContactWikiIndividual::findContactByTmdbId( (string)$tmdbId ) ) {
			$gContent->upsertXref( $gContent->mContentId, 'tmdb', [ 'xkey_ext' => (string)$tmdbId ] );
		}
		$linkQid = $qid !== '' ? $qid : $gContent->getWikidataQid();
		$rows = FisheyeFilm::linkCreditRows( $person['unlinked_ids'], (int)$gContent->mContentId, $linkQid );
		$createResult['rows'] += $rows;
		$entry = [ 'name' => $person['name'], 'title' => $gContent->getTitle(), 'rows' => $rows, 'view_url' => $gContent->getDisplayUrl() ];
		$createResult[$wasCreated ? 'created' : 'linked'][] = $entry;
	}
}

// ---- Survey after any write, so the page always shows what is left.
$survey = FisheyeFilm::surveyCredits();
$counts = [ 'linked' => 0, 'match' => 0, 'choose' => 0, 'unmatched' => 0 ];
$reviewList = [];
$unmatchedAll = [];
foreach( $survey['people'] as $key => $person ) {
	if( !$person['unlinked_ids'] ) {
		$counts['linked']++;
		continue;
	}
	$candidates = $candidatesFor( $person['name'] );
	foreach( $candidates as &$candidate ) {
		$candidate['view_url'] = CONTACTWIKI_PKG_URL.'view.php?content_id='.$candidate['content_id'];
	}
	unset( $candidate );
	$person['key'] = $key;
	$person['candidates'] = $candidates;
	$person['film_titles'] = array_slice( array_values( $person['films'] ), 0, 4 );
	$person['more_films'] = max( 0, count( $person['films'] ) - 4 );
	if( count( $candidates ) === 1 ) {
		$counts['match']++;
		$reviewList[] = $person;
	} elseif( $candidates ) {
		$counts['choose']++;
		$reviewList[] = $person;
	} else {
		$counts['unmatched']++;
		$unmatchedAll[] = $person;
	}
}

// After a Create, the people of the previous batch who are still unlinked (unticked, unresolved,
// failed) stay in the list - step past them so the next batch is new people.
if( !empty( $_REQUEST['fCreate'] ) ) {
	$stillThere = 0;
	foreach( (array)( $_REQUEST['batch'] ?? [] ) as $key ) {
		if( !empty( $survey['people'][(string)$key]['unlinked_ids'] ) ) {
			$stillThere++;
		}
	}
	$start += $stillThere;
}

// ---- Stage 2 lookup: TMDb person ids from the films' credits, then Wikidata Q-ids, for the next batch.
$lookup = null;
if( $resolve ) {
	$batch = array_slice( $unmatchedAll, $start, LOAD_WIKI_FILM_PEOPLE_LOOKUP_BATCH );
	$filmIds = [];
	foreach( $batch as $person ) {
		$filmIds = array_merge( $filmIds, array_keys( $person['films'] ) );
	}
	$tmdbByFilm = FisheyeFilm::tmdbIdsByFilm( $filmIds );
	$tokenSet = $gBitSystem->getConfig( 'contactwiki_tmdb_token', '' ) !== '';
	$allIds = [];
	foreach( $batch as &$person ) {
		$movieIds = [];
		foreach( array_keys( $person['films'] ) as $filmId ) {
			if( isset( $tmdbByFilm[$filmId] ) ) {
				$movieIds[] = $tmdbByFilm[$filmId];
			}
		}
		$person['tmdb_films'] = count( $movieIds );
		$person['found'] = $movieIds && $tokenSet ? ContactWikiIndividual::findTmdbPersonForCredit( $person['name'], $movieIds ) : [ 'ids' => [], 'names' => [], 'error' => null ];
		$allIds = array_merge( $allIds, $person['found']['ids'] );
	}
	unset( $person );
	$wikidata = $allIds ? ContactWikiIndividual::lookupWikidataByTmdbPersonIds( $allIds ) : [];
	$wikidataError = $wikidata === null;
	$wikidataErrorReason = $wikidataError ? ContactWikiIndividual::getLastFetchError() : null;
	$wikidata ??= [];

	foreach( $batch as &$person ) {
		$person['options'] = [];
		foreach( $person['found']['ids'] as $tmdbId ) {
			$qids = $wikidata[(string)$tmdbId] ?? [];
			$details = null;
			if( count( $qids ) !== 1 ) {
				// Wikidata gave none (or several): TMDb's own record shows who this is, and its external
				// ids may carry the Q-id Wikidata's P4985 doesn't yet.
				$details = ContactWikiIndividual::fetchTmdbPerson( $tmdbId );
				if( !$qids && !empty( $details['wikidata_id'] ) && preg_match( '/^Q\d+$/', $details['wikidata_id'] ) ) {
					$qids = [ [ 'qid' => $details['wikidata_id'], 'label' => $details['name'], 'is_human' => true, 'from_tmdb' => true ] ];
				}
			}
			$existing = ContactWikiIndividual::findContactByTmdbId( (string)$tmdbId )
				?: ( count( $qids ) === 1 ? ContactWikiIndividual::findContactByWikidataQid( $qids[0]['qid'] ) : null );
			if( $existing ) {
				$existing['view_url'] = CONTACTWIKI_PKG_URL.'view.php?content_id='.$existing['content_id'];
			}
			// One option per (TMDb person, Wikidata item): several Wikidata items on one TMDb id is a
			// duplicate on Wikidata's side, offered as a choice rather than guessed.
			foreach( ( $qids ?: [ null ] ) as $wd ) {
				$person['options'][] = [
					'tmdb_id'  => $tmdbId,
					'tmdb_name' => $person['found']['names'][$tmdbId] ?? '',
					'qid'      => $wd['qid'] ?? '',
					'label'    => $wd['label'] ?? '',
					'is_human' => $wd['is_human'] ?? true,
					'from_tmdb' => !empty( $wd['from_tmdb'] ),
					'details'  => $details,
					'existing' => $existing,
					'value'    => $tmdbId.':'.( $wd['qid'] ?? '' ),
				];
			}
		}
		if( !$person['options'] ) {
			$person['status'] = 'unresolved';
			$person['reason'] = !$tokenSet ? KernelTools::tra( 'No TMDb access token is set.' )
				: ( !$person['tmdb_films'] ? KernelTools::tra( 'None of its films carries a TMDb id.' )
				: ( $person['found']['error'] ? KernelTools::tra( 'TMDb lookup failed' ).': '.$person['found']['error']
				: KernelTools::tra( 'Not found in the TMDb credits of its films under this name.' ) ) );
		} elseif( count( $person['options'] ) > 1 ) {
			$person['status'] = 'choose';
		} elseif( $person['options'][0]['existing'] ) {
			$person['status'] = 'link_existing';
		} else {
			$person['status'] = $person['options'][0]['qid'] !== '' ? 'create' : 'create_tmdb';
		}
	}
	unset( $person );
	$lookup = [ 'people' => $batch, 'wikidataError' => $wikidataError, 'wikidataErrorReason' => $wikidataErrorReason,
		'tokenSet' => $tokenSet, 'remaining' => max( 0, count( $unmatchedAll ) - $start - count( $batch ) ) ];
}

$gBitSmarty->assign( 'survey', [ 'films' => $survey['films'], 'credits' => $survey['credits'], 'people' => count( $survey['people'] ) ] );
$gBitSmarty->assign( 'counts', $counts );
$gBitSmarty->assign( 'reviewList', array_slice( $reviewList, 0, LOAD_WIKI_FILM_PEOPLE_BATCH ) );
$gBitSmarty->assign( 'reviewTotal', count( $reviewList ) );
$gBitSmarty->assign( 'unmatchedShown', array_slice( $unmatchedAll, $start, 30 ) );
$gBitSmarty->assign( 'start', $start );
$gBitSmarty->assign( 'lookupBatch', LOAD_WIKI_FILM_PEOPLE_LOOKUP_BATCH );
$gBitSmarty->assign( 'lookup', $lookup );
$gBitSmarty->assign( 'result', $result );
$gBitSmarty->assign( 'createResult', $createResult );

$gBitSystem->display( 'bitpackage:contactwiki/load_wiki_film_people.tpl', KernelTools::tra( 'Load Wiki Film People' ), [ 'display_mode' => 'edit' ] );
