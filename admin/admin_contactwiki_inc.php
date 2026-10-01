<?php
/**
 * @package  contactwiki
 * @subpackage functions
 */

// Same shape as fisheye's own admin_fisheye_inc.php ($formGalleryGeneral) - real per-site values
// (a secret token, an operator's own contact info), so they only ever live in kernel_config
// (getConfig()/storeConfig()), never a committed file.
$formContactWikiGeneral = [
	"contactwiki_api_contact" => [
		'heading' => 'Identify this site to Wikidata / Wikipedia / MusicBrainz',
		'label'   => 'Contact address (User-Agent)',
		'note'    => 'An email address or URL these services can reach you at - added to the User-Agent of every request this package makes to Wikidata, Wikipedia, Wikimedia Commons and MusicBrainz, as their etiquette asks. Not a key or password. Leave blank and requests go out anonymous, which those services throttle or block sooner.',
		'type'    => 'text',
	],
	"contactwiki_tmdb_token" => [
		'heading' => 'TMDb - optional, not currently used',
		'label'   => 'TMDb API Read Access Token',
		'note'    => 'Unrelated to the contact address above: a secret token from themoviedb.org/settings/api (the long v4 "API Read Access Token" starting eyJ..., not the shorter v3 "API Key"). Nothing uses it at present - biographies come from Wikipedia - it is kept for possible future film/TV person biographies. Can be left blank.',
		'type'    => 'text',
	],
];
$gBitSmarty->assign( 'formContactWikiGeneral', $formContactWikiGeneral );

if( !empty( $_REQUEST['contactWikiGeneralSubmit'] ) ) {
	foreach( $formContactWikiGeneral as $item => $data ) {
		$gBitSystem->storeConfig( $item, trim( (string)( $_REQUEST[$item] ?? '' ) ), CONTACTWIKI_PKG_NAME );
	}
}
