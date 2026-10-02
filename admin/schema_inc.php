<?php

// No tables of its own - ContactWikiIndividual/ContactWikiGroup reuse liberty_content/liberty_xref
// via their parent ContactPerson/ContactBusiness (contact package), same pattern fisheyemedia uses
// for fisheye_gallery/fisheye_image. No liberty_xref_group/liberty_xref_item defaults registered
// here either - WPxx/WBxx role tags, external-identity links, biography dates and the
// 'music_gallery' link are all applied privately per-site instead via LibertyXrefScheme::apply()
// (liberty), same as before the split - see contactwiki/DEVELOPER.md.

global $gBitInstaller;

$gBitInstaller->registerPackageInfo( CONTACTWIKI_PKG_NAME, [
	'description'  => 'Wikidata/MusicBrainz-backed Wiki Individual/Group contacts, extending contact with ContactWikiIndividual/ContactWikiGroup content types.',
	'license'      => '<a href="http://www.gnu.org/licenses/licenses.html#LGPL">LGPL</a>',
	'dependencies' => 'contact',
] );

$gBitInstaller->registerRequirements( CONTACTWIKI_PKG_NAME, [
	'liberty' => [ 'min' => '5.0.0' ],
	'contact' => [ 'min' => '5.0.0' ],
] );

$gBitInstaller->registerContentObjects( CONTACTWIKI_PKG_NAME, [
	'ContactWikiIndividual' => CONTACTWIKI_PKG_CLASS_PATH.'ContactWikiIndividual.php',
	'ContactWikiGroup'      => CONTACTWIKI_PKG_CLASS_PATH.'ContactWikiGroup.php',
] );

// ### Default User Permissions
// p_contact_view/p_contact_update (contact's own) still gate the actual add/view/edit flows -
// these pages manipulate Contact-family content same as any other contact, no need for a
// duplicate permission concept. p_contactwiki_admin is only for this package's own admin settings
// page (kernel/admin/index.php's generic ?package= dispatcher requires 'p_'.$package.'_admin').
$gBitInstaller->registerUserPermissions( CONTACTWIKI_PKG_NAME, [
	[ 'p_contactwiki_admin', 'Can admin Contact Wiki settings', 'admin', CONTACTWIKI_PKG_NAME ],
] );
