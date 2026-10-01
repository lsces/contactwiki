<?php
/**
 * Wiki contacts only - individuals and groups - without contact's own person/business records
 * mixed in (list_contacts.php shows every contact-family type together). Same Contact::getList()
 * query underneath, restricted to this package's two content types, so title search, sorting and
 * pagination behave exactly like contact's own list pages.
 *
 * @package contactwiki
 * @subpackage functions
 */

namespace Bitweaver\Contactwiki;

use Bitweaver\Contact\Contact;
use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPermission( 'p_contact_view' );

$listContent = new Contact();
$listHash = $_REQUEST;
$wikiGuids = [ CONTACTWIKIINDIVIDUAL_CONTENT_TYPE_GUID, CONTACTWIKIGROUP_CONTENT_TYPE_GUID ];
if( empty( $listHash['sort_mode'] ) ) {
	$listHash['sort_mode'] = 'title_asc';
}
// Same two-stage class/type filter list_contacts.php uses (Contact::applyListFilter()), offered
// over just the two wiki classes - Composer/Conductor/Orchestra/... within them.
$listFilter = Contact::applyListFilter( $listHash, $_REQUEST, $wikiGuids );
$listWiki = $listContent->getList( $listHash );
if( $listFilter['classes'] ) {
	$listHash['listInfo']['ihash']['content_class'] = implode( ',', $listFilter['classes'] );
}
if( $listFilter['items'] ) {
	$listHash['listInfo']['ihash']['xref_items'] = implode( ',', $listFilter['items'] );
}
$gBitSmarty->assign( 'filterOptions', Contact::getListFilterOptions( $wikiGuids, $listFilter ) );

// Contact::getList() already ran the registered 'contact_list_row_function' (this package's own
// ContactWikiTrait::enrichListRows()) - each row carries 'wiki_summary': type names, dates,
// Wikidata qid, MusicBrainz id, music gallery link.

$gBitSmarty->assign( 'listWiki', $listWiki );
$gBitSmarty->assign( 'listInfo', $listHash['listInfo'] );

$gBitSystem->setBrowserTitle( KernelTools::tra( 'Wiki Contacts' ) );
$gBitSystem->display( 'bitpackage:contactwiki/list_wiki.tpl', null, [ 'display_mode' => 'list' ] );
