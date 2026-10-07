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
		'heading' => 'TMDb - the film and TV people tools',
		'label'   => 'TMDb API Read Access Token',
		'note'    => 'Unrelated to the contact address above: a secret token from themoviedb.org/settings/api (the long v4 "API Read Access Token" starting eyJ..., not the shorter v3 "API Key"). The film and TV people tools (Load Wiki Film People / Load Wiki TV People) use it to find who a credited name is on TMDb and to create a contact for someone Wikidata does not have; without it they cannot look anybody up. Biographies still come from Wikipedia (or TMDb for a person with no Wikipedia article).',
		'type'    => 'text',
	],
	"contactwiki_photo_width" => [
		'heading' => 'Contact photos from Wikimedia Commons',
		'label'   => 'Photo width (pixels)',
		'note'    => 'Commons sends a copy resized to this width instead of the original upload (press photos run to many megabytes). Blank means 400 (the size the media library stores its stills at); a smaller image is never enlarged; the word "original" fetches the file exactly as uploaded. Applies to photos fetched from now on - an existing contact keeps its photo until it is reloaded from Wikidata.',
		'type'    => 'text',
	],
];
$gBitSmarty->assign( 'formContactWikiGeneral', $formContactWikiGeneral );

if( !empty( $_REQUEST['contactWikiGeneralSubmit'] ) ) {
	foreach( $formContactWikiGeneral as $item => $data ) {
		$gBitSystem->storeConfig( $item, trim( (string)( $_REQUEST[$item] ?? '' ) ), CONTACTWIKI_PKG_NAME );
	}
}
