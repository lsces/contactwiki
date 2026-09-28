<?php
/**
 * Dedicated edit page for ContactWikiGroup - reached via ContactWikiGroup::getEditUrl(). See
 * edit_wiki_indi.php's own docblock for the full reasoning; identical shape, just this content
 * type's own class and isPerson=false (organisation-name storage, not forename/surname).
 *
 * @package contactwiki
 * @subpackage functions
 */

use Bitweaver\Contactwiki\ContactWikiGroup;
use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPermission( 'p_contact_update' );

if( !empty( $_REQUEST['content_id'] ) ) {
	$gContent = \Bitweaver\Liberty\LibertyContent::getLibertyObject( (int)$_REQUEST['content_id'] );
	if( !( $gContent instanceof ContactWikiGroup ) ) {
		$gBitSystem->fatalError( KernelTools::tra( 'No Wiki Group exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
	}
} else {
	$gContent = new ContactWikiGroup();
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
	$wikiReloadResult = $gContent->reloadFromWikidata();
	$wikiReloadLabel = empty( $wikiReloadResult['error'] ) ? KernelTools::tra( 'Reloaded from Wikidata' ) : KernelTools::tra( 'Reload from Wikidata' );
}

if( empty( $formInfo ) ) {
	$formInfo = &$gContent->mInfo;
}

$gContent->loadXrefInfo();
$gBitSmarty->assign( 'gXrefInfo', $gContent->mXrefInfo );
$gBitSmarty->assign( 'isPerson', false );
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
