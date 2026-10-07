<?php
/**
 * Fill in the photos wiki contacts are missing. The people pass can leave photos out (Wikimedia refuses bulk photo
 * downloads for a while after a few big batches), so contacts created then have none; their stored Wikidata entity
 * still names the image, so this fetches just those photos, a few at a time, within a time budget with a Continue.
 * ContactWikiIndividual::loadMissingPhotos() does the work and is what a cron/CLI wrapper would call.
 *
 * @package contactwiki
 * @subpackage functions
 */

namespace Bitweaver\Contactwiki;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPermission( 'p_contact_update' );

$result = !empty( $_REQUEST['fLoad'] ) ? ContactWikiIndividual::loadMissingPhotos( max( 0, (int)( $_REQUEST['after'] ?? 0 ) ), 30 ) : null;
if( !$result ) {
	global $gBitDb;
	$gBitSmarty->assign( 'missing', (int)$gBitDb->getOne(
		"SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_content` c
		 JOIN `".BIT_DB_PREFIX."liberty_xref` w ON w.`content_id` = c.`content_id` AND w.`item` = 'wikidata' AND w.`end_date` IS NULL
		 WHERE c.`content_type_guid` IN ( 'contactwikiindi', 'contactwikigroup' ) AND w.`data` CONTAINING '\"P18\"'
		 AND NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` i WHERE i.`content_id` = c.`content_id` AND i.`item` = 'image' AND i.`end_date` IS NULL )"
	) );
}
$gBitSmarty->assign( 'result', $result );
$gBitSystem->display( 'bitpackage:contactwiki/load_wiki_photos.tpl', KernelTools::tra( 'Load Wiki Photos' ), [ 'display_mode' => 'edit' ] );
