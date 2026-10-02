<?php
/**
 * The people pass for one Music artist/composer gallery - run before its albums are imported, so an
 * album import can link every credit to a contact rather than to a name. Reads the gallery's own
 * folder on disk (FisheyeAlbum::surveyArtistCredits(): every distinct MusicBrainz artist id across
 * its albums' album-artist and track-artist tags) and resolves each person:
 *
 *   - a wiki contact already holding that MusicBrainz id          -> linked, nothing to do
 *   - otherwise Wikidata (one SPARQL query for the whole gallery, P434 = MusicBrainz artist id):
 *       - a contact already holding the resulting qid             -> linked (it just lacks the id)
 *       - one Wikidata item                                       -> create, pre-ticked
 *       - more than one                                           -> choose which, then create
 *       - none                                                    -> create from MusicBrainz, pre-ticked
 *
 * Wikidata first - the tags already carry MusicBrainz ids, and Wikidata maps those straight to a qid;
 * the qid (with the contact_id) is what an album's credit rows end up holding. MusicBrainz itself is
 * only called to create someone Wikidata has no item for (ContactWikiTrait::createFromMusicBrainz()),
 * one request a second, at submit time - never during the survey.
 * Nothing is created until the list is reviewed and submitted. A created contact whose name matches
 * the gallery's own title ("Samuel Barber" or "Barber, Samuel") also gets its 'music_gallery' link.
 *
 * @package contactwiki
 * @subpackage functions
 */

namespace Bitweaver\Contactwiki;

use Bitweaver\Fisheyemedia\FisheyeAlbum;
use Bitweaver\Fisheye\FisheyeGallery;
use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';
// mime_film_get_storage_root() - only auto-loaded via the LibertyMime attachment-plugin dispatch,
// same explicit include load_album.php needs.
require_once dirname( __DIR__ ).'/liberty/plugins/mime.film.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPackage( 'fisheyemedia' );
$gBitSystem->verifyPermission( 'p_contact_update' );

// "Barber, Samuel" and "Samuel Barber" both count as the same name, for the music_gallery link.
function load_wiki_people_name_forms( string $pName ): array {
	$name = mb_strtolower( trim( $pName ) );
	$forms = [ $name ];
	if( preg_match( '/^([^,]+),\s*(.+)$/', $name, $m ) ) {
		$forms[] = $m[2].' '.$m[1];
	}
	return $forms;
}

$galleryId = (int)( $_REQUEST['gallery_id'] ?? 0 );

if( !$galleryId ) {
	// No gallery picked yet - list every artist/composer gallery under the top-level Music pool.
	$galleries = [];
	$topGalleryId = FisheyeGallery::getTopGalleryId( 'Music' );
	if( $topGalleryId ) {
		$topGallery = new FisheyeGallery( $topGalleryId );
		$topGallery->load();
		$listHash = [ 'max_records' => 1000 ];
		$topGallery->loadImages( $listHash );
		foreach( $topGallery->mItems as $item ) {
			if( $item instanceof FisheyeGallery ) {
				$galleries[] = [ 'gallery_id' => $item->mGalleryId, 'title' => $item->getTitle() ];
			}
		}
		usort( $galleries, fn( $a, $b ) => strnatcasecmp( $a['title'], $b['title'] ) );
	}
	$gBitSmarty->assign( 'galleries', $galleries );
	$gBitSystem->display( 'bitpackage:contactwiki/load_wiki_people.tpl', KernelTools::tra( 'Load Wiki People' ), [ 'display_mode' => 'edit' ] );
	exit;
}

$gallery = new FisheyeGallery( $galleryId );
$gallery->load();
// Same validity check load_album.php uses - isValid() alone doesn't prove load() found a row.
if( !$gallery->isValid() || empty( $gallery->getTitle() ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No gallery exists with the given ID.' ) );
}
$galleryTitle = $gallery->getTitle();

// Folder resolution mirrors load_album.php's own: directly under Music/, or one level down under
// the parent gallery (a box set's nested gallery).
$root = \Bitweaver\Liberty\mime_film_get_storage_root();
$artistDir = null;
if( !empty( $root ) ) {
	$musicDir = $root.'Music/';
	if( is_dir( $musicDir.$galleryTitle.'/' ) ) {
		$artistDir = $musicDir.$galleryTitle.'/';
	} else {
		$parentGalleries = $gallery->getParentGalleries();
		$parentTitle = $parentGalleries ? current( $parentGalleries )['title'] : null;
		if( $parentTitle && is_dir( $musicDir.$parentTitle.'/'.$galleryTitle.'/' ) ) {
			$artistDir = $musicDir.$parentTitle.'/'.$galleryTitle.'/';
		}
	}
}
if( !$artistDir ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No folder found under Music/ for this gallery.' ).' ('.$galleryTitle.')' );
}
$galleryNameForms = load_wiki_people_name_forms( $galleryTitle );

// Contacts created per submit - each one is several network round trips (Wikidata entity, Wikipedia
// summary, Commons image; MusicBrainz at ~1/s for anyone not on Wikidata), so a big gallery (a
// compilation crediting 150+ artists) is done in small batches: the page lists only the next batch
// still to create, and Create is pressed again until none remain. The pause between batches (page
// reload + click) plus a short gap between people keeps Wikimedia/MusicBrainz from throttling.
const LOAD_WIKI_PEOPLE_GAP_US = 500000;
const LOAD_WIKI_PEOPLE_BATCH = 10;

$result = null;
if( !empty( $_REQUEST['fCreate'] ) ) {
	$result = [ 'created' => [], 'errors' => [], 'remaining' => 0 ];
	$attempted = 0;
	$qids = (array)( $_REQUEST['qid'] ?? [] );
	$mbCalls = 0;
	foreach( (array)( $_REQUEST['selected'] ?? [] ) as $mbid ) {
		$mbid = strtolower( trim( (string)$mbid ) );
		if( !ContactWikiIndividual::extractMusicBrainzArtistId( $mbid ) || ContactWikiIndividual::findContactByMusicBrainzId( $mbid ) ) {
			continue;
		}
		if( $attempted >= LOAD_WIKI_PEOPLE_BATCH ) {
			$result['remaining']++;
			continue;
		}
		if( $attempted++ ) {
			usleep( LOAD_WIKI_PEOPLE_GAP_US );
		}
		$qid = ContactWikiIndividual::extractQid( (string)( $qids[$mbid] ?? '' ) );
		if( $qid ) {
			if( ContactWikiIndividual::findContactByWikidataQid( $qid ) ) {
				continue;
			}
			$created = ContactWikiIndividual::createFromWikidata( $qid );
		} else {
			// No Wikidata item - MusicBrainz's own record. Its API etiquette is ~1 request/second.
			if( $mbCalls++ ) {
				usleep( 1100000 );
			}
			$created = ContactWikiIndividual::createFromMusicBrainz( $mbid );
		}
		if( empty( $created['content'] ) ) {
			$result['errors'][] = [ 'mbid' => $mbid, 'error' => $created['error'] ];
			continue;
		}
		$gContent = $created['content'];
		if( array_intersect( load_wiki_people_name_forms( $gContent->getTitle() ), $galleryNameForms ) ) {
			$gContent->upsertXref( $gContent->mContentId, 'music_gallery', [ 'xref' => $gallery->mContentId ] );
		}
		$result['created'][] = [ 'title' => $gContent->getTitle(), 'view_url' => $gContent->getDisplayUrl() ];
	}
}

$survey = FisheyeAlbum::surveyArtistCredits( $artistDir );

// Resolve: contacts first (no network), Wikidata only for whoever's left.
$people = [];
$unresolved = [];
foreach( $survey['people'] as $person ) {
	$person['contact'] = ContactWikiIndividual::findContactByMusicBrainzId( $person['mbid'] );
	$person['status'] = $person['contact'] ? 'linked' : 'pending';
	$person['wikidata'] = [];
	if( !$person['contact'] ) {
		$unresolved[] = $person['mbid'];
	}
	$people[$person['mbid']] = $person;
}
$wikidataError = false;
if( $unresolved ) {
	$wikidata = ContactWikiIndividual::lookupWikidataByMusicBrainzIds( $unresolved );
	if( $wikidata === null ) {
		$wikidataError = true;
	}
	foreach( $unresolved as $mbid ) {
		$matches = $wikidata[$mbid] ?? [];
		$people[$mbid]['wikidata'] = $matches;
		if( count( $matches ) === 1 && ( $contact = ContactWikiIndividual::findContactByWikidataQid( $matches[0]['qid'] ) ) ) {
			$people[$mbid]['contact'] = $contact;
			$people[$mbid]['status'] = 'linked_by_qid';
		} else {
			$people[$mbid]['status'] = $wikidataError ? 'lookup_failed' : ( count( $matches ) === 1 ? 'create' : ( $matches ? 'choose' : 'create_mb' ) );
		}
	}
}
foreach( $people as &$person ) {
	if( !empty( $person['contact'] ) ) {
		$person['contact']['view_url'] = CONTACTWIKI_PKG_URL.'view.php?content_id='.$person['contact']['content_id'];
	}
}
unset( $person );

// Only the next batch still to do is listed (same as load_music/load_album's own candidate lists);
// everyone else is summarised in counts.
$counts = [ 'linked' => 0, 'todo' => 0, 'unresolved' => 0 ];
$nextBatch = [];
foreach( $people as $person ) {
	if( in_array( $person['status'], [ 'linked', 'linked_by_qid' ], true ) ) {
		$counts['linked']++;
	} elseif( in_array( $person['status'], [ 'create', 'create_mb', 'choose' ], true ) ) {
		$counts['todo']++;
		if( count( $nextBatch ) < LOAD_WIKI_PEOPLE_BATCH ) {
			$nextBatch[] = $person;
		}
	} else {
		$counts['unresolved']++;
	}
}
// Next step of the one-folder-at-a-time music workflow (load_music.php -> here -> albums). A
// Create that leaves nothing to do - no failures, nobody unresolved, Wikidata answering - goes
// straight on to the album import; anything else stays here to be seen, with a Continue button.
$albumsUrl = FISHEYEMEDIA_PKG_URL.'load_album.php?gallery_id='.$galleryId;
if( $result && !$result['errors'] && !$result['remaining'] && !$counts['todo'] && !$counts['unresolved'] && !$wikidataError ) {
	KernelTools::bit_redirect( $albumsUrl );
}
$gBitSmarty->assign( 'albumsUrl', $albumsUrl );
$gBitSmarty->assign( 'counts', $counts );
$gBitSmarty->assign( 'batchSize', LOAD_WIKI_PEOPLE_BATCH );

$gBitSmarty->assign( 'galleryId', $galleryId );
$gBitSmarty->assign( 'galleryTitle', $galleryTitle );
$gBitSmarty->assign( 'survey', [ 'albums' => $survey['albums'], 'tracks' => $survey['tracks'], 'unreadable' => $survey['unreadable'] ] );
$gBitSmarty->assign( 'people', $nextBatch );
$gBitSmarty->assign( 'totalPeople', count( $people ) );
$gBitSmarty->assign( 'wikidataError', $wikidataError );
$gBitSmarty->assign( 'wikidataErrorReason', $wikidataError ? ContactWikiIndividual::getLastFetchError() : null );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:contactwiki/load_wiki_people.tpl', KernelTools::tra( 'Load Wiki People' ).': '.$galleryTitle, [ 'display_mode' => 'edit' ] );
