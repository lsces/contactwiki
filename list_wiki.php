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

global $gBitSystem, $gBitSmarty, $gBitDb;

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

// Dates and the Wikidata id for this page's rows in one read, not a query per row - all four date
// items and the qid keep their value in xkey_ext, which LibertyContent::lookupXrefValues() (xkey
// only) doesn't cover.
$extra = [];
$contentIds = array_column( $listWiki, 'content_id' );
if( $contentIds ) {
	$placeholders = implode( ',', array_fill( 0, count( $contentIds ), '?' ) );
	$rows = $gBitDb->getAll(
		"SELECT `content_id`, `item`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref`
		 WHERE `item` IN ( 'dob', 'dod', 'formed', 'disbanded', 'wikidata' ) AND `end_date` IS NULL AND `content_id` IN ( $placeholders )",
		$contentIds
	);
	foreach( $rows as $row ) {
		$extra[$row['content_id']][$row['item']] = $row['xkey_ext'];
	}
}
foreach( $listWiki as &$row ) {
	$x = $extra[$row['content_id']] ?? [];
	$row['is_group'] = $row['content_type_guid'] === CONTACTWIKIGROUP_CONTENT_TYPE_GUID;
	$row['date_from'] = $row['is_group'] ? ( $x['formed'] ?? null ) : ( $x['dob'] ?? null );
	$row['date_to'] = $row['is_group'] ? ( $x['disbanded'] ?? null ) : ( $x['dod'] ?? null );
	$row['wikidata_qid'] = $x['wikidata'] ?? null;
	$row['view_url'] = CONTACTWIKI_PKG_URL.'view.php?content_id='.$row['content_id'];
}
unset( $row );

$gBitSmarty->assign( 'listWiki', $listWiki );
$gBitSmarty->assign( 'listInfo', $listHash['listInfo'] );

$gBitSystem->setBrowserTitle( KernelTools::tra( 'Wiki Contacts' ) );
$gBitSystem->display( 'bitpackage:contactwiki/list_wiki.tpl', null, [ 'display_mode' => 'list' ] );
