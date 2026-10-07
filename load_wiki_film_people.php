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
 * contact, LOOKUP_BATCH (20) at a time:
 *   - TMDb: the credits of the films the person appears in (their own `tmdb` ids) give the person's
 *     TMDb id; several ids = two people of that name, choose;
 *   - Wikidata: P4985 (TMDb person id) gives the Q-id - one SPARQL query for the batch; TMDb's own
 *     external ids are the fallback;
 *   - an existing contact already holding the tmdb id or Q-id is linked, not duplicated;
 *   - otherwise create from the Wikidata item, or from TMDb when Wikidata has none.
 * Nothing is created or linked until the reviewed list is submitted.
 *
 * The same page serves one TV show at a time (scope=tv&program_id=N, "show by show" like the music
 * loading): its seasons' credit directories (FisheyeSeason::deriveCreditDirectory(), built from the
 * episodes - "Build credit directories") and the program's own cast rows are surveyed, matched and
 * linked the same way; TMDb is asked for the show's aggregate credits (one call) instead of each
 * film's. Which cast get a season row at all is the season directory's own rule (FisheyeSeason::deriveCreditDirectory()).
 *
 * @package contactwiki
 * @subpackage functions
 */

namespace Bitweaver\Contactwiki;

use Bitweaver\Fisheyemedia\FisheyeCredits;
use Bitweaver\Fisheyemedia\FisheyeFilm;
use Bitweaver\Fisheyemedia\FisheyeSeason;
use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPackage( 'fisheyemedia' );
$gBitSystem->verifyPermission( 'p_contact_update' );

// Name-matched people linked per submit. No network involved, so this is only a page-size limit.
const LOAD_WIKI_FILM_PEOPLE_BATCH = 100;
// People looked up (TMDb credits, Wikidata, contact creation) per submit - each is several network
// round trips, so a long list is done in small batches with a gap between creations, as the music pass does.
const LOAD_WIKI_FILM_PEOPLE_LOOKUP_BATCH = 20;
const LOAD_WIKI_FILM_PEOPLE_GAP_US = 500000;
// Wall-clock budget for one submit's creations. Production nginx cuts a request after 60s without a response, so a
// run stops starting new people at this point and reports how many are left (they stay ticked for the next press).
const LOAD_WIKI_FILM_PEOPLE_TIME_BUDGET = 35;

$scope = ( $_REQUEST['scope'] ?? '' ) === 'tv' ? 'tv' : 'film';
$programId = $scope === 'tv' ? (int)( $_REQUEST['program_id'] ?? 0 ) : 0;

// TV with no show picked yet: the show picker, nothing else.
if( $scope === 'tv' && !$programId ) {
	$gBitSmarty->assign( 'scope', 'tv' );
	$gBitSmarty->assign( 'programs', FisheyeCredits::programOverview() );
	$gBitSystem->display( 'bitpackage:contactwiki/load_wiki_film_people.tpl', KernelTools::tra( 'Load Wiki TV People' ), [ 'display_mode' => 'edit' ] );
	exit;
}
$program = null;
$contentIds = null;
if( $scope === 'tv' ) {
	foreach( FisheyeCredits::programOverview( $programId ) as $candidateProgram ) {
		if( $candidateProgram['content_id'] === $programId ) {
			$program = $candidateProgram;
		}
	}
	if( !$program ) {
		$gBitSystem->fatalError( KernelTools::tra( 'No such program.' ) );
	}
	$seasonIds = FisheyeCredits::seasonIdsForProgram( $programId );
	$contentIds = array_merge( [ $programId ], $seasonIds );
}

/** The credits survey for this page's scope, shaped as people[].films = the film/season titles. */
$surveyFn = function() use ( $scope, $contentIds ): array {
	if( $scope === 'film' ) {
		return FisheyeFilm::surveyCredits();
	}
	$survey = FisheyeCredits::survey( [ 'fisheyeseason', 'fisheyeprogram' ], $contentIds );
	foreach( $survey['people'] as &$person ) {
		$person['films'] = $person['items'];
	}
	unset( $person );
	return [ 'films' => $survey['items'], 'credits' => $survey['credits'], 'people' => $survey['people'] ];
};

$nameIndex = ContactWikiIndividual::nameIndex();

/** The contacts a credited name could be, from the name index. */
$candidatesFor = function( string $pName ) use ( $nameIndex ): array {
	return array_values( $nameIndex[ContactWikiIndividual::normaliseName( $pName )] ?? [] );
};

$result = null;
$createResult = null;
$reloadResult = null;
$start = max( 0, (int)( $_REQUEST['start'] ?? 0 ) );
$resolve = !empty( $_REQUEST['fResolve'] ) || !empty( $_REQUEST['fCreate'] );

// ---- TV: reload the seasons' episodes from Plex (full cast per episode), which rebuilds each credit directory.
// Each season is dozens of Plex calls and thumbnail fetches, so a few seasons per submit with a Continue.
if( $scope === 'tv' && !empty( $_REQUEST['fReload'] ) ) {
	$reloadResult = [ 'seasons' => [], 'episodes' => 0, 'next' => null, 'total' => count( $seasonIds ) ];
	$reloadStarted = microtime( true );
	for( $i = max( 0, (int)( $_REQUEST['rl'] ?? 0 ) ); $i < count( $seasonIds ); $i++ ) {
		if( microtime( true ) - $reloadStarted > 25 ) {
			$reloadResult['next'] = $i;
			break;
		}
		$seasonStarted = microtime( true );
		$season = new FisheyeSeason( null, $seasonIds[$i] );
		$season->load();
		$reloaded = $season->reloadPlexEpisodes( true );
		// Episode titles are only searchable through the season's own index words - same refresh
		// edit_season.php's Reload Episodes does.
		if( $gBitSystem->isPackageActive( 'search' ) ) {
			require_once SEARCH_PKG_INCLUDE_PATH.'refresh_functions.php';
			\Bitweaver\Liberty\refresh_index( $season );
		}
		$episodeCount = (int)$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'episode' AND `end_date` IS NULL", [ $seasonIds[$i] ] );
		$reloadResult['seasons'][] = [ 'title' => $season->getTitle(), 'matched' => !empty( $reloaded['matched'] ), 'episodes' => $episodeCount,
			'seconds' => round( microtime( true ) - $seasonStarted, 1 ) ];
		$reloadResult['episodes'] += $episodeCount;
	}
	foreach( FisheyeCredits::programOverview( $programId ) as $candidateProgram ) {
		if( $candidateProgram['content_id'] === $programId ) {
			$program = $candidateProgram;
		}
	}
}

// ---- Stage 1 write: link by name.
if( !empty( $_REQUEST['fLink'] ) ) {
	$result = [ 'linked' => [], 'rows' => 0, 'remaining' => 0, 'skipped' => 0 ];
	$survey = $surveyFn();
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
	$createResult = [ 'created' => [], 'linked' => [], 'errors' => [], 'rows' => 0, 'remaining' => 0, 'seconds' => 0 ];
	$createStarted = microtime( true );
	$survey = $surveyFn();
	$picks = (array)( $_REQUEST['pick'] ?? [] );
	$attempted = 0;
	$needGap = false;
	foreach( array_slice( array_map( 'strval', (array)( $_REQUEST['selected2'] ?? [] ) ), 0, LOAD_WIKI_FILM_PEOPLE_LOOKUP_BATCH ) as $key ) {
		$person = $survey['people'][$key] ?? null;
		// "<tmdb person id>:<Q-id or empty>" - both re-validated, the form is not trusted.
		if( !$person || !$person['unlinked_ids'] || !preg_match( '/^(\d+):(Q\d+)?$/', (string)( $picks[$key] ?? '' ), $m ) ) {
			continue;
		}
		$tmdbId = (int)$m[1];
		$qid = $m[2] ?? '';
		if( $attempted && microtime( true ) - $createStarted > LOAD_WIKI_FILM_PEOPLE_TIME_BUDGET ) {
			$createResult['remaining']++;
			continue;
		}
		$attempted++;
		// The pause is for Wikimedia's rate limits - only after a creation that actually called it.
		if( $needGap ) {
			usleep( LOAD_WIKI_FILM_PEOPLE_GAP_US );
		}
		$personStarted = microtime( true );
		$contact = ContactWikiIndividual::findContactByTmdbId( (string)$tmdbId )
			?: ( $qid !== '' ? ContactWikiIndividual::findContactByWikidataQid( $qid ) : null );
		$wasCreated = false;
		$needGap = !$contact && $qid !== '';
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
		// Further TMDb records of the same person (kept as aliases on the contact's tmdb id).
		$also = array_filter( array_map( 'intval', explode( ',', (string)( $_REQUEST['also'][$key] ?? '' ) ) ) );
		if( $also ) {
			$gContent->addTmdbAliases( $also );
		}
		$linkQid = $qid !== '' ? $qid : $gContent->getWikidataQid();
		$rows = FisheyeFilm::linkCreditRows( $person['unlinked_ids'], (int)$gContent->mContentId, $linkQid );
		$createResult['rows'] += $rows;
		$entry = [ 'name' => $person['name'], 'title' => $gContent->getTitle(), 'rows' => $rows, 'view_url' => $gContent->getDisplayUrl(),
			'seconds' => round( microtime( true ) - $personStarted, 1 ) ];
		$createResult[$wasCreated ? 'created' : 'linked'][] = $entry;
	}
}

if( $createResult ) {
	$createResult['seconds'] = round( microtime( true ) - $createStarted, 1 );
}

// ---- Survey after any write, so the page always shows what is left.
$survey = $surveyFn();
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

// After a Create, people left unticked stay at the top of the list until decided (or skipped with "Skip
// these"). Only people with nothing to act on (not found on TMDb) are stepped past, or they would block
// the list for ever.
if( !empty( $_REQUEST['fCreate'] ) ) {
	$stillThere = 0;
	foreach( (array)( $_REQUEST['unres'] ?? [] ) as $key ) {
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
	$tmdbByFilm = $scope === 'film' ? FisheyeFilm::tmdbIdsByFilm( $filmIds ) : [];
	$tvId = $scope === 'tv' ? FisheyeCredits::tmdbIdFor( $programId ) : null;
	$tokenSet = $gBitSystem->getConfig( 'contactwiki_tmdb_token', '' ) !== '';
	$allIds = [];
	foreach( $batch as &$person ) {
		$movieIds = [];
		foreach( array_keys( $person['films'] ) as $filmId ) {
			if( isset( $tmdbByFilm[$filmId] ) ) {
				$movieIds[] = $tmdbByFilm[$filmId];
			}
		}
		$person['tmdb_films'] = $scope === 'tv' ? (int)(bool)$tvId : count( $movieIds );
		$noFound = [ 'ids' => [], 'names' => [], 'error' => null ];
		if( $scope === 'tv' ) {
			$person['found'] = $tvId && $tokenSet ? ContactWikiIndividual::findTmdbPersonForTvCredit( $person['name'], $tvId, array_keys( $person['roles'] ) ) : $noFound;
		} else {
			$person['found'] = $movieIds && $tokenSet ? ContactWikiIndividual::findTmdbPersonForCredit( $person['name'], $movieIds, array_keys( $person['roles'] ) ) : $noFound;
		}
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
		// TMDb often holds several records for one person (R. D. Wingfield: two "Writing" ids). Within one
		// show, the same name doing the same job more than once is the same person: take the record that
		// has a Wikidata item (or the lowest id) and keep the others as aliases on the contact. Two
		// different Wikidata items stay a choice.
		if( $scope === 'tv' && count( $person['options'] ) > 1 ) {
			$withQid = array_values( array_filter( $person['options'], fn( $o ) => $o['qid'] !== '' ) );
			if( count( array_unique( array_column( $withQid, 'qid' ) ) ) <= 1 ) {
				$allOptions = $person['options'];
				usort( $allOptions, fn( $a, $b ) => $a['tmdb_id'] <=> $b['tmdb_id'] );
				$primary = $withQid[0] ?? $allOptions[0];
				$primary['aliases'] = array_values( array_unique( array_diff( array_column( $allOptions, 'tmdb_id' ), [ $primary['tmdb_id'] ] ) ) );
				$primary['existing'] = $primary['existing'] ?: ( array_values( array_filter( array_column( $allOptions, 'existing' ) ) )[0] ?? null );
				$person['options'] = [ $primary ];
			}
		}
		if( !$person['options'] ) {
			$person['status'] = 'unresolved';
			$person['reason'] = !$tokenSet ? KernelTools::tra( 'No TMDb access token is set.' )
				: ( !$person['tmdb_films'] ? ( $scope === 'tv' ? KernelTools::tra( 'This show has no TMDb id.' ) : KernelTools::tra( 'None of its films carries a TMDb id.' ) )
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

$gBitSmarty->assign( 'scope', $scope );
$gBitSmarty->assign( 'program', $program );
// The show's own page (the generic dispatcher routes to whatever display page the program has) and "finished":
// the show has credits and every one is linked to a contact.
$gBitSmarty->assign( 'programUrl', $programId ? BIT_ROOT_URL.'index.php?content_id='.$programId : null );
$gBitSmarty->assign( 'finished', $scope === 'tv' && $counts['linked'] > 0 && !$counts['match'] && !$counts['choose'] && !$counts['unmatched'] );
$gBitSmarty->assign( 'reloadResult', $reloadResult );
$gBitSmarty->assign( 'hiddenFields', array_filter( [ 'scope' => $scope === 'tv' ? 'tv' : null, 'program_id' => $programId ?: null ] ) );
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

$pageTitle = $scope === 'tv' ? KernelTools::tra( 'Load Wiki TV People' ).': '.$program['title'] : KernelTools::tra( 'Load Wiki Film People' );
$gBitSystem->display( 'bitpackage:contactwiki/load_wiki_film_people.tpl', $pageTitle, [ 'display_mode' => 'edit' ] );
