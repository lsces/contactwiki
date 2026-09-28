<?php
/**
 * Dedicated edit page for ContactWikiIndividual - reached via ContactWikiIndividual::getEditUrl().
 * Base contact's own edit.php only ever handles Person/Business; a wiki individual is edited here
 * instead, reusing the same shared 'bitpackage:contact/edit.tpl' xref-tab editor (dob/dod/external
 * ids are all plain xrefs, so the generic tab UI already handles them - nothing wiki-specific about
 * that part) plus this content type's own "Reload from Wikidata" action, which contact/edit.php has
 * no knowledge of at all.
 *
 * @package contactwiki
 * @subpackage functions
 */

use Bitweaver\Contactwiki\ContactWikiIndividual;
use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPermission( 'p_contact_update' );

if( !empty( $_REQUEST['content_id'] ) ) {
	$gContent = \Bitweaver\Liberty\LibertyContent::getLibertyObject( (int)$_REQUEST['content_id'] );
	if( !( $gContent instanceof ContactWikiIndividual ) ) {
		$gBitSystem->fatalError( KernelTools::tra( 'No Wiki Individual exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
	}
} else {
	$gContent = new ContactWikiIndividual();
}

if( !empty( $gContent->mInfo ) ) {
	$formInfo = $gContent->mInfo;
	$formInfo['edit'] = !empty( $gContent->mInfo['data'] ) ? $gContent->mInfo['data'] : '';
}

$wikiReloadResult = null;
$wikiReloadLabel = null;

if( !empty( $_REQUEST['expunge'] ) && $gContent->isValid() ) {
	$gBitSystem->verifyPermission( 'p_contact_expunge' );
	$gContent->expunge();
	KernelTools::bit_redirect( CONTACT_PKG_URL . 'list_contacts.php' );
} elseif( isset( $_REQUEST['fCancel'] ) ) {
	if( !empty( $gContent->mContentId ) ) {
		KernelTools::bit_redirect( $gContent->getDisplayUrl() );
	} else {
		KernelTools::bit_redirect( CONTACT_PKG_URL );
	}
} elseif( isset( $_REQUEST['fSaveContact'] ) ) {
	if( $gContent->store( $_REQUEST ) ) {
		KernelTools::bit_redirect( $gContent->getDisplayUrl() );
	} else {
		$formInfo = $_REQUEST;
		$formInfo['data'] = &$_REQUEST['edit'];
	}
} elseif( !empty( $_REQUEST['fReloadWikidata'] ) ) {
	// Fisheye-style Reload button (edit_album.php's own fReloadImages/fReloadTracks) - re-runs the
	// same fetch-then-apply cascade the add-flow's own Save step used to create this contact.
	$wikiReloadResult = $gContent->reloadFromWikidata();
	$wikiReloadLabel = empty( $wikiReloadResult['error'] ) ? KernelTools::tra( 'Reloaded from Wikidata' ) : KernelTools::tra( 'Reload from Wikidata' );
}

if( empty( $formInfo ) ) {
	$formInfo = &$gContent->mInfo;
}

$gContent->loadXrefInfo();
$gBitSmarty->assign( 'gXrefInfo', $gContent->mXrefInfo );
$gBitSmarty->assign( 'isPerson', true );
$gBitSmarty->assign( 'wikiReloadResult', $wikiReloadResult );
$gBitSmarty->assign( 'wikiReloadLabel', $wikiReloadLabel );

$setItems = [];
foreach( $gContent->mInfo['contact_types'] ?? [] as $ct ) {
	if( !empty( $ct['content_id'] ) ) {
		$setItems[$ct['item']] = true;
	}
}
$typeToggle = [];
foreach( $gContent->getAvailableTypeItems() as $m ) {
	$typeToggle[] = [
		'item'    => $m['item'],
		'name'    => $m['name'],
		'checked' => isset( $setItems[$m['item']] ),
	];
}
$gContent->mInfo['contact_type_list'] = $typeToggle;
$gBitSmarty->assign( 'pageInfo', $formInfo );

$gBitSmarty->assign( 'errors', $gContent->mErrors );
$gBitSmarty->assign( ( !empty( $_REQUEST['tab'] ) ? $_REQUEST['tab'] : 'body' ) . 'TabSelect', 'tdefault' );
$gBitSmarty->assign( 'show_page_bar', 'y' );

$gBitSystem->display( 'bitpackage:contact/edit.tpl', 'Edit: ', [ 'display_mode' => 'edit' ] );
