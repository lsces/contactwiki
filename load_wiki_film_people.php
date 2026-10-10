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
 * contact, LOOKUP_BATCH (100) at a time:
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

use Bitweaver\Fisheye\FisheyeGallery;
use Bitweaver\Fisheyemedia\FisheyeCredits;
use Bitweaver\Fisheyemedia\FisheyeFilm;
use Bitweaver\Fisheyemedia\FisheyeProgram;
use Bitweaver\Fisheyemedia\FisheyeSeason;
use Bitweaver\Fisheyemedia\FisheyeTvdb;
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
const LOAD_WIKI_FILM_PEOPLE_LOOKUP_BATCH = 100;
const LOAD_WIKI_FILM_PEOPLE_GAP_US = 500000;
// Wall-clock budget for one submit's creations. Production nginx cuts a request after 60s without a response, so a
// run stops starting new people at this point and reports how many are left (they stay ticked for the next press).
const LOAD_WIKI_FILM_PEOPLE_TIME_BUDGET = 40;

$scope = ( $_REQUEST['scope'] ?? '' ) === 'tv' ? 'tv' : 'film';
$programId = $scope === 'tv' ? (int)( $_REQUEST['program_id'] ?? 0 ) : 0;

// "Mark finished" / "Reopen": a show whose people are dealt with but never reach 0 unlinked (a documentary's director left as plain text, a show Plex
// holds no people for) is taken off the picker by hand - a content preference on the show, no schema.
const LOAD_WIKI_PEOPLE_DONE_PREF = 'contactwiki_people_done';
if( $scope === 'tv' && $programId && ( !empty( $_REQUEST['fMarkDone'] ) || !empty( $_REQUEST['fReopen'] ) ) ) {
	$gBitSystem->verifyPermission( 'p_contact_update' );
	$programObject = \Bitweaver\Liberty\LibertyContent::getLibertyObject( $programId );
	if( $programObject && $programObject->isValid() ) {
		$programObject->storePreference( LOAD_WIKI_PEOPLE_DONE_PREF, !empty( $_REQUEST['fMarkDone'] ) ? 'y' : null );
	}
	if( !empty( $_REQUEST['fMarkDone'] ) ) {
		KernelTools::bit_redirect( CONTACTWIKI_PKG_URL.'load_wiki_film_people.php?scope=tv' );
	}
}
$markedDone = array_fill_keys( array_map( 'intval', $gBitDb->getCol( "SELECT `content_id` FROM `".BIT_DB_PREFIX."liberty_content_prefs` WHERE `pref_name` = ? AND `pref_value` = 'y'", [ LOAD_WIKI_PEOPLE_DONE_PREF ] ) ?: [] ), true );

// TV with no show picked yet: the show picker, nothing else.
if( $scope === 'tv' && !$programId ) {
	$gBitSmarty->assign( 'scope', 'tv' );
	// A finished show - credits built and none left unlinked, or marked finished by hand - is hidden from the picker unless ?all=1 asks for the full list.
	$allPrograms = FisheyeCredits::programOverview();
	$showAll = !empty( $_REQUEST['all'] );
	$finished = array_filter( $allPrograms, fn( $prog ) => isset( $markedDone[$prog['content_id']] ) || ( $prog['built'] > 0 && $prog['credits'] > 0 && !$prog['unlinked'] ) );
	$gBitSmarty->assign( 'markedDone', $markedDone );
	$gBitSmarty->assign( 'programs', $showAll ? $allPrograms : array_values( array_diff_key( $allPrograms, $finished ) ) );
	$gBitSmarty->assign( 'finishedCount', count( $finished ) );
	$gBitSmarty->assign( 'showAll', $showAll );
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

// What each person is on screen ("Master Carpenter" from a "Self - ..." role): for the Wikidata job fit and a name-only contact's description.
$functionsByName = FisheyeCredits::functionsByActor( $contentIds );
$rolesByName = FisheyeCredits::rolesByActor( $contentIds );   // every role text per person: who is only ever on screen as themselves is not tagged Actor

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
// A linked credit's key is its contact's Wikidata id; make any that disagree (an item merged since, a contact edited) agree again.
FisheyeCredits::syncLinkKeys();

/** The contacts a credited name could be, from the name index. */
$candidatesFor = function( string $pName ) use ( $nameIndex ): array {
	return array_values( $nameIndex[ContactWikiIndividual::normaliseName( $pName )] ?? [] );
};

$result = null;
$createResult = null;
$reloadResult = null;
$start = max( 0, (int)( $_REQUEST['start'] ?? 0 ) );
$resolve = !empty( $_REQUEST['fResolve'] ) || !empty( $_REQUEST['fCreate'] );

// ---- Films: reload every film's credits from Plex (the full cast - films registered earlier hold only the first five stars).
// Credits only, no thumbnails or ffprobe, so a few hundred films per submit would be possible; a time budget with a Continue keeps it inside the server limit.
if( $scope === 'film' && !empty( $_REQUEST['fReload'] ) ) {
	$filmIds = array_map( 'intval', $gBitDb->getCol( "SELECT `content_id` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_type_guid` = 'fisheyefilm' ORDER BY `content_id`" ) ?: [] );
	$reloadResult = [ 'films' => 0, 'unmatched' => [], 'stars' => 0, 'next' => null, 'total' => count( $filmIds ) ];
	$reloadStarted = microtime( true );
	for( $i = max( 0, (int)( $_REQUEST['rl'] ?? 0 ) ); $i < count( $filmIds ); $i++ ) {
		if( microtime( true ) - $reloadStarted > 25 ) {
			$reloadResult['next'] = $i;
			break;
		}
		$film = new FisheyeFilm( null, $filmIds[$i] );
		$film->load();
		$reloaded = $film->reloadPlexCredits();
		$reloadResult['films']++;
		if( $reloaded['matched'] ) {
			$reloadResult['stars'] += $reloaded['counts']['star'] ?? 0;
		} elseif( count( $reloadResult['unmatched'] ) < 30 ) {
			$reloadResult['unmatched'][] = $film->getTitle();
		}
	}
	$reloadResult['seconds'] = round( microtime( true ) - $reloadStarted, 1 );
}

// ---- TV: reload the seasons' episodes from Plex (full cast per episode), which rebuilds each credit directory.
// Each season is dozens of Plex calls and thumbnail fetches, so a few seasons per submit with a Continue.
// ---- TV: fill the gaps Plex leaves from TheTVDB (a documentary's presenters, some series' episode writers/directors/guests). Press again to carry on.
$tvdbResult = null;
if( $scope === 'tv' && $programId && !empty( $_REQUEST['fTvdb'] ) ) {
	if( !FisheyeTvdb::configured() ) {
		$tvdbResult = [ 'ok' => false, 'error' => KernelTools::tra( 'No TheTVDB API key is set (Media Library Settings).' ) ];
	} else {
		set_time_limit( 120 );
		$tvdbProgram = new FisheyeProgram( null, $programId );
		$tvdbProgram->load();
		$tvdbResult = $tvdbProgram->fillCreditsFromTvdb( 30.0 );
	}
}

if( $scope === 'tv' && !empty( $_REQUEST['fReload'] ) ) {
	$reloadResult = [ 'seasons' => [], 'episodes' => 0, 'next' => null, 'total' => count( $seasonIds ), 'creators' => null ];
	// The show's creators (TMDb's created_by - not in its credits) go onto the program as `creator` rows, once, on the first batch.
	if( (int)( $_REQUEST['rl'] ?? 0 ) === 0 ) {
		$reloadResult['creators'] = ( function() use ( $programId ) {
			$tvId = FisheyeCredits::tmdbIdFor( $programId );
			$creators = $tvId ? ContactWikiIndividual::fetchTmdbCreators( $tvId ) : null;
			$program = FisheyeGallery::lookup( [ 'content_id' => $programId ] );
			if( $creators === null || !$program || !$program->isValid() ) {
				return [ 'names' => [], 'note' => !$tvId ? KernelTools::tra( 'This show has no TMDb id.' ) : ( ContactWikiIndividual::getLastFetchError() ?: '' ) ];
			}
			$known = $creators ? FisheyeCredits::linkedContactsByName( array_column( $creators, 'name' ) ) : [];
			$wanted = [];
			foreach( $creators as $i => $creator ) {
				$row = [ 'key' => $creator['name'], 'xkey_ext' => $creator['name'], 'xorder' => $i + 1 ];
				if( $link = ( $known[mb_strtolower( $creator['name'] )] ?? null ) ) {
					$row['xref'] = $link['xref'];
					$row['xkey'] = $link['xkey'];
				}
				$wanted[] = $row;
			}
			$program->reconcileXrefItem( 'creator', $wanted, 'xkey_ext', false, true );
			return [ 'names' => array_column( $creators, 'name' ), 'note' => '' ];
		} )();
	}
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
	$createResult = [ 'created' => [], 'linked' => [], 'errors' => [], 'rows' => 0, 'remaining' => 0, 'seconds' => 0,
		'kinds' => [ 'wikidata' => 0, 'tmdb' => 0, 'nameonly' => 0, 'existing' => 0 ] ];
	$createStarted = microtime( true );
	ContactWikiIndividual::$skipPhotos = empty( $_REQUEST['photos'] );
	ContactWikiIndividual::$skipBiography = empty( $_REQUEST['bios'] );
	$stepStart = microtime( true );
	$survey = $surveyFn();
	ContactWikiIndividual::$stepTimings['credit survey'] = ( ContactWikiIndividual::$stepTimings['credit survey'] ?? 0.0 ) + microtime( true ) - $stepStart;
	$picks = (array)( $_REQUEST['pick'] ?? [] );
	$attempted = 0;
	$needGap = false;
	$ticked = array_slice( array_map( 'strval', (array)( $_REQUEST['selected2'] ?? [] ) ), 0, LOAD_WIKI_FILM_PEOPLE_LOOKUP_BATCH );
	// Everyone about to be created from a Wikidata item has their entity, Wikipedia text and photo fetched together
	// up front (a few at a time), so the loop below makes no Wikimedia requests of its own.
	// What to create or link, one entry per contact: [ key, person, TMDb id, Wikidata Q-id, whether to keep the form's aliases ]. A pick of
	// "split" is a name that is really several people (a writer and an actor of one name): each gets its own contact and only the credits of
	// the films it was found on - the form carries "tmdb:Q|film,film" for each, and everything is re-validated, the form is not trusted.
	$work = [];
	foreach( $ticked as $key ) {
		$person = $survey['people'][$key] ?? null;
		if( !$person || !$person['unlinked_ids'] ) {
			continue;
		}
		$pick = (string)( $picks[$key] ?? '' );
		if( $pick === 'split' && $scope === 'film' ) {
			foreach( (array)( $_REQUEST['splitopt'][$key] ?? [] ) as $option ) {
				if( !preg_match( '/^(\d+):(Q\d+)?\|([\d,]+)$/', (string)$option, $sm ) ) {
					continue;
				}
				$rows = [];
				foreach( array_map( 'intval', explode( ',', $sm[3] ) ) as $filmId ) {
					$rows = array_merge( $rows, $person['unlinked_by_item'][$filmId] ?? [] );
				}
				if( $rows ) {
					$part = $person;
					$part['unlinked_ids'] = $rows;
					$work[] = [ $key, $part, (int)$sm[1], $sm[2] ?? '', false ];
				}
			}
		} elseif( preg_match( '/^(\d+):(Q\d+)?$/', $pick, $m ) ) {
			$work[] = [ $key, $person, (int)$m[1], $m[2] ?? '', true ];
		}
	}
	$prefetch = [];
	$stepStart = microtime( true );
	foreach( $work as [ , , $workTmdb, $workQid ] ) {
		if( $workQid !== '' && !ContactWikiIndividual::findContactByTmdbId( (string)$workTmdb ) && !ContactWikiIndividual::findContactByWikidataQid( $workQid ) ) {
			$prefetch[] = $workQid;
		}
	}
	ContactWikiIndividual::$stepTimings['find existing'] = ( ContactWikiIndividual::$stepTimings['find existing'] ?? 0.0 ) + microtime( true ) - $stepStart;
	$createResult['prefetch'] = $prefetch ? ContactWikiIndividual::prefetchWikidata( $prefetch ) : null;
	foreach( $work as [ $key, $person, $tmdbId, $qid, $keepAlso ] ) {
		if( $attempted && microtime( true ) - $createStarted > LOAD_WIKI_FILM_PEOPLE_TIME_BUDGET ) {
			$createResult['remaining']++;
			continue;
		}
		$attempted++;
		// The pause is for Wikimedia's rate limits - only before a person whose data was NOT prefetched (a fallback fetch).
		if( $needGap ) {
			usleep( LOAD_WIKI_FILM_PEOPLE_GAP_US );
		}
		$personStarted = microtime( true );
		$stepStart = $personStarted;
		// tmdb id 0 = no TMDb record: a Wikidata item picked by name ("0:Q123") or a contact from the name alone ("0:").
		$contact = ( $tmdbId ? ContactWikiIndividual::findContactByTmdbId( (string)$tmdbId ) : null )
			?: ( $qid !== '' ? ContactWikiIndividual::findContactByWikidataQid( $qid ) : null );
		ContactWikiIndividual::$stepTimings['find existing'] = ( ContactWikiIndividual::$stepTimings['find existing'] ?? 0.0 ) + microtime( true ) - $stepStart;
		$wasCreated = false;
		$needGap = !$contact && $qid !== '' && !\Bitweaver\Contactwiki\WikimediaCache::hasEntity( $qid );
		if( $contact ) {
			$gContent = new ContactWikiIndividual( null, $contact['content_id'] );
			$gContent->load();
		} else {
			$asThemselves = FisheyeCredits::appearsOnlyAsThemselves( $rolesByName[mb_strtolower( $person['name'] )] ?? [] );
			$created = $qid !== '' ? ContactWikiIndividual::createFromWikidata( $qid, false )
				: ( $tmdbId ? ContactWikiIndividual::createFromTmdb( $tmdbId, $asThemselves ) : ContactWikiIndividual::createNameOnly( $person['name'], array_keys( $person['roles'] ), $asThemselves ) );
			if( empty( $created['content'] ) ) {
				$createResult['errors'][] = [ 'name' => $person['name'], 'error' => $created['error'] ];
				continue;
			}
			$gContent = $created['content'];
			$wasCreated = true;
			if( $qid === '' && !$tmdbId ) {
				// A contact from the name alone has nothing else to say who it is: describe it from its credits ("Master Carpenter on This Old House").
				$gContent->saveStoredDescription( ContactWikiIndividual::plainTextToHtmlParagraphs( FisheyeCredits::describeAppearances(
					array_keys( $functionsByName[mb_strtolower( $person['name'] )] ?? [] ) ?: array_map( fn( $r ) => $r === 'star' ? ( $asThemselves ? 'Appears' : 'Actor' ) : ucfirst( $r ), array_keys( $person['roles'] ) ),
					array_values( $person['items'] ?? [] ) ) ) );
			}
		}
		// The contact must carry the TMDb id it was found by (a Wikidata item reached through TMDb's own
		// external ids may not hold P4985 yet), so the next credit of this person matches it.
		if( $tmdbId && !ContactWikiIndividual::findContactByTmdbId( (string)$tmdbId ) ) {
			$gContent->upsertXref( $gContent->mContentId, 'tmdb', [ 'xkey_ext' => (string)$tmdbId ] );
		}
		// Further TMDb records of the same person (kept as aliases on the contact's tmdb id).
		$also = $keepAlso ? array_filter( array_map( 'intval', explode( ',', (string)( $_REQUEST['also'][$key] ?? '' ) ) ) ) : [];
		if( $also ) {
			$gContent->addTmdbAliases( $also );
		}
		// The contact's own current Wikidata id (a merged item's old id is what TMDb and old links still hold).
		$linkQid = $gContent->getWikidataQid() ?: $qid;
		$stepStart = microtime( true );
		$rows = FisheyeFilm::linkCreditRows( $person['unlinked_ids'], (int)$gContent->mContentId, $linkQid );
		ContactWikiIndividual::$stepTimings['credit rows linked'] = ( ContactWikiIndividual::$stepTimings['credit rows linked'] ?? 0.0 ) + microtime( true ) - $stepStart;
		$createResult['rows'] += $rows;
		$entry = [ 'name' => $person['name'], 'title' => $gContent->getTitle(), 'rows' => $rows, 'view_url' => $gContent->getDisplayUrl(),
			'seconds' => round( microtime( true ) - $personStarted, 1 ) ];
		$createResult[$wasCreated ? 'created' : 'linked'][] = $entry;
		// What it took: a Wikidata item (the slow, three-record kind), TMDb alone (a fraction of a second), or no creation at all.
		$createResult['kinds'][!$wasCreated ? 'existing' : ( $qid !== '' ? 'wikidata' : ( $tmdbId ? 'tmdb' : 'nameonly' ) )]++;
	}
}

if( $createResult ) {
	$createResult['seconds'] = round( microtime( true ) - $createStarted, 1 );
	$createResult['steps'] = array_map( fn( $v ) => round( $v, 1 ), ContactWikiIndividual::$stepTimings );
}

// ---- Survey after any write, so the page always shows what is left.
$survey = $surveyFn();
$counts = [ 'linked' => 0, 'match' => 0, 'choose' => 0, 'unmatched' => 0, 'minor' => 0 ];
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
	} elseif( !empty( $person['minor'] ) ) {
		// Far down a film's cast and not an existing contact: stays a plain-text credit.
		$counts['minor']++;
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
	// TMDb movie id -> the film records that carry it, to tell which films each TMDb person was found on.
	$filmIdsByTmdb = [];
	foreach( $tmdbByFilm as $filmId => $movieId ) {
		$filmIdsByTmdb[$movieId][] = $filmId;
	}
	$wikidata = $allIds ? ContactWikiIndividual::lookupWikidataByTmdbPersonIds( $allIds ) : [];
	$wikidataError = $wikidata === null;
	$wikidataErrorReason = $wikidataError ? ContactWikiIndividual::getLastFetchError() : null;
	$wikidata ??= [];

	$seriesIndex = null;
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
					'description' => $wd['description'] ?? '',
					'fit'      => ContactWikiIndividual::descriptionFitsRoles( (string)( $wd['description'] ?? '' ), array_keys( $person['roles'] ), array_keys( $functionsByName[mb_strtolower( $person['name'] )] ?? [] ) ),
					'is_human' => $wd['is_human'] ?? true,
					'statements' => (int)( $wd['statements'] ?? 0 ),
					'sitelinks'  => (int)( $wd['sitelinks'] ?? 0 ),
					'from_tmdb' => !empty( $wd['from_tmdb'] ),
					'details'  => $details,
					'existing' => $existing,
					'value'    => $tmdbId.':'.( $wd['qid'] ?? '' ),
					'film_ids' => array_values( array_unique( array_merge( [], ...array_map( fn( $m ) => $filmIdsByTmdb[$m] ?? [], $person['found']['films'][$tmdbId] ?? [] ) ) ) ),
				];
			}
		}
		// Two TMDb people of one name found on different films (a writer on one film, an actor of the same name on another) are two people, not
		// one: offer to create each and link only the credits of the films it was found on. One option per TMDb person, with its films.
		$person['split'] = null;
		if( $scope === 'film' && count( $person['options'] ) > 1 ) {
			$byTmdb = [];
			foreach( $person['options'] as $o ) {
				$byTmdb[$o['tmdb_id']] ??= $o;
			}
			$people = array_values( array_filter( $byTmdb, fn( $o ) => $o['film_ids'] ) );
			$seenFilms = [];
			$disjoint = count( $people ) > 1;
			foreach( $people as $o ) {
				if( array_intersect( $o['film_ids'], $seenFilms ) ) {
					$disjoint = false;
				}
				$seenFilms = array_merge( $seenFilms, $o['film_ids'] );
			}
			if( $disjoint ) {
				// Two different Wikidata items on different films is the clear case: that is the default choice.
				$qs = array_filter( array_column( $people, 'qid' ) );
				$person['split'] = [ 'people' => $people, 'default' => count( $qs ) === count( $people ) && count( array_unique( $qs ) ) === count( $qs ) ];
			}
		}
		// Where a TMDb id sits on several Wikidata items, the one whose description fits the credited job comes first (and is the default radio);
		// among equals the item holding the most (statements plus sitelinks) - the other is usually a thin duplicate of it.
		usort( $person['options'], fn( $a, $b ) => [ $b['fit'], $b['statements'] + $b['sitelinks'] ] <=> [ $a['fit'], $a['statements'] + $a['sitelinks'] ] );
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
		// Not in TMDb's credits: for a TV show try the people Wikidata itself ties to the show (as cast, director, writer, producer or creator of the series
		// or its episodes) - older programmes are thin on TMDb but often well curated on Wikidata, and a name that matches someone tied to THIS show is a
		// far safer match than a bare name search. One Wikidata query per show per request.
		if( !$person['options'] && $scope === 'tv' ) {
			if( $seriesIndex === null ) {
				// The run of this show (its own Wikidata item), so a person tied to it only in works from AFTER the run can be flagged, not pre-ticked.
				$seriesPeriod = ContactWikiIndividual::wikidataSeriesPeriod( FisheyeCredits::tmdbIdFor( $programId ), FisheyeCredits::imdbIdFor( $programId ) );
				$seriesIndex = ContactWikiIndividual::wikidataSeriesPeople(
					ContactWikiIndividual::wikidataSeriesItems( FisheyeCredits::tmdbIdFor( $programId ), FisheyeCredits::imdbIdFor( $programId ) ), $seriesPeriod['end'] !== null );
			}
			foreach( $seriesIndex[ContactWikiIndividual::normaliseName( $person['name'] )] ?? [] as $match ) {
				$existing = ContactWikiIndividual::findContactByWikidataQid( $match['qid'] );
				if( $existing ) {
					$existing['view_url'] = CONTACTWIKI_PKG_URL.'view.php?content_id='.$existing['content_id'];
				}
				$person['options'][] = [ 'tmdb_id' => 0, 'tmdb_name' => '', 'qid' => $match['qid'], 'label' => $match['label'], 'description' => $match['description'],
					'fit' => ContactWikiIndividual::descriptionFitsRoles( $match['description'], array_keys( $person['roles'] ) ),
					'is_human' => true, 'from_tmdb' => false, 'from_series' => true, 'details' => null, 'existing' => $existing, 'value' => '0:'.$match['qid'],
					// Tied to the show only in works from after its run: probably a Plex mix-up (metadata of a later series), so never pre-ticked.
					'era_warn' => ( !empty( $seriesPeriod['end'] ) && !empty( $match['year'] ) && $match['year'] > $seriesPeriod['end'] + 1 )
						? sprintf( KernelTools::tra( 'tied to this show on Wikidata only from %d - after its run ended in %d' ), $match['year'], $seriesPeriod['end'] ) : '' ];
			}
		}
		if( !$person['options'] ) {
			// Tagged in Plex but TMDb has no record: offer Wikidata items of that exact name (a person decides - none is pre-selected as a
			// pick) and a contact from the name alone. Searches are capped per request so a show with many such people stays quick.
			static $nameSearches = 0;
			$person['manual'] = [];
			$person['manualOther'] = [];
			if( $nameSearches++ < 12 ) {
				foreach( ContactWikiIndividual::searchWikidataByName( $person['name'], array_keys( $person['roles'] ), array_keys( $functionsByName[mb_strtolower( $person['name'] )] ?? [] ) ) as $candidate ) {
					// An item whose description names a screen/writing job is offered; the rest (no description, or a psychiatrist) are kept
					// behind a fold, so a common name does not bury the page in eight look-alikes and the name-only contact stays the default.
					$person[$candidate['fit'] || $candidate['likely'] ? 'manual' : 'manualOther'][] = $candidate + [ 'value' => '0:'.$candidate['qid'] ];
				}
			}
			$person['status'] = 'unresolved';
			$person['reason'] = !$tokenSet ? KernelTools::tra( 'No TMDb access token is set.' )
				: ( !$person['tmdb_films'] ? ( $scope === 'tv' ? KernelTools::tra( 'This show has no TMDb id.' ) : KernelTools::tra( 'None of its films carries a TMDb id.' ) )
				: ( $person['found']['error'] ? KernelTools::tra( 'TMDb lookup failed' ).': '.$person['found']['error']
				: ( $scope === 'tv' ? KernelTools::tra( 'Not in TMDb\'s credits for this show, nor in the show\'s Wikidata cast, under this name.' ) : KernelTools::tra( 'Not found in the TMDb credits of its films under this name.' ) ) ) );
		} elseif( count( $person['options'] ) > 1 || !empty( $person['options'][0]['era_warn'] ) ) {
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
$gBitSmarty->assign( 'programMarkedDone', $programId && isset( $markedDone[$programId] ) );
// Recurring roles still waiting for a character contact (what the Characters page's review list would offer) - the Characters button is only shown while there are some.
$linkableCharacters = $scope === 'tv' && $programId ? count( FisheyeCredits::recurringCharacters( $programId ) ) : 0;
$gBitSmarty->assign( 'linkableCharacters', $linkableCharacters );
// The show's own page (the generic dispatcher routes to whatever display page the program has) and "finished":
// the show has credits and every one is linked to a contact.
$gBitSmarty->assign( 'programUrl', $programId ? BIT_ROOT_URL.'index.php?content_id='.$programId : null );
$gBitSmarty->assign( 'finished', $scope === 'tv' && $counts['linked'] > 0 && !$counts['match'] && !$counts['choose'] && !$counts['unmatched'] );
$gBitSmarty->assign( 'reloadResult', $reloadResult );
$gBitSmarty->assign( 'tvdbResult', $tvdbResult );
$gBitSmarty->assign( 'tvdbConfigured', FisheyeTvdb::configured() );
$gBitSmarty->assign( 'hiddenFields', array_filter( [ 'scope' => $scope === 'tv' ? 'tv' : null, 'program_id' => $programId ?: null ] ) );
$gBitSmarty->assign( 'survey', [ 'films' => $survey['films'], 'credits' => $survey['credits'], 'people' => count( $survey['people'] ) ] );
$gBitSmarty->assign( 'counts', $counts );
$gBitSmarty->assign( 'reviewList', array_slice( $reviewList, 0, LOAD_WIKI_FILM_PEOPLE_BATCH ) );
$gBitSmarty->assign( 'reviewTotal', count( $reviewList ) );
$gBitSmarty->assign( 'unmatchedShown', array_slice( $unmatchedAll, $start, 30 ) );
// Whoever the lookup offset has stepped past (set aside or skipped) must stay visible - but only them: on a new show there is nobody, and no list.
$gBitSmarty->assign( 'unmatchedSteppedPast', array_slice( $unmatchedAll, 0, min( $start, 200 ) ) );
$gBitSmarty->assign( 'start', $start );
$gBitSmarty->assign( 'lookupBatch', LOAD_WIKI_FILM_PEOPLE_LOOKUP_BATCH );
$gBitSmarty->assign( 'lookup', $lookup );
$gBitSmarty->assign( 'result', $result );
$gBitSmarty->assign( 'createResult', $createResult );

$pageTitle = $scope === 'tv' ? KernelTools::tra( 'Load Wiki TV People' ).': '.$program['title'] : KernelTools::tra( 'Load Wiki Film People' );
$gBitSystem->display( 'bitpackage:contactwiki/load_wiki_film_people.tpl', $pageTitle, [ 'display_mode' => 'edit' ] );
