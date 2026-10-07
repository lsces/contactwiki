<?php
/**
 * @package  contactwiki
 * @subpackage functions
 */
global $gBitSystem, $gBitSmarty;
$pRegisterHash = [
	'package_name' => 'contactwiki',
	'package_path' => dirname( dirname( __FILE__ ) ) . '/',
	'homeable'     => false,
];
define( 'CONTACTWIKI_PKG_NAME', $pRegisterHash['package_name'] );
define( 'CONTACTWIKI_PKG_URL', BIT_ROOT_URL . basename( $pRegisterHash['package_path'] ) . '/' );
define( 'CONTACTWIKI_PKG_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/' );
define( 'CONTACTWIKI_PKG_INCLUDE_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/includes/');
define( 'CONTACTWIKI_PKG_CLASS_PATH',   BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/includes/classes/');
define( 'CONTACTWIKI_PKG_ADMIN_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/admin/');

// liberty_content_types.content_type_guid is VARCHAR(16) - 'contactwikiindividual' (21 chars)
// overflowed it, same reason these were abbreviated when they still lived in contact's own
// Contact.php. Moved here since they're wiki-specific, not base-contact knowledge - never shown
// anywhere, only ever read back in code.
defined( 'CONTACTWIKIINDIVIDUAL_CONTENT_TYPE_GUID' ) || define( 'CONTACTWIKIINDIVIDUAL_CONTENT_TYPE_GUID', 'contactwikiindi' );
defined( 'CONTACTWIKIGROUP_CONTENT_TYPE_GUID' )      || define( 'CONTACTWIKIGROUP_CONTENT_TYPE_GUID',      'contactwikigroup' );

$gBitSystem->registerPackage( $pRegisterHash );

// contactwiki extends contact's own ContactPerson/ContactBusiness - nothing here can run before
// contact's own bit_setup_inc.php has registered it. registerPackage() has no built-in "requires"
// concept (see fisheyemedia's own equivalent guard), so this is a manual check.
if( !$gBitSystem->isPackageActive( 'contact' ) ) {
	return;
}

if( $gBitSystem->isPackageActive( 'contactwiki' ) ) {
	global $gLibertySystem;

	// Content-type registration for ContactWikiIndividual/ContactWikiGroup stays inside each
	// class's own constructor (registerContentType() there is a no-op in memory once the DB row
	// exists), NOT unconditionally here - this lazy-on-instantiation pattern is what makes it safe:
	// a site can have contactwiki active with zero wiki contacts and get zero dangling
	// liberty_content_types rows, unlike an unconditional registerContentType() call would.

	// Contributes this package's two content-type guids into Contact's own combined-listing
	// lookup via the generic registerService()/getServiceValues() extension point - same pattern
	// fisheyemedia uses for fisheye_gallery_layout. Base contact has no hardcoded knowledge of
	// contactwiki's guids or package name anywhere in its own code; list_contacts.php's combined
	// query (Contact::getAllContentTypeGuids()) picks these up generically.
	$gLibertySystem->registerService( 'contact_content_type', CONTACTWIKI_PKG_NAME, [
		'content_type_guid' => [ CONTACTWIKIINDIVIDUAL_CONTENT_TYPE_GUID, CONTACTWIKIGROUP_CONTENT_TYPE_GUID ],
	] );

	// Contributes the "Reload from Wikidata" button/result fragment into contact/edit.tpl's own
	// generic content_edit_mini_tpl slot (liberty/edit_services_inc.tpl - already how e.g. the tags
	// package injects its own edit-page fragment). Self-gated inside the fragment itself, not here
	// and not in contact/edit.tpl - registerService() has no per-content-type filtering
	// (LibertyContent::hasService() is unconditionally true), so a plain Person/Business's own edit
	// page would try to include this too; the fragment just renders nothing for those. A distinct
	// service name (not 'contact_content_type' above) - registerService() keys its top-level
	// mServices array by this name, so reusing one collides and silently drops whichever
	// registration happened first.
	//
	// Same service also contributes this package's section of contact's own menu - contact's
	// menu_contact.tpl includes every 'contact_menu_tpl' generically, so the wiki tools sit inside
	// the one Contact dropdown (only where contactwiki is active) rather than a dropdown of their own.
	$gLibertySystem->registerService( CONTACTWIKI_PKG_NAME, CONTACTWIKI_PKG_NAME, [
		'content_edit_mini_tpl' => 'bitpackage:contactwiki/edit_wiki_reload_inc.tpl',
		'contact_menu_tpl'      => 'bitpackage:contactwiki/contact_menu_inc.tpl',
		// Fills wiki rows of contact's list pages with their summary (types, dates, Wikidata,
		// gallery) - see ContactWikiTrait::enrichListRows() and list_summary_inc.tpl.
		'contact_list_row_function' => 'Bitweaver\\Contactwiki\\ContactWikiIndividual::enrichListRows',
		// Which of this package's own type codes say what job a person does on a recording - read
		// generically (getServiceValues('credit_role_map')) by fisheyemedia when it sorts an album's
		// credits into artist/composer/conductor/orchestra/performer, so it never needs to know
		// contactwiki's WPxx/WBxx codes itself.
		'credit_role_map'       => [
			'WP03' => 'composer',
			'WP08' => 'conductor',
			'WB02' => 'orchestra',
			'WP06' => 'performer',
			'WB03' => 'performer', // choir
			'WB04' => 'performer', // ensemble
		],
		// Per-artist actions offered by fisheyemedia's music pages (read generically via
		// getServiceValues('music_artist_tools')) - 'url' gets the artist gallery's gallery_id
		// appended, so the people pass opens straight on that artist's folder.
		// Tools offered on a TV show's own page (fisheyemedia's view_program.php reads this generically):
		// 'url' gets the program's content_id appended; 'credit_status' asks the page to show how far the
		// show's credits are linked (a count of those still unlinked, or a tick when none are).
		'program_tools'         => [
			[
				'title'         => 'Load Wiki TV People',
				'url'           => CONTACTWIKI_PKG_URL.'load_wiki_film_people.php?scope=tv&program_id=',
				'icon'          => 'system-users',
				'perm'          => 'p_contact_update',
				'credit_status' => true,
			],
		],
		'music_artist_tools'    => [
			[
				'title' => 'Load its contacts',
				'url'   => CONTACTWIKI_PKG_URL.'load_wiki_people.php?gallery_id=',
				'icon'  => 'system-users',
				'perm'  => 'p_contact_update',
			],
		],
	] );
}
