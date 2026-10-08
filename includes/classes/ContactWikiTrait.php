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

		// Characters: who played this one in what / the characters this person played (film and season cast rows linked to it).
		if( class_exists( '\\Bitweaver\\Fisheyemedia\\FisheyeCredits' ) ) {
			$pBitSmarty->assign( 'characterLinks', \Bitweaver\Fisheyemedia\FisheyeCredits::characterLinksFor( (int)$this->mContentId ) );
		}

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

	/** @var bool a bulk pass can leave biographies out too: none is fetched, and one the prefetch did not already hold is skipped (loaded when an editor first opens the contact - see fillMissingFromWikidata()). */
	public static bool $skipBiography = false;

	/** @var bool a bulk pass can leave photos out: none is fetched, and one the prefetch did not already hold is skipped (a contact's own Reload from Wikidata loads it later). */
	public static bool $skipPhotos = false;

	/** @var array<string,float> seconds spent per step of a contact build, summed over the request (shown on the people pass). */
	public static array $stepTimings = [];

	/** Add the time since $pSince to $pStep and return a fresh start time for the next step. */
	protected static function stepDone( string $pStep, float $pSince ): float {
		$now = microtime( true );
		self::$stepTimings[$pStep] = ( self::$stepTimings[$pStep] ?? 0.0 ) + $now - $pSince;
		return $now;
	}

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
	public function reloadFromWikidata( ?string $pQid = null, bool $pBiographyStored = false ): array {
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
		$t = microtime( true );
		$entity = self::fetchWikidataEntity( $qid );
		$t = self::stepDone( 'entity fetch', $t );
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
		$t = self::stepDone( 'entity store', $t );

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

		$t = self::stepDone( 'type tags', $t );
		foreach( static::EXTERNAL_ID_PROPS as $item => $property ) {
			$value = self::stringClaim( $entity, $property );
			if( $value !== null ) {
				$this->upsertXref( $this->mContentId, $item, [ 'xkey_ext' => $value ] );
				$items[] = $item.': '.$value;
			}
		}
		$t = self::stepDone( 'external ids', $t );

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
		$wikiTitle = $pBiographyStored ? null : self::wikipediaTitle( $entity );
		if( $wikiTitle !== null ) {
			$bio = self::fetchWikipediaSummary( $wikiTitle );
			if( $bio !== null ) {
				$bioHash = [ 'content_id' => $this->mContentId, 'title' => $this->getTitle(), 'edit' => self::plainTextToHtmlParagraphs( $bio ) ];
				\Bitweaver\Liberty\LibertyContent::store( $bioHash );
				$items[] = KernelTools::tra( 'Biography' ).' ('.KernelTools::tra( 'Wikipedia' ).')';
			}
		}

		$t = self::stepDone( 'biography', $t );
		foreach( $this->biographyDateProps() as $item => $property ) {
			$value = self::dateClaim( $entity, $property );
			if( $value !== null ) {
				$this->upsertXref( $this->mContentId, $item, [ 'xkey_ext' => $value ] );
				$items[] = $item.': '.$value;
			}
		}

		$t = self::stepDone( 'dates', $t );
		$imageFilename = self::imageFilename( $entity );
		if( $imageFilename && self::$skipPhotos && WikimediaCache::getImage( $imageFilename ) === null ) {
			$imageFilename = null;
		}
		if( $imageFilename && $this->storeCommonsPhoto( $imageFilename ) ) {
			$items[] = KernelTools::tra( 'Image' ).': '.$imageFilename;
		}
		self::stepDone( 'photo', $t );

		return [ 'items' => $items ];
	}

	/**
	 * Load what a contact is still missing from its stored Wikidata entity - the Wikipedia biography and the Commons photo - without the
	 * full reload: bulk creation leaves them out (they are the slow, throttled part), and this is called when an editor first opens the
	 * contact. Nothing is fetched unless the stored entity says there is something to fetch (an English Wikipedia article / a P18 image),
	 * so a contact with neither costs a database read only. A refused or failed request stores nothing, so the next view tries again.
	 *
	 * @return array{bio:bool, photo:bool}  what was loaded
	 */
	public function fillMissingFromWikidata(): array {
		global $gBitDb;
		$ret = [ 'bio' => false, 'photo' => false ];
		if( !$this->isValid() ) {
			return $ret;
		}
		$needBio = trim( (string)( $this->mInfo['data'] ?? '' ) ) === '';
		$needPhoto = !(int)$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL", [ $this->mContentId ] );
		if( !$needBio && !$needPhoto ) {
			return $ret;
		}
		$json = $gBitDb->getOne( "SELECT `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'wikidata' AND `end_date` IS NULL", [ $this->mContentId ] );
		$entity = $json ? json_decode( (string)$json, true ) : null;
		if( !is_array( $entity ) ) {
			return $ret;
		}
		if( $needBio && ( $title = self::wikipediaTitle( $entity ) ) !== null && ( $bio = self::fetchWikipediaSummary( $title ) ) !== null ) {
			$bioHash = [ 'content_id' => $this->mContentId, 'title' => $this->getTitle(), 'edit' => self::plainTextToHtmlParagraphs( $bio ) ];
			$ret['bio'] = (bool)\Bitweaver\Liberty\LibertyContent::store( $bioHash );
		}
		if( $needPhoto && ( $file = self::imageFilename( $entity ) ) !== null ) {
			$ret['photo'] = $this->storeCommonsPhoto( $file );
		}
		return $ret;
	}

	/**
	 * The storage-relative branch (storage/attachments/<id%1000>/<id>/) this contact's downloaded images live in.
	 */
	public function getExtraImageBranch(): string {
		return \Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $this->mContentId ] );
	}

	/**
	 * Make one of this contact's 'image' files its thumbnail: rebuild thumbs/ (avatar to extra-large) in the same storage branch from
	 * it, replacing any set already there. The same hook, and the same thumbs/-beside-the-images layout, FisheyeFilm uses - edit_xref.php
	 * calls it for "Set as Thumbnail".
	 *
	 * @param string $pRelativePath  an 'image' xref row's xkey_ext (a bare filename)
	 */
	public function promoteImageToThumbnail( string $pRelativePath ): bool {
		global $gBitSystem;
		$source = $this->getExtraImagePath( $pRelativePath );
		if( $pRelativePath === '' || !is_file( $source ) ) {
			return false;
		}
		foreach( glob( $this->getExtraImagePath( 'thumbs/*' ) ) ?: [] as $oldThumb ) {
			@unlink( $oldThumb );
		}
		$hash = [
			'source_file' => $source,
			'dest_branch' => $this->getExtraImageBranch(),
			'type'        => $gBitSystem->verifyMimeType( $source ) ?: 'image/jpeg',
		];
		// A scan or archive photo can be bigger than ImageMagick's policy allows the thumbnailer to open (8000 px a side) - shrink it first, by asking the decoder to scale down as it reads (JPEG only - the policy is the machine's, not ours to raise).
		$shrunk = null;
		$dims = @getimagesize( $source );
		if( $dims && max( $dims[0], $dims[1] ) > 6000 && class_exists( '\Imagick' ) ) {
			try {
				$im = new \Imagick();
				// jpeg:size makes the decoder scale down while reading, so the full-size image is never opened (the policy limit is on the opened size).
				$im->setOption( 'jpeg:size', '4000x4000' );
				$im->readImage( $source.'[0]' );
				$im->thumbnailImage( 3000, 3000, true );
				$im->setImageFormat( 'jpeg' );
				$shrunk = tempnam( sys_get_temp_dir(), 'cwphoto_' );
				$im->writeImage( 'jpeg:'.$shrunk );
				$hash['source_file'] = $shrunk;
				$hash['type'] = 'image/jpeg';
			} catch( \Exception $e ) {
				// leave the original as the source; the thumbnailer will report its own failure
			}
		}
		\Bitweaver\Liberty\liberty_generate_thumbnails( $hash );
		if( $shrunk ) {
			@unlink( $shrunk );
		}
		return $this->hasThumbnails();
	}

	public function hasThumbnails(): bool {
		return !empty( glob( $this->getExtraImagePath( 'thumbs/small.*' ) ) );
	}

	/**
	 * A downloaded photo becomes the thumbnail only while the contact has none - a later download never replaces one somebody chose.
	 */
	protected function thumbnailIfNone( string $pRelativePath ): void {
		if( !$this->hasThumbnails() ) {
			$this->promoteImageToThumbnail( $pRelativePath );
		}
	}

	/** The thumbs/ file for lists and cards (getThumbnailUri()), else whatever the content type shows by default. */
	public static function getThumbnailUrlFromHash( array &$pMixed, string $pSize = 'small', ?int $pSecondaryId = null, ?int $pDefault = null ): string {
		if( !empty( $pMixed['content_id'] ) ) {
			$branch = \Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $pMixed['content_id'], 'create_dir' => false ] );
			if( $found = glob( STORAGE_PKG_PATH.$branch.'thumbs/'.basename( $pSize ).'.*' ) ) {
				return STORAGE_PKG_URL.$branch.'thumbs/'.basename( $found[0] );
			}
		}
		return parent::getThumbnailUrlFromHash( $pMixed, $pSize, $pSecondaryId, $pDefault );
	}

	// ---- The generic xref file hooks liberty/add_xref.php and edit_xref.php call (the same set FisheyeFilm has), so the Images tab can add, replace and delete files.

	public function addImageXrefFile( string $pTmpPath, string $pOriginalName ): ?string {
		$imagesDir = $this->getExtraImagePath( '' );
		KernelTools::mkdir_p( $imagesDir );
		$baseName = preg_replace( '/[^A-Za-z0-9]+/', '_', $this->getTitle() ) ?: 'image';
		$ext = strtolower( pathinfo( $pOriginalName, PATHINFO_EXTENSION ) ) ?: 'jpg';
		$n = 1;
		do {
			$fileName = "$baseName-manual-$n.$ext";
			$n++;
		} while( is_file( $imagesDir.$fileName ) );
		if( !move_uploaded_file( $pTmpPath, $imagesDir.$fileName ) ) {
			return null;
		}
		@chmod( $imagesDir.$fileName, 0644 );
		$this->thumbnailIfNone( $fileName );
		return $fileName;
	}

	public function replaceXrefFile( string $pItem, string $pXkeyExt, string $pTmpPath ): bool {
		if( $pItem !== 'image' || $pXkeyExt === '' || basename( $pXkeyExt ) !== $pXkeyExt ) {
			return false;
		}
		return move_uploaded_file( $pTmpPath, $this->getExtraImagePath( $pXkeyExt ) );
	}

	public function deleteXrefFile( string $pItem, string $pXkeyExt ): bool {
		if( $pItem !== 'image' || $pXkeyExt === '' || basename( $pXkeyExt ) !== $pXkeyExt ) {
			return false;
		}
		$path = $this->getExtraImagePath( $pXkeyExt );
		return is_file( $path ) && @unlink( $path );
	}

	/**
	 * Download a Commons photo (the cache first, see WikimediaCache) into this contact's own image folder and record it as its 'image' xref.
	 *
	 * @return bool  false if the download failed
	 */
	public function storeCommonsPhoto( string $pImageFilename ): bool {
		$imagesDir = $this->getExtraImagePath( '' );
		$ext = strtolower( pathinfo( $pImageFilename, PATHINFO_EXTENSION ) ) ?: 'jpg';
		$storedName = 'wikidata.'.$ext;
		KernelTools::mkdir_p( $imagesDir );
		if( !self::downloadCommonsFile( $pImageFilename, $imagesDir.$storedName ) ) {
			return false;
		}
		// Commons may have re-rendered the file (an SVG or TIFF comes back as PNG/JPEG) - name it for what it is.
		$realExt = self::imageExtensionOf( $imagesDir.$storedName );
		if( $realExt !== null && $realExt !== $ext && @rename( $imagesDir.$storedName, $imagesDir.'wikidata.'.$realExt ) ) {
			$storedName = 'wikidata.'.$realExt;
		}
		$this->upsertXref( $this->mContentId, 'image', [ 'xkey_ext' => $storedName ] );
		$this->thumbnailIfNone( $storedName );
		return true;
	}

	/**
	 * Fill in the photos wiki contacts are missing - the ones created without (the people pass leaves photos out when Wikimedia is throttling).
	 * A contact is a candidate if its stored Wikidata entity names an image (P18) and it has no 'image' of its own, so nothing is fetched
	 * to find out. Photos are fetched a few at a time into the cache, then stored; if Wikimedia refuses most of a chunk the pass stops
	 * rather than wait it out - the refused contacts stay candidates for a later run (this method is also what a cron/CLI wrapper would call).
	 *
	 * @param int   $pAfter   only contacts with a content_id above this (the cursor a Continue carries)
	 * @param float $pBudget  seconds after which no new chunk is started
	 * @return array{done:int, failed:int, throttled:bool, next:?int, missing:int, seconds:float, contacts:list<array{content_id:int,title:string}>}
	 */
	public static function loadMissingPhotos( int $pAfter = 0, float $pBudget = 30.0 ): array {
		global $gBitDb;
		$started = microtime( true );
		$result = [ 'done' => 0, 'failed' => 0, 'throttled' => false, 'next' => null, 'missing' => 0, 'seconds' => 0.0, 'contacts' => [] ];
		$candidateSql = "FROM `".BIT_DB_PREFIX."liberty_content` c
			JOIN `".BIT_DB_PREFIX."liberty_xref` w ON w.`content_id` = c.`content_id` AND w.`item` = 'wikidata' AND w.`end_date` IS NULL
			WHERE c.`content_type_guid` IN ( 'contactwikiindi', 'contactwikigroup' ) AND w.`data` CONTAINING '\"P18\"'
			AND NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` i WHERE i.`content_id` = c.`content_id` AND i.`item` = 'image' AND i.`end_date` IS NULL )";
		$userAgent = trim( preg_replace( '/^User-Agent:\s*/i', '', self::userAgentHeader() ) );
		$cursor = $pAfter;
		while( microtime( true ) - $started < $pBudget ) {
			$rows = $gBitDb->getAll( "SELECT FIRST 20 c.`content_id`, c.`content_type_guid`, c.`title`, w.`data` $candidateSql AND c.`content_id` > ? ORDER BY c.`content_id`", [ $cursor ] ) ?: [];
			if( !$rows ) {
				break;
			}
			$files = [];
			foreach( $rows as $row ) {
				$entity = json_decode( (string)$row['data'], true );
				$file = is_array( $entity ) ? self::imageFilename( $entity ) : null;
				if( $file !== null && WikimediaCache::getImage( $file ) === null ) {
					$files[$file] = self::commonsPhotoUrl( $file );
				}
			}
			$requests = [];
			foreach( $files as $file => $url ) {
				$requests['i:'.$file] = $url;
			}
			$refused = [];
			foreach( [ 0, 1 ] as $attempt ) {
				$fetched = WikimediaCache::multiFetch( $attempt ? array_intersect_key( $requests, array_flip( $refused ) ) : $requests, $userAgent, $attempt ? 1 : 3, 25 );
				$refused = [];
				foreach( $fetched as $key => $r ) {
					if( $r['status'] === 200 && (string)$r['body'] !== '' ) {
						WikimediaCache::putImage( substr( $key, 2 ), $r['body'] );
					} elseif( in_array( $r['status'], [ 429, 503 ], true ) ) {
						$refused[] = $key;
						$wait = max( $wait ?? 0, $r['retry_after'] );
					}
				}
				if( !$refused || !$requests ) {
					break;
				}
				if( !$attempt ) {
					sleep( min( 6, max( 2, $wait ?? 0 ) ) );
				}
			}
			if( $requests && count( $refused ) * 2 > count( $requests ) ) {
				// Wikimedia is refusing most of them: stop, leave these for later.
				$result['throttled'] = true;
				break;
			}
			foreach( $rows as $row ) {
				$cursor = (int)$row['content_id'];
				$entity = json_decode( (string)$row['data'], true );
				$file = is_array( $entity ) ? self::imageFilename( $entity ) : null;
				if( $file === null || in_array( 'i:'.$file, $refused, true ) ) {
					$result['failed']++;
					continue;
				}
				$class = $row['content_type_guid'] === 'contactwikigroup' ? ContactWikiGroup::class : ContactWikiIndividual::class;
				$contact = new $class( null, (int)$row['content_id'] );
				$contact->load();
				if( $contact->storeCommonsPhoto( $file ) ) {
					$result['done']++;
					if( count( $result['contacts'] ) < 40 ) {
						$result['contacts'][] = [ 'content_id' => (int)$row['content_id'], 'title' => (string)$row['title'] ];
					}
				} else {
					$result['failed']++;
				}
			}
			if( count( $rows ) < 20 ) {
				$cursor = 0;
				break;
			}
		}
		$result['next'] = $cursor > 0 && microtime( true ) - $started >= $pBudget ? $cursor : null;
		$result['missing'] = (int)$gBitDb->getOne( "SELECT COUNT(*) $candidateSql" );
		$result['seconds'] = round( microtime( true ) - $started, 1 );
		return $result;
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
			$json = self::fetchExternal( 'https://query.wikidata.org/sparql', $context );
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

	/**
	 * A name reduced to a comparison key: accents folded, lower-cased, punctuation and runs of space
	 * collapsed ("Newton-John, Olivia" and "Olivia  Newton John" differ only by word order, not by
	 * this). Word order is handled separately by nameForms().
	 */
	public static function normaliseName( string $pName ): string {
		$name = function_exists( 'transliterator_transliterate' )
			? (string)transliterator_transliterate( 'Any-Latin; Latin-ASCII; Lower()', $pName )
			: strtolower( (string)iconv( 'UTF-8', 'ASCII//TRANSLIT', $pName ) );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $name ) );
	}

	/**
	 * The comparison keys a contact's title answers to: as stored, and with a "Surname, Forename"
	 * title flipped to "Forename Surname" (the order credits are written in).
	 *
	 * @return string[]
	 */
	public static function nameForms( string $pTitle ): array {
		$forms = [ self::normaliseName( $pTitle ) ];
		if( preg_match( '/^([^,]+),\s*(.+)$/u', trim( $pTitle ), $m ) ) {
			$forms[] = self::normaliseName( $m[2].' '.$m[1] );
		}
		return array_values( array_unique( array_filter( $forms ) ) );
	}

	/**
	 * Every wiki contact (individual and group) indexed by the name forms it answers to, for matching
	 * a plain-text credit to an existing contact without a query per name.
	 *
	 * @return array<string, array<int, array{content_id:int, title:string, content_type_guid:string, qid:?string}>>
	 *         normalised name => contacts (keyed by content_id)
	 */
	public static function nameIndex(): array {
		global $gBitDb;
		$rows = $gBitDb->getAll(
			"SELECT lc.`content_id`, lc.`title`, lc.`content_type_guid`, x.`xkey_ext` AS qid
			 FROM `".BIT_DB_PREFIX."liberty_content` lc
			 LEFT JOIN `".BIT_DB_PREFIX."liberty_xref` x ON x.`content_id` = lc.`content_id` AND x.`item` = 'wikidata' AND x.`end_date` IS NULL
			 WHERE lc.`content_type_guid` IN ( 'contactwikiindi', 'contactwikigroup' )"
		) ?: [];
		$index = [];
		foreach( $rows as $row ) {
			$contact = [ 'content_id' => (int)$row['content_id'], 'title' => $row['title'],
				'content_type_guid' => $row['content_type_guid'], 'qid' => !empty( $row['qid'] ) ? strtoupper( $row['qid'] ) : null ];
			foreach( self::nameForms( (string)$row['title'] ) as $form ) {
				$index[$form][$contact['content_id']] = $contact;
			}
		}
		return $index;
	}

	/**
	 * GET one TMDb v3 API path with the configured Read Access Token. Null (reason in
	 * getLastFetchError()) when no token is set or the call fails.
	 */
	protected static function tmdbGet( string $pPath ): ?array {
		global $gBitSystem;
		$token = $gBitSystem->getConfig( 'contactwiki_tmdb_token', '' );
		if( $token === '' ) {
			self::$lastFetchError = 'No TMDb access token is set (contactwiki admin settings)';
			return null;
		}
		$context = stream_context_create( [ 'http' => [
			'header'  => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
			'timeout' => 15,
		] ] );
		$json = self::fetchExternal( 'https://api.themoviedb.org/3'.$pPath, $context );
		return $json === false ? null : ( json_decode( $json, true ) ?: [] );
	}

	/**
	 * TMDb credits (cast + crew, movie or aggregate TV) as person ids by normalised name, each with the
	 * credit roles this system uses it for: 'star' (cast), 'director' (crew job Director), 'writer' (crew
	 * department Writing). Lets a credited name be matched to the TMDb person who did that job - an actor
	 * and a director of the same name are different people.
	 *
	 * @return array<string, array<int, array{name:string, roles:string[]}>>
	 */
	private static function tmdbCreditsByName( array $pData ): array {
		$byName = [];
		$add = function( array $pPerson, array $pRoles ) use ( &$byName ) {
			if( empty( $pPerson['id'] ) || empty( $pPerson['name'] ) ) {
				return;
			}
			$entry = &$byName[self::normaliseName( $pPerson['name'] )][(int)$pPerson['id']];
			$entry ??= [ 'name' => $pPerson['name'], 'roles' => [] ];
			$entry['roles'] = array_values( array_unique( array_merge( $entry['roles'], $pRoles ) ) );
		};
		foreach( $pData['cast'] ?? [] as $person ) {
			$add( $person, [ 'star' ] );
		}
		foreach( $pData['crew'] ?? [] as $person ) {
			$jobs = isset( $person['jobs'] ) ? array_column( $person['jobs'], 'job' ) : array_filter( [ $person['job'] ?? null ] );
			$roles = [];
			if( in_array( 'Director', $jobs, true ) ) {
				$roles[] = 'director';
			}
			if( in_array( 'Creator', $jobs, true ) ) {
				$roles[] = 'creator';
			}
			if( ( $person['department'] ?? '' ) === 'Writing' ) {
				$roles[] = 'writer';
			}
			if( in_array( 'Narrator', $jobs, true ) ) {
				// A narrator is credited on the cast row too (Plex lists them there), so match either.
				array_push( $roles, 'narrator', 'star' );
			}
			$add( $person, $roles );
		}
		return $byName;
	}

	/**
	 * The candidates for a credited name that did one of the given jobs - all of them when none did
	 * (a credit TMDb files under another department, e.g. a creator).
	 *
	 * @param array<int, array{name:string, roles:string[]}> $pCandidates
	 * @param string[] $pRoles  credit roles of the person (director/writer/star)
	 * @return array<int, array{name:string, roles:string[]}>
	 */
	private static function filterByRole( array $pCandidates, array $pRoles ): array {
		$matching = array_filter( $pCandidates, fn( $c ) => array_intersect( $c['roles'], $pRoles ) );
		return $matching ?: $pCandidates;
	}

	/**
	 * A TMDb movie's cast and crew as person ids by normalised name - what a credit's plain-text name
	 * is matched against. Cached per request (several people share a film).
	 *
	 * @return array<string, array<int, array{name:string, roles:string[]}>>|null  normalised name => [ tmdb person id => ... ]
	 */
	public static function fetchTmdbMovieCredits( int $pMovieId ): ?array {
		static $cache = [];
		if( !array_key_exists( $pMovieId, $cache ) ) {
			$data = self::tmdbGet( "/movie/$pMovieId/credits" );
			if( $data === null ) {
				return null;
			}
			$cache[$pMovieId] = self::tmdbCreditsByName( $data );
		}
		return $cache[$pMovieId];
	}

	/**
	 * A TMDb TV series' whole cast and crew across every season (aggregate_credits) as person ids by
	 * normalised name - one call for the show, however many seasons. Same shape as
	 * fetchTmdbMovieCredits(). Cached per request.
	 *
	 * @return array<string, array<int, array{name:string, roles:string[]}>>|null  normalised name => [ tmdb person id => ... ]
	 */
	/** A TMDb TV show's own record (name, created_by, ...), cached per request. */
	private static function tmdbShow( int $pTvId ): ?array {
		static $cache = [];
		if( !array_key_exists( $pTvId, $cache ) ) {
			$cache[$pTvId] = self::tmdbGet( "/tv/$pTvId" );
		}
		return $cache[$pTvId];
	}

	/**
	 * A TMDb TV show's creators (its created_by) - not in the show's credits, so the show page's "Created by" line comes from here.
	 *
	 * @return list<array{id:int, name:string}>|null  null when TMDb could not be asked
	 */
	public static function fetchTmdbCreators( int $pTvId ): ?array {
		$show = self::tmdbShow( $pTvId );
		if( $show === null ) {
			return null;
		}
		$ret = [];
		foreach( $show['created_by'] ?? [] as $creator ) {
			if( !empty( $creator['id'] ) && !empty( $creator['name'] ) ) {
				$ret[] = [ 'id' => (int)$creator['id'], 'name' => $creator['name'] ];
			}
		}
		return $ret;
	}

	public static function fetchTmdbTvCredits( int $pTvId ): ?array {
		static $cache = [];
		if( !array_key_exists( $pTvId, $cache ) ) {
			$data = self::tmdbGet( "/tv/$pTvId/aggregate_credits" );
			if( $data === null ) {
				return null;
			}
			// A show's creator (Gene Roddenberry on Andromeda) is on the show record's created_by, not in its credits - and
			// Plex credits them as a writer, so they count as one.
			$show = self::tmdbShow( $pTvId );
			foreach( $show['created_by'] ?? [] as $creator ) {
				$data['crew'][] = [ 'id' => $creator['id'] ?? null, 'name' => $creator['name'] ?? null, 'department' => 'Writing', 'jobs' => [ [ 'job' => 'Creator' ] ] ];
			}
			$cache[$pTvId] = self::tmdbCreditsByName( $data );
		}
		return $cache[$pTvId];
	}

	/**
	 * Which TMDb person(s) a credited name is, from a TV series' aggregate credits, limited to those who did
	 * one of the person's credit roles. Same result shape as findTmdbPersonForCredit().
	 *
	 * @return array{ids:int[], names:array<int,string>, error:?string}
	 */
	public static function findTmdbPersonForTvCredit( string $pName, int $pTvId, array $pRoles = [] ): array {
		$credits = self::fetchTmdbTvCredits( $pTvId );
		if( $credits === null ) {
			return [ 'ids' => [], 'names' => [], 'error' => self::getLastFetchError() ];
		}
		$found = $credits[self::normaliseName( $pName )] ?? [];
		if( $pRoles ) {
			$found = self::filterByRole( $found, $pRoles );
		}
		return [ 'ids' => array_keys( $found ), 'names' => array_map( fn( $c ) => $c['name'], $found ), 'error' => null ];
	}

	/**
	 * Which TMDb person(s) a credited name is, from the credits of the films it appears in: the id(s)
	 * TMDb gives that name on each film's cast/crew list, until two films have agreed (or six have been
	 * looked at). One id = that person; more than one = two people of the same name, for a human to
	 * choose between.
	 *
	 * @param string $pName
	 * @param int[]  $pMovieIds  TMDb movie ids of the films credited
	 * @return array{ids:int[], names:array<int,string>, error:?string}
	 */
	public static function findTmdbPersonForCredit( string $pName, array $pMovieIds, array $pRoles = [] ): array {
		$key = self::normaliseName( $pName );
		$ids = $names = $hits = $films = [];
		$looked = 0;
		$error = null;
		foreach( $pMovieIds as $movieId ) {
			if( $looked++ >= 6 ) {
				break;
			}
			$credits = self::fetchTmdbMovieCredits( (int)$movieId );
			if( $credits === null ) {
				$error = self::getLastFetchError();
				continue;
			}
			$candidates = $credits[$key] ?? [];
			foreach( ( $pRoles ? self::filterByRole( $candidates, $pRoles ) : $candidates ) as $id => $candidate ) {
				$ids[$id] = $id;
				$names[$id] = $candidate['name'];
				$hits[$movieId] = true;
				$films[$id][(int)$movieId] = (int)$movieId;
			}
			if( count( $hits ) >= 2 ) {
				break;
			}
		}
		return [ 'ids' => array_values( $ids ), 'names' => $names, 'films' => array_map( 'array_values', $films ), 'error' => $ids ? null : $error ];
	}

	/**
	 * A TMDb person with their external ids (imdb, wikidata) - one call.
	 *
	 * @return array{id:int, name:string, birthday:?string, deathday:?string, place_of_birth:?string,
	 *         biography:string, known_for:?string, profile_path:?string, imdb_id:?string, wikidata_id:?string}|null
	 */
	public static function fetchTmdbPerson( int $pTmdbId ): ?array {
		$d = self::tmdbGet( "/person/$pTmdbId?language=en-US&append_to_response=external_ids" );
		if( !$d || empty( $d['name'] ) ) {
			return null;
		}
		return [
			'id'             => (int)$d['id'],
			'name'           => $d['name'],
			'birthday'       => $d['birthday'] ?: null,
			'deathday'       => $d['deathday'] ?: null,
			'place_of_birth' => $d['place_of_birth'] ?: null,
			'biography'      => trim( (string)( $d['biography'] ?? '' ) ),
			'known_for'      => $d['known_for_department'] ?? null,
			'profile_path'   => $d['profile_path'] ?: null,
			'imdb_id'        => $d['external_ids']['imdb_id'] ?: null,
			'wikidata_id'    => $d['external_ids']['wikidata_id'] ?: null,
		];
	}

	/**
	 * Wikidata items for a batch of TMDb person ids (P4985), one SPARQL query per 150 - the same
	 * shape as lookupWikidataByMusicBrainzIds(). An id Wikidata doesn't know is absent; one claimed by
	 * more than one item comes back with every candidate. Null if the query itself fails.
	 *
	 * @param list<int|string> $pTmdbIds
	 * @return array<string, list<array{qid:string, label:string, is_human:bool}>>|null  keyed by TMDb id
	 */
	public static function lookupWikidataByTmdbPersonIds( array $pTmdbIds ): ?array {
		$ret = [];
		foreach( array_chunk( array_values( array_unique( array_map( 'strval', $pTmdbIds ) ) ), 150 ) as $chunk ) {
			$values = implode( ' ', array_map( fn( $id ) => '"'.addslashes( $id ).'"', $chunk ) );
			$query = 'SELECT ?tid ?item ?itemLabel ?human ?desc WHERE { VALUES ?tid { '.$values.' } ?item wdt:P4985 ?tid . '
				.'OPTIONAL { ?item schema:description ?desc . FILTER( LANG( ?desc ) = "en" ) } '
				.'BIND( EXISTS { ?item wdt:P31 wd:Q5 } AS ?human ) SERVICE wikibase:label { bd:serviceParam wikibase:language "en". } }';
			$context = stream_context_create( [ 'http' => [
				'method'  => 'POST',
				'header'  => self::userAgentHeader()."Accept: application/sparql-results+json\r\nContent-Type: application/x-www-form-urlencoded\r\n",
				'content' => http_build_query( [ 'query' => $query ] ),
				'timeout' => 30,
			] ] );
			$json = self::fetchExternal( 'https://query.wikidata.org/sparql', $context );
			if( $json === false ) {
				return null;
			}
			foreach( json_decode( $json, true )['results']['bindings'] ?? [] as $row ) {
				if( preg_match( '#/(Q\d+)$#', $row['item']['value'] ?? '', $m ) ) {
					$ret[(string)$row['tid']['value']][] = [
						'qid'      => $m[1],
						'label'    => $row['itemLabel']['value'] ?? $m[1],
						'description' => $row['desc']['value'] ?? '',
						'is_human' => ( $row['human']['value'] ?? '' ) === 'true',
					];
				}
			}
		}
		return $ret;
	}

	/**
	 * Wikidata items whose label (or alias) is exactly this name - for a credited person TMDb has no record of (Plex tags them, TMDb's
	 * crew list lacks them). Nothing here is trusted: the caller shows each candidate with its description for a person to pick, none
	 * pre-selected. Disambiguation pages are dropped; a description that fits one of the credited roles marks the candidate 'fit' (listed first), one that
	 * reads like any screen/writing job 'likely'.
	 *
	 * @param string[] $pRoles  the person's credit roles (director/writer/star/creator)
	 * @return list<array{qid:string, label:string, description:string, fit:bool, likely:bool}>
	 */
	/** The type tag a character contact carries (a fictional character is a wiki individual with this type). */
	public const CHARACTER_TYPE = 'WP09';

	/**
	 * Wikidata items for films by TMDb movie id (P4947).
	 *
	 * @param int[] $pTmdbIds
	 * @return array<int,string>|null  tmdb id => Q-id; null if Wikidata could not be asked
	 */
	public static function wikidataFilmItems( array $pTmdbIds ): ?array {
		$pTmdbIds = array_values( array_unique( array_filter( array_map( 'intval', $pTmdbIds ) ) ) );
		if( !$pTmdbIds ) {
			return [];
		}
		$rows = self::wikidataSparql( 'SELECT ?tid ?f WHERE { VALUES ?tid { '.implode( ' ', array_map( fn( $i ) => '"'.$i.'"', $pTmdbIds ) ).' } ?f wdt:P4947 ?tid }' );
		if( $rows === null ) {
			return null;
		}
		$ret = [];
		foreach( $rows as $row ) {
			if( preg_match( '#/(Q\d+)$#', $row['f']['value'] ?? '', $m ) ) {
				$ret[(int)$row['tid']['value']] ??= $m[1];
			}
		}
		return $ret;
	}

	/**
	 * The characters Wikidata gives each film's cast: a cast statement (P161) carrying a character role (P453).
	 *
	 * @param string[] $pFilmQids
	 * @return array<string,array<string,list<array{qid:string,label:string}>>>|null  film Q => actor Q => characters; null if Wikidata could not be asked
	 */
	public static function wikidataFilmCharacters( array $pFilmQids ): ?array {
		$pFilmQids = array_values( array_unique( array_filter( $pFilmQids, fn( $q ) => preg_match( '/^Q\d+$/', (string)$q ) ) ) );
		if( !$pFilmQids ) {
			return [];
		}
		$rows = self::wikidataSparql( 'SELECT ?f ?a ?c ?cl WHERE { VALUES ?f { '.implode( ' ', array_map( fn( $q ) => 'wd:'.$q, $pFilmQids ) ).' } ?f p:P161 ?s . ?s ps:P161 ?a . ?s pq:P453 ?c . '
			.'OPTIONAL { ?c rdfs:label ?cl . FILTER( LANG( ?cl ) = "en" ) } }' );
		if( $rows === null ) {
			return null;
		}
		$ret = [];
		foreach( $rows as $row ) {
			if( preg_match( '#/(Q\d+)$#', $row['f']['value'] ?? '', $f ) && preg_match( '#/(Q\d+)$#', $row['a']['value'] ?? '', $a ) && preg_match( '#/(Q\d+)$#', $row['c']['value'] ?? '', $c ) ) {
				$ret[$f[1]][$a[1]][$c[1]] = [ 'qid' => $c[1], 'label' => (string)( $row['cl']['value'] ?? '' ) ];
			}
		}
		foreach( $ret as &$byActor ) {
			foreach( $byActor as &$chars ) {
				$chars = array_values( $chars );
			}
		}
		return $ret;
	}

	/**
	 * The Wikidata item(s) of a TV series, found through its TMDb TV id (P4983) or IMDb id (P345). A show can have more than one (Doctor Who has
	 * one for the franchise and one for 1963-1989). Empty if Wikidata has none or cannot be asked.
	 *
	 * @return list<string>  Q-ids
	 */
	public static function wikidataSeriesItems( ?int $pTmdbTvId, ?string $pImdbId ): array {
		$parts = [];
		if( $pTmdbTvId ) {
			$parts[] = '{ ?s wdt:P4983 "'.(int)$pTmdbTvId.'" }';
		}
		if( $pImdbId && preg_match( '/^tt\d+$/', $pImdbId ) ) {
			$parts[] = '{ ?s wdt:P345 "'.$pImdbId.'" }';
		}
		if( !$parts ) {
			return [];
		}
		$rows = self::wikidataSparql( 'SELECT DISTINCT ?s WHERE { '.implode( ' UNION ', $parts ).' }' );
		$qids = [];
		foreach( $rows ?? [] as $row ) {
			if( preg_match( '#/(Q\d+)$#', $row['s']['value'] ?? '', $m ) ) {
				$qids[] = $m[1];
			}
		}
		return array_values( array_unique( $qids ) );
	}

	/**
	 * The years a series ran, from the Wikidata item its OWN id points to (IMDb id first - the period-specific item, e.g. "Doctor Who (1963-1989)" -
	 * else TMDb's): start of the first item, end of the last, null end if still running or unknown. Lets a match be checked against the run.
	 *
	 * @return array{start:?int, end:?int}
	 */
	public static function wikidataSeriesPeriod( ?int $pTmdbTvId, ?string $pImdbId ): array {
		$cond = $pImdbId && preg_match( '/^tt\d+$/', $pImdbId ) ? '?s wdt:P345 "'.$pImdbId.'"' : ( $pTmdbTvId ? '?s wdt:P4983 "'.(int)$pTmdbTvId.'"' : null );
		if( !$cond ) {
			return [ 'start' => null, 'end' => null ];
		}
		$start = $end = null;
		$running = false;
		foreach( self::wikidataSparql( 'SELECT ?s ?start ?end WHERE { '.$cond.' . OPTIONAL { ?s wdt:P580 ?start } OPTIONAL { ?s wdt:P582 ?end } }' ) ?? [] as $row ) {
			$start = isset( $row['start']['value'] ) ? min( $start ?? 9999, (int)substr( $row['start']['value'], 0, 4 ) ) : $start;
			if( isset( $row['end']['value'] ) ) {
				$end = max( $end ?? 0, (int)substr( $row['end']['value'], 0, 4 ) );
			} else {
				$running = true;
			}
		}
		return [ 'start' => $start, 'end' => $running ? null : $end ];
	}

	/**
	 * Every person Wikidata ties to a series - as a cast member, director, screenwriter, producer or creator of the series itself or of any of its
	 * parts (episodes, serials, one level of sub-series) - indexed by normalised name (label and English aliases). For an older programme TMDb's
	 * credits are thin while Wikidata is often well curated; a credited name that matches someone tied to THIS show is a far safer match than a bare
	 * name search. One query per show per request.
	 *
	 * @param list<string> $pSeriesQids
	 * @return array<string, list<array{qid:string, label:string, description:string}>>  normalised name => the people with that name
	 */
	public static function wikidataSeriesPeople( array $pSeriesQids, bool $pWithYears = false ): array {
		static $cache = [];
		$qids = array_values( array_filter( array_unique( $pSeriesQids ), fn( $q ) => preg_match( '/^Q\d+$/', (string)$q ) ) );
		sort( $qids );
		$key = implode( ',', $qids ).( $pWithYears ? '+years' : '' );
		if( !$qids ) {
			return [];
		}
		// A show's Wikidata cast barely changes: keep it a few hours across requests (APCu where the stack has it), so paging through a show
		// does not repeat three queries on every page.
		$apcuKey = 'contactwiki_series_'.md5( $key );
		if( !isset( $cache[$key] ) && function_exists( 'apcu_fetch' ) && ( $stored = apcu_fetch( $apcuKey ) ) !== false ) {
			$cache[$key] = $stored;
		}
		if( !isset( $cache[$key] ) ) {
			$values = implode( ' ', array_map( fn( $q ) => "wd:$q", $qids ) );
			$rows = self::wikidataSparql( 'SELECT DISTINCT ?person ?personLabel ?personDescription ?alias WHERE {
				VALUES ?series { '.$values.' }
				{ ?series wdt:P161|wdt:P57|wdt:P58|wdt:P162|wdt:P170 ?person }
				UNION { ?w wdt:P179 ?series . ?w wdt:P161|wdt:P57|wdt:P58|wdt:P162 ?person }
				UNION { ?w wdt:P179 ?sub . ?sub wdt:P179 ?series . ?w wdt:P161|wdt:P57|wdt:P58 ?person }
				?person wdt:P31 wd:Q5 .
				OPTIONAL { ?person skos:altLabel ?alias . FILTER( LANG( ?alias ) = "en" ) }
				SERVICE wikibase:label { bd:serviceParam wikibase:language "en". } } LIMIT 40000' );
			$people = [];
			foreach( $rows ?? [] as $row ) {
				if( !preg_match( '#/(Q\d+)$#', $row['person']['value'] ?? '', $m ) ) {
					continue;
				}
				$people[$m[1]] ??= [ 'qid' => $m[1], 'label' => $row['personLabel']['value'] ?? $m[1], 'description' => $row['personDescription']['value'] ?? '', 'names' => [] ];
				$people[$m[1]]['names'][self::normaliseName( $row['personLabel']['value'] ?? '' )] = true;
				if( !empty( $row['alias']['value'] ) ) {
					$people[$m[1]]['names'][self::normaliseName( $row['alias']['value'] )] = true;
				}
			}
			// The earliest year each person is tied to a dated part of the series (two light queries, joined here - one combined query times out).
			$minYear = [];
			if( $pWithYears ) {
				$parts = '{ ?w wdt:P179 ?series } UNION { ?w wdt:P179 ?sub . ?sub wdt:P179 ?series }';
				$years = [];
				foreach( self::wikidataSparql( 'SELECT ?w ?year WHERE { VALUES ?series { '.$values.' } '.$parts.' OPTIONAL { ?w wdt:P577 ?d . BIND( YEAR( ?d ) AS ?year ) } }' ) ?? [] as $row ) {
					if( isset( $row['year']['value'] ) && preg_match( '#/(Q\d+)$#', $row['w']['value'], $m ) ) {
						$years[$m[1]] = min( (int)$row['year']['value'], $years[$m[1]] ?? 9999 );
					}
				}
				foreach( self::wikidataSparql( 'SELECT DISTINCT ?w ?person WHERE { VALUES ?series { '.$values.' } '.$parts.' ?w wdt:P161|wdt:P57|wdt:P58|wdt:P162 ?person . ?person wdt:P31 wd:Q5 }' ) ?? [] as $row ) {
					if( preg_match( '#/(Q\d+)$#', $row['w']['value'], $w ) && preg_match( '#/(Q\d+)$#', $row['person']['value'], $p ) && isset( $years[$w[1]] ) ) {
						$minYear[$p[1]] = min( $years[$w[1]], $minYear[$p[1]] ?? 9999 );
					}
				}
			}
			$index = [];
			foreach( $people as $person ) {
				foreach( array_keys( $person['names'] ) as $name ) {
					if( $name !== '' ) {
						$index[$name][$person['qid']] = [ 'qid' => $person['qid'], 'label' => $person['label'], 'description' => $person['description'], 'year' => $minYear[$person['qid']] ?? null ];
					}
				}
			}
			$cache[$key] = array_map( 'array_values', $index );
			if( $index && function_exists( 'apcu_store' ) ) {
				apcu_store( $apcuKey, $cache[$key], 6 * 3600 );
			}
		}
		return $cache[$key];
	}

	/** One SPARQL query against Wikidata's endpoint; the bindings, or null if it cannot be asked. */
	private static function wikidataSparql( string $pQuery ): ?array {
		$context = stream_context_create( [ 'http' => [
			'method'  => 'POST',
			'header'  => self::userAgentHeader()."Accept: application/sparql-results+json\r\nContent-Type: application/x-www-form-urlencoded\r\n",
			'content' => http_build_query( [ 'query' => $pQuery ] ),
			'timeout' => 60,
		] ] );
		$json = self::fetchExternal( 'https://query.wikidata.org/sparql', $context );
		return $json === false ? null : ( json_decode( $json, true )['results']['bindings'] ?? [] );
	}

	/** Does a Wikidata item's description fit one of the credited jobs (director/writer/star/creator)? */
	public static function descriptionFitsRoles( string $pDescription, array $pRoles ): bool {
		$patterns = [ 'director' => '/director/i', 'writer' => '/writer|screenwriter|dramatist|playwright|novelist|author/i',
			'star' => '/actor|actress|performer|comedian|singer/i', 'narrator' => '/narrator|voice|broadcaster|presenter|actor|actress|journalist|naturalist|comedian/i', 'creator' => '/creator|producer|writer|screenwriter|director/i' ];
		foreach( $pRoles as $role ) {
			if( isset( $patterns[$role] ) && preg_match( $patterns[$role], $pDescription ) ) {
				return true;
			}
		}
		return false;
	}

	public static function searchWikidataByName( string $pName, array $pRoles = [] ): array {
		$context = stream_context_create( [ 'http' => [ 'header' => self::userAgentHeader(), 'timeout' => 15 ] ] );
		$json = self::fetchExternal( 'https://www.wikidata.org/w/api.php?'.http_build_query( [
			'action' => 'wbsearchentities', 'search' => $pName, 'language' => 'en', 'uselang' => 'en', 'type' => 'item', 'limit' => 8, 'format' => 'json' ] ), $context );
		if( $json === false ) {
			return [];
		}
		$want = self::normaliseName( $pName );
		$found = [];
		foreach( json_decode( $json, true )['search'] ?? [] as $hit ) {
			$description = (string)( $hit['description'] ?? '' );
			$matched = self::normaliseName( (string)( $hit['label'] ?? '' ) ) === $want
				|| self::normaliseName( (string)( $hit['match']['text'] ?? '' ) ) === $want;
			if( !$matched || empty( $hit['id'] ) || stripos( $description, 'disambiguation' ) !== false ) {
				continue;
			}
			// Does the description fit the job they are credited with (a director credit prefers "television director")?
			$fit = self::descriptionFitsRoles( $description, $pRoles );
			$found[] = [ 'qid' => $hit['id'], 'label' => (string)( $hit['label'] ?? $hit['id'] ), 'description' => $description, 'fit' => $fit,
				'likely' => (bool)preg_match( '/actor|actress|director|producer|writer|screenwriter|dramatist|playwright|television|film|comedian|presenter|novelist|cinematograph|editor|composer|journalist/i', $description ) ];
		}
		usort( $found, fn( $a, $b ) => [ $b['fit'], $b['likely'] ] <=> [ $a['fit'], $a['likely'] ] );
		return $found;
	}

	/**
	 * A contact from a credited name alone - nothing external known (TMDb has no record, Wikidata none chosen). Tagged from the credit roles
	 * (director -> WP02, writer -> WP07, star -> WP01). Its identity is its name; a later Reload from Wikidata still works once a Wikidata id is added.
	 *
	 * @param string[] $pRoles  the credit roles (director/writer/star)
	 * @return array{content:object}|array{error:string}
	 */
	public static function createNameOnly( string $pName, array $pRoles ): array {
		$gContent = new ContactWikiIndividual();
		$parts = explode( ' ', trim( $pName ) );
		$surname = array_pop( $parts ) ?: '';
		$codes = [ 'star' => 'WP01', 'director' => 'WP02', 'writer' => 'WP07' ];
		$storeHash = [ 'forename' => implode( ' ', $parts ), 'surname' => $surname, 'fContactTypesSubmitted' => 1,
			'contact_types' => array_values( array_unique( array_filter( array_map( fn( $r ) => $codes[$r] ?? null, $pRoles ) ) ) ) ];
		if( !$gContent->store( $storeHash ) ) {
			return [ 'error' => implode( '; ', $gContent->mErrors ) ];
		}
		return [ 'content' => $gContent ];
	}

	public static function findContactByTmdbId( string $pTmdbId ): ?array {
		global $gBitDb;
		$pTmdbId = trim( $pTmdbId );
		if( $found = self::findWikiContactByXref( 'tmdb', $pTmdbId ) ) {
			return $found;
		}
		// An alias: TMDb often holds several records for one person; the extra ids are kept on the contact's
		// own tmdb xref ({"also":["123"]}).
		if( !ctype_digit( $pTmdbId ) ) {
			return null;
		}
		$row = $gBitDb->getRow(
			"SELECT lc.content_id, lc.title, lc.content_type_guid FROM `".BIT_DB_PREFIX."liberty_xref` x
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.content_id = x.content_id
			 WHERE x.item = 'tmdb' AND x.end_date IS NULL AND x.data LIKE ?
			 AND lc.content_type_guid IN ( 'contactwikiindi', 'contactwikigroup' )",
			[ '%"'.$pTmdbId.'"%' ]
		);
		return $row ? [ 'content_id' => (int)$row['content_id'], 'title' => $row['title'], 'content_type_guid' => $row['content_type_guid'] ] : null;
	}

	/**
	 * Record further TMDb person ids that are the same person as this contact's own `tmdb` id (TMDb keeps
	 * duplicate records: the same writer under two ids). Kept on the tmdb xref's data as {"also":["id",...]}
	 * and found by findContactByTmdbId(). Existing ids are not repeated.
	 *
	 * @param int[]|string[] $pIds
	 * @return int  ids added
	 */
	public function addTmdbAliases( array $pIds ): int {
		global $gBitDb;
		$row = $gBitDb->getRow(
			"SELECT `xref_id`, `xkey_ext`, `data` FROM `".BIT_DB_PREFIX."liberty_xref`
			 WHERE `content_id` = ? AND `item` = 'tmdb' AND `end_date` IS NULL",
			[ $this->mContentId ]
		);
		if( !$row ) {
			return 0;
		}
		$data = !empty( $row['data'] ) ? ( json_decode( $row['data'], true ) ?: [] ) : [];
		$also = array_map( 'strval', $data['also'] ?? [] );
		$added = 0;
		foreach( $pIds as $id ) {
			$id = (string)(int)$id;
			if( $id !== '0' && $id !== (string)$row['xkey_ext'] && !in_array( $id, $also, true ) ) {
				$also[] = $id;
				$added++;
			}
		}
		if( $added ) {
			$data['also'] = $also;
			$hash = [ 'xref_id' => (int)$row['xref_id'], 'content_id' => $this->mContentId, 'item' => 'tmdb',
				'xkey_ext' => $row['xkey_ext'], 'edit' => json_encode( $data ) ];
			$this->storeXref( $hash );
		}
		return $added;
	}

	/**
	 * Create an individual from a TMDb person when Wikidata has no item for them - the film/TV
	 * counterpart of createFromMusicBrainz(). Identity is the `tmdb` xref; a later Reload from Wikidata
	 * upgrades it once an item appears (and the TMDb id on that item matches).
	 *
	 * @return array{content:object}|array{error:string}
	 */
	public static function createFromTmdb( int $pTmdbId ): array {
		$person = self::fetchTmdbPerson( $pTmdbId );
		if( !$person ) {
			return [ 'error' => KernelTools::tra( 'Could not fetch that TMDb person.' ).' ('.$pTmdbId.')'.( self::getLastFetchError() ? ' - '.self::getLastFetchError() : '' ) ];
		}
		$gContent = new ContactWikiIndividual();
		$parts = explode( ' ', $person['name'] );
		$surname = array_pop( $parts ) ?: '';
		$codes = [ 'Acting' => 'WP01', 'Directing' => 'WP02', 'Writing' => 'WP07' ];
		$storeHash = [ 'forename' => implode( ' ', $parts ), 'surname' => $surname, 'fContactTypesSubmitted' => 1,
			'contact_types' => isset( $codes[$person['known_for']] ) ? [ $codes[$person['known_for']] ] : [] ];
		if( !$gContent->store( $storeHash ) ) {
			return [ 'error' => implode( '; ', $gContent->mErrors ) ];
		}
		$gContent->applyTmdbData( $person );
		return [ 'content' => $gContent ];
	}

	/**
	 * The xrefs a TMDb person gives: its own id, IMDb id, life dates (this type's dob/dod), the
	 * biography text and the profile photo. upsertXref(), so a repeat is an update.
	 *
	 * @return list<string>  human-readable lines of what was applied
	 */
	public function applyTmdbData( array $pPerson ): array {
		$items = [];
		$this->upsertXref( $this->mContentId, 'tmdb', [ 'xkey_ext' => (string)$pPerson['id'] ] );
		$items[] = 'tmdb: '.$pPerson['id'];
		if( !empty( $pPerson['imdb_id'] ) && isset( static::EXTERNAL_ID_PROPS['imdb'] ) ) {
			$this->upsertXref( $this->mContentId, 'imdb', [ 'xkey_ext' => $pPerson['imdb_id'] ] );
			$items[] = 'imdb: '.$pPerson['imdb_id'];
		}
		$dateItems = array_keys( $this->biographyDateProps() );
		foreach( [ $dateItems[0] => $pPerson['birthday'] ?? null, $dateItems[1] => $pPerson['deathday'] ?? null ] as $item => $value ) {
			if( !empty( $value ) ) {
				$this->upsertXref( $this->mContentId, $item, [ 'xkey_ext' => $value ] );
				$items[] = $item.': '.$value;
			}
		}
		if( !empty( $pPerson['biography'] ) ) {
			$bioHash = [ 'content_id' => $this->mContentId, 'title' => $this->getTitle(), 'edit' => self::plainTextToHtmlParagraphs( $pPerson['biography'] ) ];
			\Bitweaver\Liberty\LibertyContent::store( $bioHash );
			$items[] = KernelTools::tra( 'Biography' ).' (TMDb)';
		}
		if( !empty( $pPerson['profile_path'] ) ) {
			$context = stream_context_create( [ 'http' => [ 'header' => self::userAgentHeader(), 'timeout' => 20 ] ] );
			$image = self::fetchExternal( 'https://image.tmdb.org/t/p/w185'.$pPerson['profile_path'], $context );
			if( $image !== false && $image !== '' ) {
				$imagesDir = $this->getExtraImagePath( '' );
				KernelTools::mkdir_p( $imagesDir );
				if( file_put_contents( $imagesDir.'tmdb.jpg', $image ) !== false ) {
					$this->upsertXref( $this->mContentId, 'image', [ 'xkey_ext' => 'tmdb.jpg' ] );
					$this->thumbnailIfNone( 'tmdb.jpg' );
					$items[] = KernelTools::tra( 'Image' ).' (TMDb)';
				}
			}
		}
		return $items;
	}

	public static function findContactByMusicBrainzId( string $pMbid ): ?array {
		return self::findWikiContactByXref( 'musicbrainz', strtolower( $pMbid ) );
	}

	public static function findContactByWikidataQid( string $pQid ): ?array {
		return self::findWikiContactByXref( 'wikidata', strtoupper( $pQid ) );
	}

	/**
	 * A Wikidata entity's display name: English first, then 'mul' (the language-independent label
	 * Wikidata now gives many people and bands instead of an English one - Busta Rhymes, Megadeth),
	 * then the other English variants, then whatever label it has. '' only when it has none.
	 *
	 * @param array $pEntity  entity JSON (fetchWikidataEntity())
	 * @return string
	 */
	public static function entityLabel( array $pEntity ): string {
		$labels = $pEntity['labels'] ?? [];
		foreach( [ 'en', 'mul', 'en-gb', 'en-us', 'en-ca' ] as $lang ) {
			if( !empty( $labels[$lang]['value'] ) && trim( $labels[$lang]['value'] ) !== '' ) {
				return trim( $labels[$lang]['value'] );
			}
		}
		foreach( $labels as $label ) {
			if( trim( $label['value'] ?? '' ) !== '' ) {
				return trim( $label['value'] );
			}
		}
		return '';
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
	public static function createFromWikidata( string $pQid, ?bool $pIsGroup = null, array $pExtraTypes = [] ): array {
		$entity = self::fetchWikidataEntity( $pQid );
		if( !$entity ) {
			return [ 'error' => KernelTools::tra( 'Could not fetch that Wikidata entity.' ).' ('.$pQid.')' ];
		}
		// A merged item arrives under its new id: the contact is made for (or found by) that one.
		if( !empty( $entity['id'] ) && (string)$entity['id'] !== $pQid ) {
			$pQid = (string)$entity['id'];
			if( $held = self::findContactByWikidataQid( $pQid ) ) {
				$existing = new static( null, (int)$held['content_id'] );
				$existing->load();
				return [ 'content' => $existing ];
			}
		}
		$label = self::entityLabel( $entity );
		if( $label === '' ) {
			return [ 'error' => KernelTools::tra( 'That Wikidata item has no name in any language.' ).' ('.$pQid.')' ];
		}
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
		$storeHash['contact_types'] = array_values( array_unique( array_merge( $storeHash['contact_types'], $pExtraTypes ) ) );
		// The Wikipedia text goes in with the contact's first save, rather than a second full save of the contact afterwards.
		$t = microtime( true );
		$wikiTitle = self::wikipediaTitle( $entity );
		$bio = $wikiTitle !== null && ( !self::$skipBiography || WikimediaCache::getSummary( $wikiTitle ) !== null ) ? self::fetchWikipediaSummary( $wikiTitle ) : null;
		if( $bio !== null ) {
			$storeHash['edit'] = self::plainTextToHtmlParagraphs( $bio );
		}
		$t = self::stepDone( 'biography', $t );
		if( !$gContent->store( $storeHash ) ) {
			return [ 'error' => implode( '; ', $gContent->mErrors ) ];
		}
		self::stepDone( 'contact store', $t );
		$gContent->reloadFromWikidata( $pQid, $bio !== null || self::$skipBiography );
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
			// MusicBrainz can leave an artist's type unset (null) - not a valid array key.
			$mbType = (string)( $mb['type'] ?? '' );
			$contactTypes = isset( ContactWikiGroup::MUSICBRAINZ_TYPE_MAP[$mbType] ) ? [ ContactWikiGroup::MUSICBRAINZ_TYPE_MAP[$mbType] ] : [];
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


	/**
	 * Fill contact list rows for wiki individuals/groups with what their list summary shows - type
	 * names (Composer, Orchestra...), biography dates, Wikidata qid, linked music gallery - in a few
	 * bulk reads for the whole page, not per row. Rows of any other content type are left untouched.
	 * Registered as contact's 'contact_list_row_function' (list_contacts.php's Information column,
	 * rendered with list_summary_inc.tpl) and used directly by list_wiki.php for its columns.
	 *
	 * @param array &$pRows  getList() result rows (content_id, content_type_guid, ...)
	 */
	public static function enrichListRows( array &$pRows ): void {
		global $gBitDb;
		$wikiGuids = [ CONTACTWIKIINDIVIDUAL_CONTENT_TYPE_GUID, CONTACTWIKIGROUP_CONTENT_TYPE_GUID ];
		$ids = [];
		foreach( $pRows as $row ) {
			if( in_array( $row['content_type_guid'] ?? '', $wikiGuids, true ) ) {
				$ids[] = (int)$row['content_id'];
			}
		}
		if( !$ids ) {
			return;
		}
		// code => name for both wiki classes' type tags
		$typeNames = [];
		foreach( $wikiGuids as $guid ) {
			foreach( ( new \Bitweaver\Liberty\LibertyXrefType( $guid ) )->getTypeMarkers() as $marker ) {
				$typeNames[$marker['item']] = $marker['name'];
			}
		}
		$items = array_merge( [ 'dob', 'dod', 'formed', 'disbanded', 'wikidata', 'musicbrainz', 'music_gallery' ], array_keys( $typeNames ) );
		$rows = $gBitDb->getAll(
			"SELECT `content_id`, `item`, `xref`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref`
			 WHERE `end_date` IS NULL AND `content_id` IN (".implode( ',', array_fill( 0, count( $ids ), '?' ) ).")
			 AND `item` IN (".implode( ',', array_fill( 0, count( $items ), '?' ) ).") ORDER BY `item`",
			array_merge( $ids, $items )
		);
		$byContent = [];
		foreach( $rows as $x ) {
			$byContent[$x['content_id']][] = $x;
		}
		foreach( $pRows as &$row ) {
			if( !in_array( $row['content_type_guid'] ?? '', $wikiGuids, true ) ) {
				continue;
			}
			$isGroup = $row['content_type_guid'] === CONTACTWIKIGROUP_CONTENT_TYPE_GUID;
			$summary = [ 'is_group' => $isGroup, 'types' => [], 'date_from' => null, 'date_to' => null, 'qid' => null, 'mbid' => null, 'gallery_url' => null ];
			foreach( $byContent[$row['content_id']] ?? [] as $x ) {
				switch( $x['item'] ) {
					case 'dob': case 'formed':        $summary['date_from'] = $x['xkey_ext']; break;
					case 'dod': case 'disbanded':     $summary['date_to'] = $x['xkey_ext']; break;
					case 'wikidata':                  $summary['qid'] = $x['xkey_ext']; break;
					case 'musicbrainz':               $summary['mbid'] = $x['xkey_ext']; break;
					case 'music_gallery':
						if( !empty( $x['xref'] ) ) {
							$summary['gallery_url'] = BIT_ROOT_URL.'index.php?content_id='.(int)$x['xref'];
						}
						break;
					default:
						// Once each - a type tag stored twice (before creation de-duplicated them)
						// shouldn't read "Orchestra, Orchestra".
						if( isset( $typeNames[$x['item']] ) && !in_array( $typeNames[$x['item']], $summary['types'], true ) ) {
							$summary['types'][] = $typeNames[$x['item']];
						}
				}
			}
			$row['wiki_summary'] = $summary;
			$row['summary_tpl'] = 'bitpackage:contactwiki/list_summary_inc.tpl';
			$row['view_url'] = CONTACTWIKI_PKG_URL.'view.php?content_id='.$row['content_id'];
		}
		unset( $row );
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

	/** @var string|null reason the last fetchExternal() call failed, see getLastFetchError() */
	protected static ?string $lastFetchError = null;

	/**
	 * GET (or POST, per the context) an external API URL - Wikidata/Wikipedia/Commons/MusicBrainz -
	 * retrying a throttled response. A bulk run (the people pass creating 20 contacts back to back)
	 * can hit Wikimedia's rate limits: a 429/503 gets ONE retry after a short wait (the server's
	 * Retry-After, capped at 5s, else 2s) - a safety net, not a long stall; small batches with a gap
	 * between people are what actually keep clear of throttling. Returns the body, or false on any
	 * other failure - same contract
	 * as the bare file_get_contents() calls this replaced.
	 *
	 * @param string $pUrl
	 * @param resource $pContext  stream context (headers, method, timeout)
	 * @return string|false
	 */
	protected static function fetchExternal( string $pUrl, $pContext ) {
		stream_context_set_option( $pContext, 'http', 'ignore_errors', true );
		self::$lastFetchError = null;
		$host = parse_url( $pUrl, PHP_URL_HOST );
		for( $attempt = 0; $attempt < 2; $attempt++ ) {
			$started = microtime( true );
			// A failed request raises several warnings (getaddrinfo, SSL, timeout...) and the last is
			// the least useful ("operation failed") - keep them all for the reason.
			$warnings = [];
			set_error_handler( function( $no, $msg ) use ( &$warnings ) {
				$warnings[] = preg_replace( '/^file_get_contents\([^)]*\):\s*/', '', $msg );
				return true;
			} );
			$body = file_get_contents( $pUrl, false, $pContext );
			restore_error_handler();
			$status = 0;
			$retryAfter = 0;
			$headers = function_exists( 'http_get_last_response_headers' ) ? ( http_get_last_response_headers() ?? [] ) : ( $http_response_header ?? [] );
			foreach( $headers as $header ) {
				if( preg_match( '#^HTTP/\S+\s+(\d{3})#', $header, $m ) ) {
					$status = (int)$m[1];
				} elseif( preg_match( '/^Retry-After:\s*(\d+)/i', $header, $m ) ) {
					$retryAfter = (int)$m[1];
				}
			}
			if( $body !== false && $status >= 200 && $status < 300 ) {
				return $body;
			}
			if( $status === 0 ) {
				// No HTTP response at all - timeout, DNS or connection failure.
				self::$lastFetchError = sprintf( '%s: no response after %.0fs (%s)', $host, microtime( true ) - $started,
					$warnings ? implode( '; ', array_unique( $warnings ) ) : 'unknown network error' );
				return false;
			}
			if( !in_array( $status, [ 429, 503 ], true ) ) {
				// The service answered with an error - its own text says why (e.g. a query timeout).
				$text = trim( preg_replace( '/\s+/', ' ', strip_tags( (string)$body ) ) );
				self::$lastFetchError = "$host: HTTP $status".( $text !== '' ? ' - '.mb_substr( $text, 0, 160 ) : '' );
				return false;
			}
			self::$lastFetchError = "$host: HTTP $status - throttled, still refused after one retry"
				.( $retryAfter ? " (asked to wait {$retryAfter}s)" : '' ).'; try again in a minute';
			if( $attempt === 0 ) {
				sleep( min( $retryAfter ?: 2, 5 ) );
			}
		}
		return false;
	}

	/**
	 * Why the last fetchExternal() call failed - timeout/network, the service's own HTTP error, or
	 * throttling - for showing beside a "lookup failed" message. Null after a successful call.
	 *
	 * @return string|null
	 */
	public static function getLastFetchError(): ?string {
		return self::$lastFetchError;
	}

	public static function fetchWikidataEntity( string $pQid ): ?array {
		// Read the per-request cache first (prefetchWikidata(), or an earlier fetch of the same entity -
		// createFromWikidata() and reloadFromWikidata() both need it).
		if( WikimediaCache::hasEntity( $pQid ) ) {
			return WikimediaCache::getEntity( $pQid );
		}
		$context = stream_context_create( [ 'http' => [
			'header'  => self::userAgentHeader(),
			'timeout' => 15,
		] ] );
		$json = self::fetchExternal( "https://www.wikidata.org/wiki/Special:EntityData/$pQid.json", $context );
		if( $json === false ) {
			return null;
		}
		$data = json_decode( $json, true );
		$entity = $data['entities'][$pQid] ?? null;
		if( !$entity && count( $data['entities'] ?? [] ) === 1 ) {
			// A merged item: Wikidata redirects it and answers with the item it became, keyed by the new id (the stale id is
			// still what TMDb's external ids and old links hold). The entity's own 'id' is the one to use from here on.
			$entity = reset( $data['entities'] );
		}
		if( $entity ) {
			WikimediaCache::putEntity( $pQid, $entity );
			if( !empty( $entity['id'] ) ) {
				WikimediaCache::putEntity( (string)$entity['id'], $entity );
			}
		}
		return $entity;
	}

	/**
	 * Fetch, concurrently, everything creating contacts for these Wikidata items will want - their entities first,
	 * then each one's English Wikipedia summary and Commons photo together - into WikimediaCache, so the normal
	 * create path (fetchWikidataEntity()/fetchWikipediaSummary()/downloadCommonsFile()) finds it all ready instead of
	 * fetching one request at a time. At most WikimediaCache::CONCURRENCY requests are in flight. Anything that
	 * fails is just not cached, and the normal path fetches it the old way.
	 *
	 * @param list<string> $pQids
	 * @return array{entities:int, summaries:int, images:int, seconds:float}  what was fetched
	 */
	public static function prefetchWikidata( array $pQids ): array {
		$started = microtime( true );
		$stats = [ 'entities' => 0, 'summaries' => 0, 'images' => 0, 'wanted' => 0, 'refused' => [], 'seconds' => 0.0 ];
		$userAgent = trim( preg_replace( '/^User-Agent:\s*/i', '', self::userAgentHeader() ) );
		$qids = array_values( array_unique( array_filter( $pQids, fn( $q ) => preg_match( '/^Q\d+$/', (string)$q ) && !WikimediaCache::hasEntity( $q ) ) ) );

		$requests = [];
		foreach( $qids as $qid ) {
			$requests[$qid] = "https://www.wikidata.org/wiki/Special:EntityData/$qid.json";
		}
		$stats['wanted'] += count( $requests );
		foreach( WikimediaCache::multiFetch( $requests, $userAgent ) as $qid => $result ) {
			if( $result['status'] !== 200 ) {
				$stats['refused'][$result['status']] = ( $stats['refused'][$result['status']] ?? 0 ) + 1;
			}
			$entities = $result['status'] === 200 ? ( json_decode( (string)$result['body'], true )['entities'] ?? [] ) : [];
			$entity = $entities[$qid] ?? ( count( $entities ) === 1 ? reset( $entities ) : null );   // a merged item answers under its new id
			if( $entity ) {
				WikimediaCache::putEntity( $qid, $entity );
				if( !empty( $entity['id'] ) ) {
					WikimediaCache::putEntity( (string)$entity['id'], $entity );
				}
				$stats['entities']++;
			}
		}

		// The summary and the photo of every entity in hand (including ones cached earlier in the request), together.
		$requests = [];
		foreach( array_values( array_unique( array_filter( $pQids, fn( $q ) => WikimediaCache::hasEntity( (string)$q ) ) ) ) as $qid ) {
			$entity = WikimediaCache::getEntity( $qid );
			if( !self::$skipBiography && ( $title = self::wikipediaTitle( $entity ) ) !== null && WikimediaCache::getSummary( $title ) === null ) {
				$requests['s:'.$title] = 'https://en.wikipedia.org/api/rest_v1/page/summary/'.rawurlencode( $title );
			}
			if( !self::$skipPhotos && ( $file = self::imageFilename( $entity ) ) !== null && WikimediaCache::getImage( $file ) === null ) {
				$requests['i:'.$file] = self::commonsPhotoUrl( $file );
			}
		}
		$stats['wanted'] += count( $requests );
		// Summaries and photos are separate Wikimedia services with their own limits: the photos (a thumbnail Commons may have to render
		// first) go fewer at a time.
		$summaryRequests = array_filter( $requests, fn( $k ) => $k[0] === 's', ARRAY_FILTER_USE_KEY );
		$photoRequests = array_diff_key( $requests, $summaryRequests );
		$results = WikimediaCache::multiFetch( $summaryRequests, $userAgent, WikimediaCache::CONCURRENCY, 25 )
			+ WikimediaCache::multiFetch( $photoRequests, $userAgent, 3, 25 );
		// A refusal (HTTP 429/503) is asked again after the pause the server named (up to 6 s), two at a time, rather than left
		// to be fetched one by one - and slower - while the contact is built.
		$throttled = array_keys( array_filter( $results, fn( $r ) => in_array( $r['status'], [ 429, 503 ], true ) ) );
		if( $throttled ) {
			$stats['retried'] = count( $throttled );
			$stats['wait'] = min( 6, max( 2, ...array_map( fn( $k ) => $results[$k]['retry_after'], $throttled ) ) );
			sleep( $stats['wait'] );
			$retry = WikimediaCache::multiFetch( array_intersect_key( $requests, array_flip( $throttled ) ), $userAgent, 2, 25 );
			$results = $retry + $results;
		}
		foreach( $results as $key => $result ) {
			if( $result['status'] !== 200 || $result['body'] === null || $result['body'] === '' ) {
				$label = ( $key[0] === 's' ? 'summary' : 'photo' ).' '.( $result['status'] ?: 'no response' );
				$stats['refused'][$label] = ( $stats['refused'][$label] ?? 0 ) + 1;
				continue;
			}
			if( $key[0] === 's' ) {
				$extract = trim( (string)( json_decode( $result['body'], true )['extract'] ?? '' ) );
				if( $extract !== '' ) {
					WikimediaCache::putSummary( substr( $key, 2 ), $extract );
					$stats['summaries']++;
				}
			} else {
				WikimediaCache::putImage( substr( $key, 2 ), $result['body'] );
				$stats['images']++;
			}
		}
		$stats['seconds'] = round( microtime( true ) - $started, 1 );
		return $stats;
	}

	/**
	 * The enwiki sitelink Wikidata's own entity carries (separate from claims entirely - see
	 * contactwiki/DEVELOPER.md's "Wikidata reload" section), title
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
		if( ( $cached = WikimediaCache::getSummary( $pTitle ) ) !== null ) {
			return $cached;
		}
		$context = stream_context_create( [ 'http' => [
			'header'  => self::userAgentHeader(),
			'timeout' => 15,
		] ] );
		$json = self::fetchExternal( 'https://en.wikipedia.org/api/rest_v1/page/summary/'.rawurlencode( $pTitle ), $context );
		if( $json === false ) {
			return null;
		}
		$data = json_decode( $json, true );
		$extract = trim( (string)( $data['extract'] ?? '' ) );
		if( $extract !== '' ) {
			WikimediaCache::putSummary( $pTitle, $extract );
		}
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
		$json = self::fetchExternal( "https://musicbrainz.org/ws/2/artist/$pMbArtistId?inc=url-rels&fmt=json", $context );
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
	/**
	 * Where a Commons photo is fetched from: Special:FilePath with a width, so Commons sends a resized copy rather than the
	 * original (press photos run to many megabytes - the first 645 stored averaged 1.9 MB, the largest was 171 MB). Width
	 * is the contactwiki_photo_width setting, default 1024; 'original' fetches the file as uploaded. Commons never upscales
	 * (a smaller image comes back unchanged) and renders SVG/TIFF as a raster, so the stored type can differ from the name
	 * (see imageExtensionOf()). Better quality can be loaded later from a contact's edit page.
	 */
	public static function commonsPhotoUrl( string $pFilename ): string {
		global $gBitSystem;
		$width = strtolower( trim( (string)$gBitSystem->getConfig( 'contactwiki_photo_width', '' ) ) );
		$url = 'https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode( $pFilename );
		if( $width === 'original' ) {
			return $url;
		}
		return $url.'?width='.( ctype_digit( $width ) && (int)$width >= 100 ? min( (int)$width, 4000 ) : 1024 );
	}

	/** The file extension for what an image file really is (Commons may re-render a TIFF or SVG as JPEG/PNG), or null if unknown. */
	public static function imageExtensionOf( string $pPath ): ?string {
		$info = @getimagesize( $pPath );
		return match( $info[2] ?? null ) {
			IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp', default => null,
		};
	}

	public static function downloadCommonsFile( string $pFilename, string $pDestPath ): bool {
		if( ( $cached = WikimediaCache::getImage( $pFilename ) ) !== null ) {
			return copy( $cached, $pDestPath );
		}
		$context = stream_context_create( [ 'http' => [
			'header'  => self::userAgentHeader(),
			'timeout' => 20,
			'follow_location' => 1,
		] ] );
		$bytes = self::fetchExternal( self::commonsPhotoUrl( $pFilename ), $context );
		if( $bytes === false || $bytes === '' ) {
			return false;
		}
		return (bool)file_put_contents( $pDestPath, $bytes );
	}
}
