<?php
/**
 * Shared display page for ContactWikiIndividual/ContactWikiGroup - reached via either type's own
 * getDisplayUrl() override, never linked to directly by content_type-specific code elsewhere.
 * Reuses base contact's own lookup_contact_inc.php for the polymorphic getLibertyObject() load
 * (same mechanism contact/view.php itself uses) rather than duplicating it.
 *
 * @package contactwiki
 * @subpackage functions
 */

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPermission( 'p_contact_view' );

include_once CONTACT_PKG_INCLUDE_PATH . 'lookup_contact_inc.php';

if( !$gContent->isValid() ) {
	header( "location: " . CONTACT_PKG_URL . "list_contacts.php" );
	die;
}

if( $gContent->isCommentable() ) {
	$commentsParentId = $gContent->mContentId;
	$comments_vars = [ 'contact' ];
	$comments_prefix_var = 'contact:';
	$comments_object_var = 'contact';
	$comments_return_url = $_SERVER['PHP_SELF'] . "?content_id=" . $gContent->mContentId;
	include_once LIBERTY_PKG_INCLUDE_PATH . 'comments_inc.php';

	if( isset( $_REQUEST['post_comment_submit'] ) and !$_REQUEST['post_comment_submit'] == 'Post' ) {
		header( "location: " . $gContent->getDisplayUrl() );
		die;
	}
}

// A contact created in bulk has no biography or photo yet (those are the slow, throttled Wikimedia requests): the first time someone
// who can edit it opens the page, load what its stored Wikidata entity has. Visitors never trigger a fetch.
global $gBitUser;
if( ( $gBitUser->isAdmin() || $gBitUser->hasPermission( 'p_contact_update' ) ) && method_exists( $gContent, 'fillMissingFromWikidata' ) ) {
	$filled = $gContent->fillMissingFromWikidata();
	if( $filled['bio'] || $filled['photo'] ) {
		$gContent->load();
	}
}

$gBitSmarty->assign( 'gXrefInfo', $gContent->mXrefInfo );
$gContent->assignViewVars( $gBitSmarty );

$gBitSystem->setBrowserTitle( $gContent->getTitle() );
$gBitSystem->display( 'bitpackage:contactwiki/view_wiki_profile.tpl' );
