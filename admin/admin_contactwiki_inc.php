<?php
/**
 * @package  contactwiki
 * @subpackage functions
 */

// Same shape as fisheye's own admin_fisheye_inc.php ($formGalleryGeneral) - real per-site values
// (a secret token, an operator's own contact info), so they only ever live in kernel_config
// (getConfig()/storeConfig()), never a committed file.
$formContactWikiGeneral = [
	"contactwiki_tmdb_token" => [
		'label' => 'TMDb API Read Access Token',
		'note'  => 'From themoviedb.org/settings/api - the v4 "API Read Access Token" (a long token starting eyJ...), not the shorter v3 "API Key". Used to fetch a person\'s biography when adding a Wiki Individual from a Wikidata entity. Leave blank to skip the biography fetch.',
		'type'  => 'text',
	],
	"contactwiki_api_contact" => [
		'label' => 'API Contact Info (User-Agent)',
		'note'  => 'Wikidata/Wikipedia/MusicBrainz etiquette asks every client to identify itself with real contact info in its User-Agent string - used by every fetch this package makes to those APIs. Leave blank to send requests with no contact info (may be rate-limited or blocked).',
		'type'  => 'text',
	],
];
$gBitSmarty->assign( 'formContactWikiGeneral', $formContactWikiGeneral );

if( !empty( $_REQUEST['contactWikiGeneralSubmit'] ) ) {
	foreach( $formContactWikiGeneral as $item => $data ) {
		$gBitSystem->storeConfig( $item, trim( (string)( $_REQUEST[$item] ?? '' ) ), CONTACTWIKI_PKG_NAME );
	}
}
