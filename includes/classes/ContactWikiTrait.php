<?php
/**
 * Shared Wikidata fetch/apply machinery for ContactWikiIndividual and ContactWikiGroup - identical
 * API-client and claim-extraction logic either way, the only real difference between a person and
 * a group is which biography date properties apply (dob/dod vs formed/disbanded, see
 * biographyDateProps()) and how role-tag suggestions are derived (P106 occupation vs P31 instance-
 * of, left to each class's own picker logic rather than folded in here).
 *
 * A trait, not a shared base class, because the two concrete classes already have divergent real
 * parents (ContactPerson vs ContactBusiness, both in the base contact package) - PHP has no
 * multiple inheritance, and a trait's own methods still run against $this exactly as if they were
 * declared directly on the using class, so $this->mContentTypeGuid/$this->mContentId are already
 * whatever the concrete constructor set them to. EXTERNAL_ID_PROPS stays a real class constant on
 * each user instead of living here - traits can't declare constants at all.
 *
 * @package contactwiki
 */
namespace Bitweaver\Contactwiki;

use Bitweaver\Fisheye\FisheyeGallery;
use Bitweaver\KernelTools;

trait ContactWikiTrait {

	/**
	 * Populates everything contactwiki's own view.php/view_wiki_profile.tpl need beyond the basics
	 * lookup_contact_inc.php's generic load already provides - role pills, profile thumbnail, and
	 * the linked discography gallery. Called directly by contactwiki/view.php, which is reached via
	 * this content type's own getDisplayUrl() override - base contact's view.php/show_contact.tpl
	 * has no involvement with a wiki contact at all.
	 */
	public function assignViewVars( $pBitSmarty ): void {
		// Role-flag pills (WPxx/WBxx currently ticked) - getAvailableTypeItems() is the full
		// code=>name lookup, getSetTypeItems() (Contact.php) is which of those are actually set
		// here; type markers are excluded from mXrefInfo by design (see LibertyXrefType's own
		// docblock), so this needs both rather than reading mXrefInfo directly.
		$typeNames = [];
		foreach( $this->getAvailableTypeItems() as $t ) {
			$typeNames[$t['item']] = $t['name'];
		}
		$roleFlags = [];
		foreach( $this->getSetTypeItems() as $item ) {
			if( isset( $typeNames[$item] ) ) {
				$roleFlags[] = $typeNames[$item];
			}
		}
		$pBitSmarty->assign( 'roleFlags', $roleFlags );

		// Large profile thumbnail - the first downloaded Wikidata image, if any (item is
		// multiple=1, but a wiki contact only ever has the one auto-downloaded image today). The
		// 'biography' and 'external' groups (dob/dod/pob/pod, external-id links) are deliberately
		// NOT flattened into bespoke PHP variables here - view_wiki_profile.tpl reads
		// $gXrefInfo->mGroups directly and renders whatever items are actually registered/set, the
		// same group/item-driven approach the generic xref tabs already use - so a new item added
		// to the schema later (POB, formed/disbanded, another external source, ...) just shows up
		// with no template change needed. view_extra_image.php itself stays in base contact - it's
		// a plain generic image-serving script, not wiki-specific.
		if( $imageXref = $this->mXrefInfo->findRowByItem( 'image' ) ) {
			$pBitSmarty->assign( 'wikiThumbnailUrl', CONTACT_PKG_URL.'view_extra_image.php?xref_id='.$imageXref['xref_id'] );
		}

		// "Other content" - the linked FisheyeGallery (this contact's own discography), if any.
		// Manually linked via the 'music_gallery' xref item for now (add_xref.php's own 'gallery'
		// template) - see contact.php's own site-local scheme comment for why this isn't automatic
		// yet.
		if( !empty( $this->mInfo['music_gallery'] ) ) {
			$gMusicGallery = new FisheyeGallery( $this->mInfo['music_gallery'] );
			$gMusicGallery->load();
			// loadImages() takes its param by reference - a literal array can't bind to that.
			$musicGalleryListHash = [ 'max_records' => 24 ];
			$gMusicGallery->loadImages( $musicGalleryListHash );
			$pBitSmarty->assign( 'gMusicGallery', $gMusicGallery );
		}
	}

	/**
	 * The Wikidata qid this contact is already linked to (its own stored 'wikidata' xref's
	 * xkey_ext), or null for one that's never been fetched/saved - used by reloadFromWikidata()
	 * when no qid is explicitly passed (edit_wiki_indi.php/edit_wiki_group.php's own Reload button
	 * case; the add-flow's own initial Save always passes one explicitly instead, since the xref
	 * doesn't exist yet).
	 */
	public function getWikidataQid(): ?string {
		$row = \Bitweaver\Liberty\LibertyContent::lookupXrefByItem( $this->mContentId, 'wikidata', $this->mContentTypeGuid );
		return $row['xkey_ext'] ?? null;
	}

	/**
	 * item => Wikidata property for this content type's own biography date fields - dob/dod (P569/
	 * P570) for an individual, formed/disbanded (P571/P576) for a group. Overridden per class;
	 * reloadFromWikidata() below is entirely generic over whatever this returns, so a third wiki
	 * content type later just needs its own override, not a change here.
	 *
	 * @return array<string,string>
	 */
	abstract protected function biographyDateProps(): array;

	/**
	 * (Re-)fetches this contact's Wikidata entity and applies every derived xref on top of it - the
	 * raw entity json itself, each configured external-id link, this content type's own biography
	 * dates (see biographyDateProps()), a freshly downloaded P18 image, and (when the entity has an
	 * enwiki sitelink) a re-fetched Wikipedia summary as the biography text. Shared by the add-flow's
	 * own initial Save (passing the qid just picked in the fetch step) and edit_wiki_indi.php/
	 * edit_wiki_group.php's own fisheye-style 'Reload' action on an already-created contact (passing
	 * nothing, so getWikidataQid() supplies the already-stored one) - same fetch-then-apply cascade
	 * either way, just a different qid source.
	 *
	 * Returns a result array in the same shape FisheyeAlbum::reloadTracks()/reloadPlexImages() use
	 * ('items' => human-readable lines of what was applied, or 'error') so edit_album.tpl's own
	 * display convention can be reused as-is.
	 *
	 * @param string|null $pQid
	 * @return array
	 */
	public function reloadFromWikidata( ?string $pQid = null ): array {
		$qid = $pQid ?: $this->getWikidataQid();
		if( !$qid && ( $mbid = $this->getMusicBrainzId() ) ) {
			// A contact created from MusicBrainz alone: Wikidata may have gained an item for it since -
			// upgrade to the full Wikidata reload if so, otherwise refresh from MusicBrainz again.
			$matches = self::lookupWikidataByMusicBrainzIds( [ $mbid ] )[$mbid] ?? [];
			if( count( $matches ) === 1 ) {
				$qid = $matches[0]['qid'];
			} else {
				return $this->reloadFromMusicBrainz();
			}
		}
		if( !$qid ) {
			return [ 'error' => KernelTools::tra( 'No Wikidata id known for this contact - fetch one first.' ) ];
		}
		$entity = self::fetchWikidataEntity( $qid );
		if( !$entity ) {
			return [ 'error' => KernelTools::tra( 'Could not fetch that Wikidata entity.' ) ];
		}

		$items = [];

		// upsertXref(), not storeXref() directly - storeXref() always inserts a fresh row unless
		// the caller already knows the xref_id to update, which is exactly the "duplicates every
		// item on a second Reload" bug found live: this method runs against an ALREADY-created
		// contact just as often as a brand new one, so every item here needs the "update the
		// existing row if there is one" lookup upsertXref() does, not a blind insert.
		//
		// 'edit', not 'data' - LibertyXref::verify() only ever populates xref_store['data'] from a
		// param key literally named 'edit' (see liberty/MANUAL.md's own "'edit', not 'data'"
		// section) - a plain 'data' key here is silently ignored.
		$this->upsertXref( $this->mContentId, 'wikidata', [ 'xkey_ext' => $qid, 'edit' => json_encode( $entity ) ] );
		$items[] = KernelTools::tra( 'Wikidata entity data' ).' ('.$qid.')';

		$mappedTypes = [];
		if( $this instanceof ContactWikiGroup ) {
			foreach( ContactWikiGroup::instanceOfQids( $entity ) as $classQid ) {
				$mappedTypes[] = ContactWikiGroup::GROUP_TYPE_MAP[$classQid] ?? null;
			}
		} else {
			foreach( ContactWikiIndividual::occupationQids( $entity ) as $occupationQid ) {
				$mappedTypes[] = ContactWikiIndividual::OCCUPATION_MAP[$occupationQid] ?? null;
			}
		}
		foreach( $this->addTypeTags( $mappedTypes ) as $added ) {
			$items[] = KernelTools::tra( 'Type tag added' ).': '.$added;
		}

		foreach( static::EXTERNAL_ID_PROPS as $item => $property ) {
			$value = self::stringClaim( $entity, $property );
			if( $value !== null ) {
				$this->upsertXref( $this->mContentId, $item, [ 'xkey_ext' => $value ] );
				$items[] = $item.': '.$value;
			}
		}

		// Biography re-fetch - a Reload should refresh everything Wikidata/Wikipedia can supply,
		// same as the rest of this method. Always overwrites the existing note, same "source wins"
		// behaviour every other item here already has (upsertXref() replaces the stored value
		// unconditionally) - a hand-edited note added since the last Reload would be lost, not
		// merged. LibertyContent::store() directly, not $this->store() (Contact's own override) -
		// this only ever needs to touch the free-text data field, not re-run the address/
		// contact_types/NAME logic Contact::store() layers on top for a full page save. Works
		// identically for both content types - a Wikipedia sitelink exists for a group's own entity
		// just as much as a person's (confirmed live against Fleetwood Mac), unlike TMDb's own
		// biography field, which is person-only and no longer used as a bio source here at all (see
		// fetchTmdbBiography()'s own docblock for why it's kept, just not called from here).
		$wikiTitle = self::wikipediaTitle( $entity );
		if( $wikiTitle !== null ) {
			$bio = self::fetchWikipediaSummary( $wikiTitle );
			if( $bio !== null ) {
				$bioHash = [ 'content_id' => $this->mContentId, 'edit' => self::plainTextToHtmlParagraphs( $bio ) ];
				\Bitweaver\Liberty\LibertyContent::store( $bioHash );
				$items[] = KernelTools::tra( 'Biography' ).' ('.KernelTools::tra( 'Wikipedia' ).')';
			}
		}

		foreach( $this->biographyDateProps() as $item => $property ) {
			$value = self::dateClaim( $entity, $property );
			if( $value !== null ) {
				$this->upsertXref( $this->mContentId, $item, [ 'xkey_ext' => $value ] );
				$items[] = $item.': '.$value;
			}
		}

		$imageFilename = self::imageFilename( $entity );
		if( $imageFilename ) {
			$imagesDir = $this->getExtraImagePath( '' );
			$ext = strtolower( pathinfo( $imageFilename, PATHINFO_EXTENSION ) ) ?: 'jpg';
			$storedName = 'wikidata.'.$ext;
			KernelTools::mkdir_p( $imagesDir );
			if( self::downloadCommonsFile( $imageFilename, $imagesDir.$storedName ) ) {
				$this->upsertXref( $this->mContentId, 'image', [ 'xkey_ext' => $storedName ] );
				$items[] = KernelTools::tra( 'Image' ).': '.$imageFilename;
			}
		}

		return [ 'items' => $items ];
	}

	/**
	 * Wikidata items for a batch of MusicBrainz artist ids, via one SPARQL query (P434 = MusicBrainz
	 * artist id) rather than a lookup per id - each result gives the qid, its English label, and
	 * whether it's a human (P31 = Q5; anything else - band, orchestra, choir, duo - is a group).
	 * An id Wikidata doesn't know is simply absent from the result; one claimed by more than one
	 * item comes back with every candidate, for a person to choose between rather than a guess.
	 * Null if the query itself fails (network, endpoint down), distinct from an empty result.
	 *
	 * @param list<string> $pMbids
	 * @return array<string, list<array{qid:string, label:string, is_human:bool}>>|null
	 */
	public static function lookupWikidataByMusicBrainzIds( array $pMbids ): ?array {
		$ret = [];
		foreach( array_chunk( array_values( array_unique( $pMbids ) ), 150 ) as $chunk ) {
			$values = implode( ' ', array_map( fn( $id ) => '"'.addslashes( $id ).'"', $chunk ) );
			$query = 'SELECT ?mbid ?item ?itemLabel ?human WHERE { VALUES ?mbid { '.$values.' } ?item wdt:P434 ?mbid . '
				.'BIND( EXISTS { ?item wdt:P31 wd:Q5 } AS ?human ) SERVICE wikibase:label { bd:serviceParam wikibase:language "en". } }';
			$context = stream_context_create( [ 'http' => [
				'method'  => 'POST',
				'header'  => self::userAgentHeader()."Accept: application/sparql-results+json\r\nContent-Type: application/x-www-form-urlencoded\r\n",
				'content' => http_build_query( [ 'query' => $query ] ),
				'timeout' => 30,
			] ] );
			$json = @file_get_contents( 'https://query.wikidata.org/sparql', false, $context );
			if( $json === false ) {
				return null;
			}
			foreach( json_decode( $json, true )['results']['bindings'] ?? [] as $row ) {
				if( preg_match( '#/(Q\d+)$#', $row['item']['value'] ?? '', $m ) ) {
					$ret[strtolower( $row['mbid']['value'] )][] = [
						'qid'      => $m[1],
						'label'    => $row['itemLabel']['value'] ?? $m[1],
						'is_human' => ( $row['human']['value'] ?? '' ) === 'true',
					];
				}
			}
		}
		return $ret;
	}

	/**
	 * The wiki contact (individual or group) already holding a given external-id xref value - the
	 * reverse of every LibertyContent::lookupXref*() helper (content_id -> its xrefs), here value ->
	 * content_id, so a small direct read, same as load_wiki_artists.php's own music_gallery reverse
	 * lookup. Live rows only.
	 *
	 * @return array{content_id:int, title:string, content_type_guid:string}|null
	 */
	private static function findWikiContactByXref( string $pItem, string $pValue ): ?array {
		global $gBitDb;
		$row = $gBitDb->getRow(
			"SELECT lc.content_id, lc.title, lc.content_type_guid FROM `".BIT_DB_PREFIX."liberty_xref` x
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.content_id = x.content_id
			 WHERE x.item = ? AND x.xkey_ext = ? AND x.end_date IS NULL
			 AND lc.content_type_guid IN ( 'contactwikiindi', 'contactwikigroup' )",
			[ $pItem, $pValue ]
		);
		return $row ? [ 'content_id' => (int)$row['content_id'], 'title' => $row['title'], 'content_type_guid' => $row['content_type_guid'] ] : null;
	}

	public static function findContactByMusicBrainzId( string $pMbid ): ?array {
		return self::findWikiContactByXref( 'musicbrainz', strtolower( $pMbid ) );
	}

	public static function findContactByWikidataQid( string $pQid ): ?array {
		return self::findWikiContactByXref( 'wikidata', strtoupper( $pQid ) );
	}

	/**
	 * Create a wiki contact from a Wikidata item and apply everything reloadFromWikidata() derives
	 * (external ids - including 'musicbrainz', so the new contact is matchable by MusicBrainz id
	 * straight away - biography, dates, image). Individual or group: $pIsGroup when the caller
	 * already knows (load_wiki_artists.php has MusicBrainz's own artist type to hand), otherwise
	 * from the entity itself - a human (P31 = Q5) is an individual, anything else a group. Role
	 * tags come from P106 occupations (individual) or P31 instance-of (group), through each class's
	 * own curated map.
	 *
	 * @param string $pQid
	 * @param bool|null $pIsGroup
	 * @return array{content:object}|array{error:string}
	 */
	public static function createFromWikidata( string $pQid, ?bool $pIsGroup = null ): array {
		$entity = self::fetchWikidataEntity( $pQid );
		if( !$entity ) {
			return [ 'error' => KernelTools::tra( 'Could not fetch that Wikidata entity.' ).' ('.$pQid.')' ];
		}
		$label = trim( $entity['labels']['en']['value'] ?? '' );
		$isGroup = $pIsGroup ?? !in_array( 'Q5', self::itemClaimQids( $entity, 'P31' ), true );

		$contactTypes = [];
		if( $isGroup ) {
			$gContent = new ContactWikiGroup();
			foreach( ContactWikiGroup::instanceOfQids( $entity ) as $qid ) {
				if( isset( ContactWikiGroup::GROUP_TYPE_MAP[$qid] ) ) {
					$contactTypes[] = ContactWikiGroup::GROUP_TYPE_MAP[$qid];
				}
			}
			$storeHash = [ 'organisation' => $label, 'fContactTypesSubmitted' => 1, 'contact_types' => $contactTypes ];
		} else {
			$gContent = new ContactWikiIndividual();
			$parts = explode( ' ', $label );
			$surname = array_pop( $parts ) ?: '';
			foreach( ContactWikiIndividual::occupationQids( $entity ) as $qid ) {
				if( isset( ContactWikiIndividual::OCCUPATION_MAP[$qid] ) ) {
					$contactTypes[] = ContactWikiIndividual::OCCUPATION_MAP[$qid];
				}
			}
			$storeHash = [ 'forename' => implode( ' ', $parts ), 'surname' => $surname, 'fContactTypesSubmitted' => 1, 'contact_types' => $contactTypes ];
		}
		// Two Wikidata classes can map to the same code (orchestra + symphony orchestra -> WB02) -
		// store each code once.
		$storeHash['contact_types'] = array_values( array_unique( $storeHash['contact_types'] ) );
		if( !$gContent->store( $storeHash ) ) {
			return [ 'error' => implode( '; ', $gContent->mErrors ) ];
		}
		$gContent->reloadFromWikidata( $pQid );
		return [ 'content' => $gContent ];
	}

	/**
	 * Add any of these type-tag codes this contact doesn't carry yet - additive only, so a reload
	 * picks up codes a growing occupation/class map now gives (a conductor gaining WP08 once that
	 * mapping exists) without removing a tag set or kept by hand. Same xref shape Contact::store()
	 * writes for a ticked type checkbox.
	 *
	 * @param list<?string> $pCodes  nulls/duplicates ignored
	 * @return list<string>  the codes actually added
	 */
	protected function addTypeTags( array $pCodes ): array {
		$have = $this->getSetTypeItems();
		$added = [];
		foreach( array_unique( array_filter( $pCodes ) ) as $code ) {
			if( in_array( $code, $have, true ) ) {
				continue;
			}
			$xrefHash = [ 'content_id' => $this->mContentId, 'item' => $code, 'fAddXref' => 1 ];
			if( $this->storeXref( $xrefHash ) ) {
				$added[] = $code;
			}
		}
		return $added;
	}

	/**
	 * This contact's own MusicBrainz artist id (its 'musicbrainz' xref), or null.
	 */
	public function getMusicBrainzId(): ?string {
		$row = \Bitweaver\Liberty\LibertyContent::lookupXrefByItem( $this->mContentId, 'musicbrainz', $this->mContentTypeGuid );
		return !empty( $row['xkey_ext'] ) ? strtolower( $row['xkey_ext'] ) : null;
	}

	/**
	 * A stored biography date (full YYYY-MM-DD, or year/month-only YYYY / YYYY-MM as MusicBrainz and
	 * Wikidata both give for less well documented people) as the epoch liberty_content.event_time
	 * holds for calendar ordering - a partial date sorts at the start of its year/month. Null when
	 * there's no usable date. (strtotime() on a bare "1955" reads it as a time of day.)
	 */
	public static function dateToEventTime( string $pDate ): ?int {
		if( !preg_match( '/^(-?\d{4})(?:-(\d{2}))?(?:-(\d{2}))?/', trim( $pDate ), $m ) ) {
			return null;
		}
		$month = max( 1, (int)( $m[2] ?? 1 ) );
		$day = max( 1, (int)( $m[3] ?? 1 ) );
		$time = strtotime( sprintf( '%s-%02d-%02d', $m[1], $month, $day ) );
		return $time === false ? null : $time;
	}

	/**
	 * Create a wiki contact from MusicBrainz alone - the fallback for a credited artist with no
	 * Wikidata item (a session player, a smaller orchestra). Every id the people pass meets comes
	 * from MusicBrainz-tagged files, so MusicBrainz always has the artist even when Wikidata doesn't.
	 * Individual vs group from MusicBrainz's own type; names from its sort name ("Buswell, James"),
	 * which avoids guessing forename/surname from a display name. The 'musicbrainz' xref is the
	 * contact's identity until a Wikidata item turns up - reloadFromWikidata() checks for one first.
	 *
	 * @param string $pMbid
	 * @return array{content:object}|array{error:string}
	 */
	public static function createFromMusicBrainz( string $pMbid ): array {
		$mb = self::lookupMusicBrainzArtist( $pMbid );
		if( !$mb ) {
			return [ 'error' => KernelTools::tra( 'Could not fetch that MusicBrainz artist.' ).' ('.$pMbid.')' ];
		}
		$isGroup = !in_array( $mb['type'], [ 'Person', null ], true ) || ( $mb['type'] === null && empty( $mb['gender'] ) && !str_contains( (string)$mb['sort_name'], ',' ) );
		if( $isGroup ) {
			$gContent = new ContactWikiGroup();
			$contactTypes = isset( ContactWikiGroup::MUSICBRAINZ_TYPE_MAP[$mb['type']] ) ? [ ContactWikiGroup::MUSICBRAINZ_TYPE_MAP[$mb['type']] ] : [];
			$storeHash = [ 'organisation' => $mb['name'], 'fContactTypesSubmitted' => 1, 'contact_types' => $contactTypes ];
		} else {
			$gContent = new ContactWikiIndividual();
			if( preg_match( '/^([^,]+),\s*(.+)$/', (string)$mb['sort_name'], $nameParts ) ) {
				[ , $surname, $forename ] = $nameParts;
			} else {
				$parts = explode( ' ', (string)$mb['name'] );
				$surname = array_pop( $parts ) ?: '';
				$forename = implode( ' ', $parts );
			}
			$storeHash = [ 'forename' => $forename, 'surname' => $surname, 'fContactTypesSubmitted' => 1, 'contact_types' => [] ];
		}
		if( !$gContent->store( $storeHash ) ) {
			return [ 'error' => implode( '; ', $gContent->mErrors ) ];
		}
		$gContent->applyMusicBrainzData( $mb );
		return [ 'content' => $gContent ];
	}

	/**
	 * Re-fetch this contact's MusicBrainz artist and re-apply what it gives - the Reload path for a
	 * contact with no Wikidata item (reloadFromWikidata() lands here when Wikidata still has none).
	 */
	public function reloadFromMusicBrainz(): array {
		$mbid = $this->getMusicBrainzId();
		if( !$mbid ) {
			return [ 'error' => KernelTools::tra( 'No Wikidata or MusicBrainz id known for this contact.' ) ];
		}
		$mb = self::lookupMusicBrainzArtist( $mbid );
		if( !$mb ) {
			return [ 'error' => KernelTools::tra( 'Could not fetch that MusicBrainz artist.' ).' ('.$mbid.')' ];
		}
		return [ 'items' => $this->applyMusicBrainzData( $mb ) ];
	}

	/**
	 * The xrefs a MusicBrainz artist record supplies: its own id, Discogs/IMDb ids from its url-rels,
	 * and its life span as this content type's biography dates (dob/dod or formed/disbanded, see
	 * biographyDateProps()). upsertXref(), same as reloadFromWikidata(), so a repeat is an update.
	 *
	 * @return list<string>  human-readable lines of what was applied
	 */
	public function applyMusicBrainzData( array $pMb ): array {
		$items = [];
		$this->upsertXref( $this->mContentId, 'musicbrainz', [ 'xkey_ext' => $pMb['mbid'] ] );
		$items[] = 'musicbrainz: '.$pMb['mbid'];
		foreach( [ 'discogs_artist', 'imdb' ] as $item ) {
			if( !empty( $pMb[$item] ) && isset( static::EXTERNAL_ID_PROPS[$item] ) ) {
				$this->upsertXref( $this->mContentId, $item, [ 'xkey_ext' => $pMb[$item] ] );
				$items[] = $item.': '.$pMb[$item];
			}
		}
		if( $this instanceof ContactWikiGroup && isset( ContactWikiGroup::MUSICBRAINZ_TYPE_MAP[$pMb['type'] ?? ''] ) ) {
			foreach( $this->addTypeTags( [ ContactWikiGroup::MUSICBRAINZ_TYPE_MAP[$pMb['type']] ] ) as $added ) {
				$items[] = 'type tag added: '.$added;
			}
		}
		$dateItems = array_keys( $this->biographyDateProps() );
		foreach( [ $dateItems[0] => $pMb['begin'], $dateItems[1] => $pMb['end'] ] as $item => $value ) {
			if( !empty( $value ) ) {
				$this->upsertXref( $this->mContentId, $item, [ 'xkey_ext' => $value ] );
				$items[] = $item.': '.$value;
			}
		}
		return $items;
	}


	public static function extractQid( string $pInput ): ?string {
		return preg_match( '/(Q\d+)/i', $pInput, $matches ) ? strtoupper( $matches[1] ) : null;
	}

	/**
	 * Wikidata/Wikipedia/MusicBrainz all ask API etiquette-wise for a real contact in the
	 * User-Agent string - a site operator's own contact, not a fixed value baked into this
	 * package, so it lives in kernel_config the same way fisheye's own
	 * fisheye_musicbrainz_contact does. Blank (the default) sends no contact info at all, which
	 * these APIs still accept, just at a higher risk of being rate-limited/blocked.
	 */
	protected static function userAgentHeader(): string {
		global $gBitSystem;
		$contact = $gBitSystem->getConfig( 'contactwiki_api_contact', '' );
		return "User-Agent: bitweaver-contactwiki/1.0".( !empty( $contact ) ? " ( $contact )" : '' )."\r\n";
	}

	public static function fetchWikidataEntity( string $pQid ): ?array {
		$context = stream_context_create( [ 'http' => [
			'header'  => self::userAgentHeader(),
			'timeout' => 15,
		] ] );
		$json = @file_get_contents( "https://www.wikidata.org/wiki/Special:EntityData/$pQid.json", false, $context );
		if( $json === false ) {
			return null;
		}
		$data = json_decode( $json, true );
		return $data['entities'][$pQid] ?? null;
	}

	/**
	 * The enwiki sitelink Wikidata's own entity carries (separate from claims entirely - see
	 * contactwiki/MANUAL.md's own "What actually answers 'what populates the bio'" section), title
	 * form ("Fleetwood_Mac", underscores not spaces) ready to hand straight to Wikipedia's own REST
	 * summary endpoint. Null when this entity has no English Wikipedia article at all.
	 */
	public static function wikipediaTitle( array $pEntity ): ?string {
		return $pEntity['sitelinks']['enwiki']['title'] ?? null;
	}

	/**
	 * Wikipedia's own REST summary endpoint - a clean lead-paragraph extract, no auth needed, works
	 * identically for a person or a group article. Chosen over TMDb (person/film-cast only) and
	 * TheAudioDB (its free API 404s on well-known artists) as this package's one generic biography
	 * source.
	 */
	public static function fetchWikipediaSummary( string $pTitle ): ?string {
		$context = stream_context_create( [ 'http' => [
			'header'  => self::userAgentHeader(),
			'timeout' => 15,
		] ] );
		$json = @file_get_contents( 'https://en.wikipedia.org/api/rest_v1/page/summary/'.rawurlencode( $pTitle ), false, $context );
		if( $json === false ) {
			return null;
		}
		$data = json_decode( $json, true );
		$extract = trim( (string)( $data['extract'] ?? '' ) );
		return $extract !== '' ? $extract : null;
	}

	// A MusicBrainz artist id is a bare UUID - distinguishable from a Wikidata Qid or URL, so the
	// same input field can accept either (see resolveWikidataQidFromMusicBrainzArtist()'s own
	// docblock for why this matters).
	public static function extractMusicBrainzArtistId( string $pInput ): ?string {
		return preg_match( '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $pInput, $matches )
			? strtolower( $matches[1] ) : null;
	}

	/**
	 * MusicBrainz's own ARTIST-level entity (not a release/album - fisheye's own existing
	 * fetchDiscogsLink() only ever queries the release endpoint, which has no reason to carry this)
	 * commonly links out to the artist's own Wikidata item as a 'wikidata' url-rel - confirmed live
	 * against Fleetwood Mac's own MusicBrainz artist id, resolving to exactly the Q106648 already
	 * found by hand. Lets a MusicBrainz-tagged artist/group be fetched without the user needing to
	 * separately go and search Wikidata at all. Returns null if this artist has no such relation on
	 * MusicBrainz, or the lookup fails outright.
	 */
	public static function resolveWikidataQidFromMusicBrainzArtist( string $pMbArtistId ): ?string {
		return self::lookupMusicBrainzArtist( $pMbArtistId )['wikidata_qid'] ?? null;
	}

	/**
	 * The richer form behind resolveWikidataQidFromMusicBrainzArtist() - one HTTP round trip giving
	 * the artist's own MusicBrainz name, its 'type' ('Person', 'Group', 'Orchestra', 'Choir', ... -
	 * MusicBrainz's own distinction, not WPxx/WBxx), and the resolved Wikidata qid if it has one.
	 * Used by load_wiki_artists.php's own batch survey to decide ContactWikiIndividual vs
	 * ContactWikiGroup without a second lookup. Null if the artist id doesn't resolve to a real
	 * MusicBrainz artist at all (not just "no Wikidata link" - see the 'wikidata_qid' key for that).
	 *
	 * Also the extra fields createFromMusicBrainz() uses for a contact with no Wikidata item (sort
	 * name, life span, Discogs/IMDb ids from the artist's own url-rels).
	 *
	 * @return array{mbid:string,name:?string,sort_name:?string,type:?string,gender:?string,
	 *               disambiguation:?string,begin:?string,end:?string,country:?string,
	 *               wikidata_qid:?string,discogs_artist:?string,imdb:?string}|null
	 */
	public static function lookupMusicBrainzArtist( string $pMbArtistId ): ?array {
		$context = stream_context_create( [ 'http' => [
			'header'  => self::userAgentHeader(),
			'timeout' => 15,
		] ] );
		$json = @file_get_contents(
			"https://musicbrainz.org/ws/2/artist/$pMbArtistId?inc=url-rels&fmt=json", false, $context
		);
		if( $json === false ) {
			return null;
		}
		$data = json_decode( $json, true );
		if( empty( $data['id'] ) ) {
			return null;
		}
		$wikidataQid = $discogsArtist = $imdb = null;
		foreach( $data['relations'] ?? [] as $relation ) {
			$url = $relation['url']['resource'] ?? '';
			switch( strtolower( $relation['type'] ?? '' ) ) {
				case 'wikidata':
					if( !$wikidataQid && preg_match( '#/(Q\d+)$#i', $url, $matches ) ) {
						$wikidataQid = strtoupper( $matches[1] );
					}
					break;
				case 'discogs':
					if( !$discogsArtist && preg_match( '#/artist/(\d+)#', $url, $matches ) ) {
						$discogsArtist = $matches[1];
					}
					break;
				case 'imdb':
					if( !$imdb && preg_match( '#/name/(nm\d+)#', $url, $matches ) ) {
						$imdb = $matches[1];
					}
					break;
			}
		}
		return [
			'mbid'           => $data['id'],
			'name'           => $data['name'] ?? null,
			'sort_name'      => $data['sort-name'] ?? null,
			'type'           => $data['type'] ?? null,
			'gender'         => $data['gender'] ?? null,
			'disambiguation' => $data['disambiguation'] ?? null,
			'begin'          => $data['life-span']['begin'] ?? null,
			'end'            => $data['life-span']['end'] ?? null,
			'country'        => $data['area']['iso-3166-1-codes'][0] ?? null,
			'wikidata_qid'   => $wikidataQid,
			'discogs_artist' => $discogsArtist,
			'imdb'           => $imdb,
		];
	}

	/**
	 * TMDb's own read access token (v4, Bearer auth) - a real secret, so it lives in kernel_config
	 * (contactwiki_tmdb_token, package='contactwiki', editable via admin_contactwiki_inc.php's own
	 * settings page), never in a committed file, same as fisheye's own fisheye_plex_token.
	 * Returns null (not an error) when unconfigured, so a site with no token set just skips this
	 * step silently rather than failing the whole fetch. Not currently called from
	 * reloadFromWikidata() any more (see that method's own docblock - Wikipedia replaced it as the
	 * generic biography source) - kept for a later film/TV credit bio use, where TMDb's own person
	 * bios are genuinely the better/more detailed source, unlike here.
	 */
	public static function fetchTmdbBiography( string $pTmdbId ): ?string {
		global $gBitSystem;
		$token = $gBitSystem->getConfig( 'contactwiki_tmdb_token', '' );
		if( $token === '' ) {
			return null;
		}
		$context = stream_context_create( [ 'http' => [
			'header'  => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
			'timeout' => 15,
		] ] );
		$json = @file_get_contents( "https://api.themoviedb.org/3/person/$pTmdbId?language=en-US", false, $context );
		if( $json === false ) {
			return null;
		}
		$data = json_decode( $json, true );
		$bio = trim( (string)( $data['biography'] ?? '' ) );
		return $bio !== '' ? $bio : null;
	}

	/**
	 * TMDb's own biography field is plain text, paragraphs separated by a blank line (a literal
	 * "\n\n") - fine as-is in the add-flow's own plain, non-wysiwyg preview textarea (a <textarea>
	 * always renders \n as a visible line break regardless), but this package's Notes tab (edit.tpl's
	 * own {textarea}) turns wysiwyg on automatically whenever the sitewide bithtml plugin is active -
	 * CKEditor then treats the stored value as HTML *source*, where a bare newline is just collapsed
	 * whitespace, not a paragraph break, so the nice-looking bio flattens into one undifferentiated
	 * block the moment it's actually saved. Converts each blank-line-separated block into its own
	 * real <p>, with any remaining single newline inside one becoming a <br> - the same shape a
	 * normal hand-typed CKEditor note already saves as, so this just matches that existing
	 * convention for an auto-imported one instead of introducing a new format.
	 */
	public static function plainTextToHtmlParagraphs( string $pText ): string {
		$blocks = preg_split( '/\n\s*\n/', trim( $pText ) );
		$blocks = array_filter( array_map( 'trim', $blocks ), fn( $p ) => $p !== '' );
		return implode( '', array_map(
			fn( $p ) => '<p>'.nl2br( htmlspecialchars( $p, ENT_QUOTES, 'UTF-8' ) ).'</p>',
			$blocks
		) );
	}

	// Only string-valued claims (external ids) - P106/P569 etc. are wikibase-item/time typed and
	// handled by their own dedicated helpers below, this one would just return null for those.
	public static function stringClaim( array $pEntity, string $pProperty ): ?string {
		foreach( $pEntity['claims'][$pProperty] ?? [] as $claim ) {
			$value = $claim['mainsnak']['datavalue']['value'] ?? null;
			if( is_string( $value ) ) {
				return $value;
			}
		}
		return null;
	}

	// Every wikibase-item-typed value (Q-id) claimed under a given property - shared shape behind
	// both occupationQids() (P106, individual role suggestions) and instanceOfQids() (P31, group
	// type suggestions), not just one of them.
	public static function itemClaimQids( array $pEntity, string $pProperty ): array {
		$qids = [];
		foreach( $pEntity['claims'][$pProperty] ?? [] as $claim ) {
			$id = $claim['mainsnak']['datavalue']['value']['id'] ?? null;
			if( $id ) {
				$qids[] = $id;
			}
		}
		return $qids;
	}

	// Wikidata's own time value is "+YYYY-MM-DDT00:00:00Z" (a leading sign, always) - only the
	// date portion is used, precision (day/month/year-only) isn't checked since a plain date is
	// all liberty_content.event_time can hold anyway.
	public static function dateClaim( array $pEntity, string $pProperty ): ?string {
		foreach( $pEntity['claims'][$pProperty] ?? [] as $claim ) {
			$time = $claim['mainsnak']['datavalue']['value']['time'] ?? null;
			if( $time && preg_match( '/([+-]?\d{4}-\d{2}-\d{2})/', $time, $matches ) ) {
				// Year- or month-precision values come through as YYYY-00-00 / YYYY-MM-00 - stored
				// as plain YYYY / YYYY-MM instead (see dateToEventTime() for the sortable form).
				return preg_replace( '/(-00)+$/', '', ltrim( $matches[1], '+' ) );
			}
		}
		return null;
	}

	// P18's own value is a bare Commons filename (e.g. "Olivia Newton John (...).jpg"), not a URL
	// - entity-typed like the occupation claims, but a plain string value rather than a
	// wikibase-item reference, so this is its own small helper rather than reusing stringClaim().
	public static function imageFilename( array $pEntity ): ?string {
		foreach( $pEntity['claims']['P18'] ?? [] as $claim ) {
			$value = $claim['mainsnak']['datavalue']['value'] ?? null;
			if( is_string( $value ) && $value !== '' ) {
				return $value;
			}
		}
		return null;
	}

	// Commons' own Special:FilePath redirect resolves a bare filename straight to the image bytes,
	// no need to compute the md5-hash-bucketed upload.wikimedia.org path by hand. Downloaded and
	// stored locally (see getExtraImagePath()), never hotlinked - same reasoning as every other
	// externally-sourced image already saved locally elsewhere in this stack.
	public static function downloadCommonsFile( string $pFilename, string $pDestPath ): bool {
		$context = stream_context_create( [ 'http' => [
			'header'  => self::userAgentHeader(),
			'timeout' => 20,
			'follow_location' => 1,
		] ] );
		$url = 'https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode( $pFilename );
		$bytes = @file_get_contents( $url, false, $context );
		if( $bytes === false || $bytes === '' ) {
			return false;
		}
		return (bool)file_put_contents( $pDestPath, $bytes );
	}
}
