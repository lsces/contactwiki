<?php
/**
 * The people pass for Film credits - the film-side counterpart of load_wiki_people.php. Film cast and
 * crew are plain text on the film's director/writer/star xref rows; this page matches each distinct
 * credited name to a wiki contact and links the rows (xref = contact content_id, xkey = its Wikidata
 * Q-id), the same shape an album credit takes.
 *
 *   - every credit already linked to a contact                       -> done, nothing to do
 *   - a name matching exactly one wiki contact                       -> link, pre-ticked
 *   - a name matching more than one                                  -> choose which, then link
 *   - no contact                                                     -> listed, most-credited first
 *
 * Name matching only (accents/case/punctuation folded, "Surname, Forename" flipped) - no network.
 * Nothing is written until the reviewed list is submitted. Resolving the unmatched through Wikidata
 * and TMDb and creating their contacts is the next stage of this page.
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

// People linked per submit. No network involved, so this is only a page-size limit.
const LOAD_WIKI_FILM_PEOPLE_BATCH = 100;
// Unmatched people shown (they can't be actioned yet, this is only to see what's left).
const LOAD_WIKI_FILM_PEOPLE_UNMATCHED_SHOWN = 30;

$nameIndex = ContactWikiIndividual::nameIndex();

/** The contacts a credited name could be, from the name index. */
$candidatesFor = function( string $pName ) use ( $nameIndex ): array {
	return array_values( $nameIndex[ContactWikiIndividual::normaliseName( $pName )] ?? [] );
};

$result = null;
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

// Survey after any write, so the page always shows what is left.
$survey = FisheyeFilm::surveyCredits();
$counts = [ 'linked' => 0, 'match' => 0, 'choose' => 0, 'unmatched' => 0 ];
$reviewList = [];
$unmatched = [];
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
		if( count( $unmatched ) < LOAD_WIKI_FILM_PEOPLE_UNMATCHED_SHOWN ) {
			$unmatched[] = $person;
		}
	}
}

$gBitSmarty->assign( 'survey', [ 'films' => $survey['films'], 'credits' => $survey['credits'], 'people' => count( $survey['people'] ) ] );
$gBitSmarty->assign( 'counts', $counts );
$gBitSmarty->assign( 'reviewList', array_slice( $reviewList, 0, LOAD_WIKI_FILM_PEOPLE_BATCH ) );
$gBitSmarty->assign( 'reviewTotal', count( $reviewList ) );
$gBitSmarty->assign( 'batchSize', LOAD_WIKI_FILM_PEOPLE_BATCH );
$gBitSmarty->assign( 'unmatched', $unmatched );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:contactwiki/load_wiki_film_people.tpl', KernelTools::tra( 'Load Wiki Film People' ), [ 'display_mode' => 'edit' ] );
