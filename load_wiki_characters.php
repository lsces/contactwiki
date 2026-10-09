<?php
/**
 * The characters pass for films (and, with ?program_id=, one show's seasons): each film's cast rows carry the role text from Plex (the film's `character` rows, one per cast member);
 * this links each to a wiki contact for the character itself. A character is a wiki individual of type Character (WP09) keyed by its
 * Wikidata item - found from the film's own Wikidata cast statements (P161 with a character role, P453), matched to the cast row through
 * the actor's contact link. So the people pass comes first: a cast row not yet linked to its actor is skipped, and picked up on a later run.
 *
 * A season is matched through its show's Wikidata series item(s) instead of a film item (several for a franchise such as Doctor Who).
 * Films are done in chunks within a time budget, with a Continue link. Photos and biographies are left for the contact's first edit-view.
 * An actor who plays several characters in one film is matched by the role text against each character's name, else left alone.
 *
 * With ?program_id= the page also lists the show's recurring roles that Wikidata cannot supply (most programmes): a review list, ticked by
 * default for a role one or two actors play, and a character contact is made from the role text for each ticked one.
 *
 * @package contactwiki
 * @subpackage functions
 */

namespace Bitweaver\Contactwiki;

use Bitweaver\Fisheyemedia\FisheyeCredits;
use Bitweaver\Fisheyemedia\FisheyeFilm;
use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPackage( 'fisheyemedia' );
$gBitSystem->verifyPermission( 'p_contact_update' );

const LOAD_WIKI_CHARACTERS_CHUNK = 20;
const LOAD_WIKI_CHARACTERS_BUDGET = 30;

$programId = (int)( $_REQUEST['program_id'] ?? 0 );
$seasonIds = $programId ? FisheyeCredits::seasonIdsForProgram( $programId ) : [];
// What this run covers: one show's seasons, or every film (the two have separate rows under the one item).
$scopeSql = $programId
	? ( $seasonIds ? "c.`content_id` IN ( ".implode( ',', array_map( 'intval', $seasonIds ) )." )" : "1 = 0" )
	: "c.`content_id` IN ( SELECT `content_id` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_type_guid` = 'fisheyefilm' )";
$unlinkedSql = "FROM `".BIT_DB_PREFIX."liberty_xref` c WHERE c.`item` = '".FisheyeCredits::CHARACTER_ITEM."' AND c.`end_date` IS NULL AND ( c.`xref` IS NULL OR c.`xref` = 0 ) AND $scopeSql";
$result = null;

if( !empty( $_REQUEST['fLoad'] ) ) {
	$started = microtime( true );
	$result = [ 'films' => 0, 'linked' => 0, 'created' => [], 'ambiguous' => 0, 'throttled' => false, 'next' => null, 'seconds' => 0.0 ];
	ContactWikiIndividual::$skipPhotos = true;
	ContactWikiIndividual::$skipBiography = true;
	$cursor = max( 0, (int)( $_REQUEST['after'] ?? 0 ) );
	while( microtime( true ) - $started < LOAD_WIKI_CHARACTERS_BUDGET ) {
		$filmIds = array_map( 'intval', $gBitDb->getCol( "SELECT FIRST ".LOAD_WIKI_CHARACTERS_CHUNK." DISTINCT c.`content_id` $unlinkedSql AND c.`content_id` > ? ORDER BY c.`content_id`", [ $cursor ] ) ?: [] );
		if( !$filmIds ) {
			$cursor = 0;
			break;
		}
		$rowsByFilm = FisheyeCredits::characterRowsForFilms( $filmIds );
		// Only films with a cast row already linked to its actor can be matched.
		$wanted = array_filter( $rowsByFilm, fn( $rows ) => (bool)array_filter( $rows, fn( $r ) => !$r['linked'] && $r['actor_qid'] ) );
		// The Wikidata item(s) each film or season is matched through: a film's own item, or its show's series items.
		$itemsFor = [];
		if( $programId ) {
			$itemQids = $wanted ? ContactWikiIndividual::wikidataSeriesItems( FisheyeCredits::tmdbIdFor( $programId ), FisheyeCredits::imdbIdFor( $programId ) ) : [];
			foreach( array_keys( $wanted ) as $filmId ) {
				$itemsFor[$filmId] = $itemQids;
			}
			$filmQ = $itemQids;
		} else {
			$tmdb = FisheyeFilm::tmdbIdsByFilm( array_keys( $wanted ) );
			$filmQ = $tmdb ? ContactWikiIndividual::wikidataFilmItems( array_values( $tmdb ) ) : [];
			foreach( $tmdb as $filmId => $tmdbId ) {
				if( isset( $filmQ[$tmdbId] ) ) {
					$itemsFor[$filmId] = [ $filmQ[$tmdbId] ];
				}
			}
		}
		$chars = $filmQ ? ContactWikiIndividual::wikidataFilmCharacters( array_values( $filmQ ) ) : [];
		if( $filmQ === null || $chars === null ) {
			$result['throttled'] = true;
			break;
		}
		$needed = [];
		foreach( $chars as $byActor ) {
			foreach( $byActor as $candidates ) {
				foreach( $candidates as $candidate ) {
					$needed[$candidate['qid']] = true;
				}
			}
		}
		$toCreate = array_values( array_filter( array_keys( $needed ), fn( $q ) => !ContactWikiIndividual::findContactByWikidataQid( $q ) ) );
		if( $toCreate ) {
			ContactWikiIndividual::prefetchWikidata( $toCreate );
		}
		$contactFor = [];
		foreach( $wanted as $filmId => $rows ) {
			foreach( $rows as $row ) {
				if( $row['linked'] || !$row['actor_qid'] || empty( $itemsFor[$filmId] ) ) {
					continue;
				}
				$candidates = [];
				foreach( $itemsFor[$filmId] as $itemQid ) {
					foreach( $chars[$itemQid][$row['actor_qid']] ?? [] as $candidate ) {
						$candidates[$candidate['qid']] = $candidate;
					}
				}
				$candidates = array_values( $candidates );
				if( count( $candidates ) > 1 ) {
					$byRole = array_filter( $candidates, fn( $c ) => $c['label'] !== '' && ContactWikiIndividual::normaliseName( $c['label'] ) === ContactWikiIndividual::normaliseName( $row['role'] ) );
					$candidates = count( $byRole ) === 1 ? array_values( $byRole ) : [];
					if( !$candidates ) {
						$result['ambiguous']++;
					}
				}
				if( count( $candidates ) !== 1 ) {
					continue;
				}
				$characterQid = $candidates[0]['qid'];
				if( !isset( $contactFor[$characterQid] ) ) {
					$found = ContactWikiIndividual::findContactByWikidataQid( $characterQid );
					if( $found ) {
						$contactFor[$characterQid] = (int)$found['content_id'];
					} else {
						$created = ContactWikiIndividual::createFromWikidata( $characterQid, false, [ ContactWikiIndividual::CHARACTER_TYPE ] );
						if( empty( $created['content'] ) ) {
							continue;
						}
						$contactFor[$characterQid] = (int)$created['content']->mContentId;
						if( count( $result['created'] ) < 40 ) {
							$result['created'][] = [ 'content_id' => $contactFor[$characterQid], 'title' => $created['content']->getTitle() ];
						}
					}
				}
				$result['linked'] += FisheyeCredits::linkRows( [ $row['xref_id'] ], $contactFor[$characterQid], $characterQid, [ FisheyeCredits::CHARACTER_ITEM ] );
			}
		}
		$result['films'] += count( $filmIds );
		$cursor = max( $filmIds );
	}
	$result['next'] = $cursor > 0 && microtime( true ) - $started >= LOAD_WIKI_CHARACTERS_BUDGET ? $cursor : null;
	$result['seconds'] = round( microtime( true ) - $started, 1 );
}

// ---- One show's recurring roles with no Wikidata character: a review list, then a character contact per ticked role (made from the role text).
$roleGroups = [];
$roleResult = null;
$requestedMin = (int)( $_REQUEST['min'] ?? 0 );
$minSeasons = in_array( $requestedMin, [ 1, 2, 3, 4, 5, 8 ], true ) ? $requestedMin : ( $programId ? FisheyeCredits::defaultMinSeasons( $programId ) : 3 );
$showTitle = '';
if( $programId ) {
	$showTitle = (string)$gBitDb->getOne( "SELECT `title` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ $programId ] );
	$roleGroups = FisheyeCredits::recurringCharacters( $programId, $minSeasons );
	if( !empty( $_REQUEST['fCreateRoles'] ) ) {
		$gBitSystem->verifyPermission( 'p_contact_update' );
		$started = microtime( true );
		$picked = array_flip( (array)( $_REQUEST['role'] ?? [] ) );
		$roleResult = [ 'created' => [], 'joined' => 0, 'linked' => 0, 'errors' => [], 'left' => 0 ];
		foreach( $roleGroups as $group ) {
			if( !isset( $picked[$group['key']] ) ) {
				continue;
			}
			if( microtime( true ) - $started > 25 ) {
				$roleResult['left']++;
				continue;
			}
			if( $group['existing'] ) {
				$contactId = $group['existing'];
				$roleResult['joined']++;
			} else {
				$actors = array_slice( $group['actors'], 0, 3 );
				$description = $group['role'].' in '.$showTitle.( $actors ? ', played by '.implode( ', ', $actors ) : '' ).' ('.$group['seasons'].' '.( $group['seasons'] == 1 ? 'season' : 'seasons' ).').';
				$created = ContactWikiIndividual::createCharacterContact( $group['role'], $description );
				if( empty( $created['content'] ) ) {
					$roleResult['errors'][] = $group['role'].': '.( $created['error'] ?? '' );
					continue;
				}
				$contactId = (int)$created['content']->mContentId;
				$roleResult['created'][] = [ 'content_id' => $contactId, 'title' => $group['role'] ];
			}
			$roleResult['linked'] += FisheyeCredits::linkRows( $group['xref_ids'], $contactId, '', [ FisheyeCredits::CHARACTER_ITEM ] );
		}
		$roleResult['seconds'] = round( microtime( true ) - $started, 1 );
		$roleGroups = FisheyeCredits::recurringCharacters( $programId, $minSeasons );
	}
}
$gBitSmarty->assign( 'roleGroups', $roleGroups );
$gBitSmarty->assign( 'roleResult', $roleResult );
$gBitSmarty->assign( 'minSeasons', $minSeasons );
$gBitSmarty->assign( 'showTitle', $showTitle );
$gBitSmarty->assign( 'result', $result );
$gBitSmarty->assign( 'programId', $programId );
$gBitSmarty->assign( 'unlinked', (int)$gBitDb->getOne( "SELECT COUNT(*) $unlinkedSql" ) );
$gBitSmarty->assign( 'linkedRows', (int)$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` c WHERE c.`item` = '".FisheyeCredits::CHARACTER_ITEM."' AND c.`end_date` IS NULL AND c.`xref` > 0" ) );
$gBitSystem->display( 'bitpackage:contactwiki/load_wiki_characters.tpl', KernelTools::tra( 'Load Wiki Characters' ), [ 'display_mode' => 'edit' ] );
