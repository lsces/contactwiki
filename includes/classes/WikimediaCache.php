<?php
/**
 * Per-request cache and parallel fetcher for the Wikimedia records a wiki contact is built from: its Wikidata
 * entity, the English Wikipedia summary and the Commons photo. Creating a contact needs all three, one after
 * another, and the same entity used to be fetched twice; a people pass creating 20 contacts spent most of its time
 * waiting on those requests. ContactWikiTrait::prefetchWikidata() fills this cache for a whole batch at once (a few
 * requests in flight, never more than CONCURRENCY), and the trait's plain fetch functions read it first - so the
 * existing create code is unchanged, finds everything ready, and anything the prefetch could not get is simply
 * fetched the old, sequential way (with its own rate-limit retry).
 *
 * Only successful fetches are cached, so a miss is retried by the normal path rather than remembered as absent.
 * Photos are kept as temp files, removed at the end of the request.
 *
 * @package contactwiki
 */

namespace Bitweaver\Contactwiki;

class WikimediaCache {

	/** Requests in flight at once - a polite number for Wikimedia's servers. */
	public const CONCURRENCY = 5;

	/** @var array<string,array> */
	private static array $entities = [];
	/** @var array<string,string> */
	private static array $summaries = [];
	/** @var array<string,string> filename => temp file path */
	private static array $images = [];
	private static bool $shutdownRegistered = false;

	public static function hasEntity( string $pQid ): bool {
		return isset( self::$entities[$pQid] );
	}

	public static function getEntity( string $pQid ): ?array {
		return self::$entities[$pQid] ?? null;
	}

	public static function putEntity( string $pQid, array $pEntity ): void {
		self::$entities[$pQid] = $pEntity;
	}

	public static function getSummary( string $pTitle ): ?string {
		return self::$summaries[$pTitle] ?? null;
	}

	public static function putSummary( string $pTitle, string $pText ): void {
		self::$summaries[$pTitle] = $pText;
	}

	/** The cached photo's temp file, or null. */
	public static function getImage( string $pFilename ): ?string {
		$path = self::$images[$pFilename] ?? null;
		return $path !== null && is_file( $path ) ? $path : null;
	}

	public static function putImage( string $pFilename, string $pBytes ): void {
		$path = tempnam( sys_get_temp_dir(), 'cwimg_' );
		if( $path === false || file_put_contents( $path, $pBytes ) === false ) {
			return;
		}
		self::$images[$pFilename] = $path;
		if( !self::$shutdownRegistered ) {
			self::$shutdownRegistered = true;
			register_shutdown_function( function() {
				foreach( self::$images as $path ) {
					@unlink( $path );
				}
			} );
		}
	}

	/**
	 * Fetch several URLs concurrently, a sliding window of $pConcurrency at a time.
	 *
	 * @param array<string,string> $pRequests  key => url
	 * @param string $pUserAgent               the User-Agent value (Wikimedia asks clients to identify themselves)
	 * @return array<string,array{status:int, body:?string}>  key => result; a transport failure has status 0 and a null body
	 */
	public static function multiFetch( array $pRequests, string $pUserAgent, int $pConcurrency = self::CONCURRENCY, int $pTimeout = 20 ): array {
		if( !$pRequests || !function_exists( 'curl_multi_init' ) ) {
			return [];
		}
		$results = [];
		$queue = array_keys( $pRequests );
		$active = [];
		$multi = curl_multi_init();
		$start = function( $pKey ) use ( &$active, $multi, $pRequests, $pUserAgent, $pTimeout ) {
			$handle = curl_init( $pRequests[$pKey] );
			curl_setopt_array( $handle, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_CONNECTTIMEOUT => 8,
				CURLOPT_TIMEOUT        => $pTimeout,
				CURLOPT_USERAGENT      => $pUserAgent,
				CURLOPT_ENCODING       => '',
				CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
			] );
			curl_multi_add_handle( $multi, $handle );
			$active[spl_object_id( $handle )] = [ $pKey, $handle ];
		};
		while( $queue && count( $active ) < $pConcurrency ) {
			$start( array_shift( $queue ) );
		}
		while( $active ) {
			curl_multi_exec( $multi, $running );
			while( $info = curl_multi_info_read( $multi ) ) {
				$handle = $info['handle'];
				[ $key ] = $active[spl_object_id( $handle )];
				$ok = $info['result'] === CURLE_OK;
				$results[$key] = [ 'status' => $ok ? (int)curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ) : 0,
					'body' => $ok ? (string)curl_multi_getcontent( $handle ) : null ];
				curl_multi_remove_handle( $multi, $handle );
				curl_close( $handle );
				unset( $active[spl_object_id( $handle )] );
				if( $queue ) {
					$start( array_shift( $queue ) );
				}
			}
			if( $active ) {
				curl_multi_select( $multi, 1.0 );
			}
		}
		curl_multi_close( $multi );
		return $results;
	}
}
