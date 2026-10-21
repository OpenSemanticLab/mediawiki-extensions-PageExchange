<?php
use MediaWiki\MediaWikiServices;
use Wikimedia\AtEase\AtEase;
use Wikimedia\Rdbms\DBError;

class PXUtils {
	public static function getInstalledExtensions( $config ) {
		$installedExtensions = [];
		// Extensions loaded via wfLoadExtension().
		$registeredExtensions = ExtensionRegistry::getInstance()->getAllThings();
		foreach ( $registeredExtensions as $extName => $extData ) {
			// Make the names "space-insensitive".
			$extensionName = str_replace( ' ', '', $extName );
			$installedExtensions[] = $extensionName;
		}

		// For MW 1.35+, this only gets extensions that are loaded the
		// old way, via include_once() or require_once().
		$extensionCredits = $config->get( 'ExtensionCredits' );
		foreach ( $extensionCredits as $group => $exts ) {
			foreach ( $exts as $ext ) {
				// Make the names "space-insensitive".
				$extensionName = str_replace( ' ', '', $ext['name'] );
				$installedExtensions[] = $extensionName;
			}
		}
		return $installedExtensions;
	}

	public static function getInstalledPackages( $user ) {
		$installedPackages = [];
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$res = $dbr->select(
			'px_packages',
			[ 'pxp_id', 'pxp_name', 'pxp_package_data' ]
		);
		while ( $row = $res->fetchRow() ) {
			$installedPackages[] = PXInstalledPackage::newFromDB( $row, $user );
		}
		return $installedPackages;
	}

	public static function getWebPageContents( $url ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		try {
			$cacheTTL = $config->get( 'PageExchangeCacheTTL' );
		} catch ( Exception $e ) {
			$cacheTTL = 3600;
		}

		if ( $cacheTTL > 0 ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
			$cacheKey = $cache->makeKey( 'pageexchange', 'url', md5( $url ) );
			$cached = $cache->get( $cacheKey );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		$contents = self::fetchUrl( $url );

		// Only cache successful (non-empty) responses.
		if ( $cacheTTL > 0 && $contents !== '' && $contents !== false ) {
			$cache->set( $cacheKey, $contents, $cacheTTL );
		}

		return $contents;
	}

	public static function getCached( $url ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		try {
			$cacheTTL = $config->get( 'PageExchangeCacheTTL' );
		} catch ( Exception $e ) {
			$cacheTTL = 3600;
		}
		if ( $cacheTTL > 0 ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
			$cacheKey = $cache->makeKey( 'pageexchange', 'url', md5( $url ) );
			$cached = $cache->get( $cacheKey );
			if ( $cached !== false ) {
				return $cached;
			}
		}
		return null;
	}

	public static function cacheContent( $url, $content ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		try {
			$cacheTTL = $config->get( 'PageExchangeCacheTTL' );
		} catch ( Exception $e ) {
			$cacheTTL = 3600;
		}
		if ( $cacheTTL > 0 && $content !== '' && $content !== false ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
			$cacheKey = $cache->makeKey( 'pageexchange', 'url', md5( $url ) );
			$cache->set( $cacheKey, $content, $cacheTTL );
		}
	}

	private static function fetchUrl( $url ) {
		$gitHubToken = self::getGitHubToken( $url );

		// Use cURL, if it's installed - it seems to have a better
		// chance of working.
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
			curl_setopt( $ch, CURLOPT_URL, $url );
			curl_setopt( $ch, CURLOPT_USERAGENT, 'request' );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			if ( $gitHubToken !== '' ) {
				curl_setopt( $ch, CURLOPT_HTTPHEADER, [
					'Authorization: token ' . $gitHubToken
				] );
			}
			$contents = curl_exec( $ch );
			$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );
			if ( $httpCode !== 200 ) {
				return '';
			}
			return $contents;
		}

		// Fallback: file_get_contents.
		$context = null;
		if ( $gitHubToken !== '' ) {
			$context = stream_context_create( [
				'http' => [
					'header' => "Authorization: token " . $gitHubToken . "\r\n" .
						"User-Agent: request\r\n",
					'follow_location' => true
				]
			] );
		}

		AtEase::suppressWarnings();
		if ( $context !== null ) {
			$contents = file_get_contents( $url, false, $context );
		} else {
			$contents = file_get_contents( $url );
		}
		AtEase::restoreWarnings();

		return $contents;
	}

	public static function downloadGitHubRepoContents( $org, $repo, $branch ) {
		$zipUrl = "https://api.github.com/repos/$org/$repo/zipball/$branch";
		$zipData = self::fetchUrl( $zipUrl );
		if ( $zipData === '' || $zipData === false ) {
			return [];
		}

		$tempFile = tempnam( sys_get_temp_dir(), 'px_zip_' );
		file_put_contents( $tempFile, $zipData );

		$contents = [];
		$zip = new ZipArchive();
		if ( $zip->open( $tempFile ) === true ) {
			// GitHub zip structure: {org}-{repo}-{sha}/path/to/file
			// Find the prefix (first directory) to strip it.
			$prefix = '';
			if ( $zip->numFiles > 0 ) {
				$firstName = $zip->getNameIndex( 0 );
				$slashPos = strpos( $firstName, '/' );
				if ( $slashPos !== false ) {
					$prefix = substr( $firstName, 0, $slashPos + 1 );
				}
			}

			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				// Skip directories.
				if ( substr( $name, -1 ) === '/' ) {
					continue;
				}
				// Strip the prefix to get the relative path.
				$relativePath = $name;
				if ( $prefix !== '' && strpos( $name, $prefix ) === 0 ) {
					$relativePath = substr( $name, strlen( $prefix ) );
				}
				$fileContent = $zip->getFromIndex( $i );
				if ( $fileContent !== false ) {
					$contents[$relativePath] = $fileContent;
					// Pre-populate the cache using the raw.githubusercontent.com URL as key.
					$rawUrl = "https://raw.githubusercontent.com/$org/$repo/$branch/" .
						rawurlencode( $relativePath );
					self::cacheContent( $rawUrl, $fileContent );
				}
			}
			$zip->close();
		}

		unlink( $tempFile );
		return $contents;
	}

	public static function getWebPageContentsBatch( $urls ) {
		$results = [];
		if ( empty( $urls ) ) {
			return $results;
		}

		// Check cache first — only fetch uncached URLs.
		$uncachedUrls = [];
		foreach ( $urls as $url ) {
			$cached = self::getCached( $url );
			if ( $cached !== null ) {
				$results[$url] = $cached;
			} else {
				$uncachedUrls[] = $url;
			}
		}
		if ( empty( $uncachedUrls ) ) {
			return $results;
		}

		if ( function_exists( 'curl_multi_init' ) ) {
			$multiHandle = curl_multi_init();
			$handles = [];

			foreach ( $uncachedUrls as $url ) {
				$ch = curl_init();
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
				curl_setopt( $ch, CURLOPT_URL, $url );
				curl_setopt( $ch, CURLOPT_USERAGENT, 'request' );
				curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
				$gitHubToken = self::getGitHubToken( $url );
				if ( $gitHubToken !== '' ) {
					curl_setopt( $ch, CURLOPT_HTTPHEADER, [
						'Authorization: token ' . $gitHubToken
					] );
				}
				curl_multi_add_handle( $multiHandle, $ch );
				$handles[$url] = $ch;
			}

			// Execute all requests in parallel.
			$running = null;
			do {
				curl_multi_exec( $multiHandle, $running );
				if ( $running > 0 ) {
					curl_multi_select( $multiHandle );
				}
			} while ( $running > 0 );

			foreach ( $handles as $url => $ch ) {
				$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
				if ( $httpCode === 200 ) {
					$content = curl_multi_getcontent( $ch );
					$results[$url] = $content;
					self::cacheContent( $url, $content );
				} else {
					$results[$url] = '';
				}
				curl_multi_remove_handle( $multiHandle, $ch );
				curl_close( $ch );
			}
			curl_multi_close( $multiHandle );
		} else {
			// Fallback: sequential fetch.
			foreach ( $uncachedUrls as $url ) {
				$content = self::fetchUrl( $url );
				$results[$url] = $content;
				if ( $content !== '' && $content !== false ) {
					self::cacheContent( $url, $content );
				}
			}
		}

		return $results;
	}

	private static function getGitHubToken( $url ) {
		$host = parse_url( $url, PHP_URL_HOST );
		if ( $host === false || $host === null ) {
			return '';
		}
		$host = strtolower( $host );
		$gitHubHosts = [ 'github.com', 'api.github.com', 'raw.githubusercontent.com' ];
		if ( !in_array( $host, $gitHubHosts ) ) {
			return '';
		}

		$path = parse_url( $url, PHP_URL_PATH );
		if ( $path === false || $path === null ) {
			return '';
		}
		$segments = explode( '/', trim( $path, '/' ) );

		// api.github.com paths start with /repos/{org}/{repo}/...
		if ( $host === 'api.github.com' ) {
			if ( count( $segments ) < 3 || $segments[0] !== 'repos' ) {
				return '';
			}
			$org = $segments[1];
			$repo = $segments[2];
		} else {
			// github.com and raw.githubusercontent.com: /{org}/{repo}/...
			if ( count( $segments ) < 2 ) {
				return '';
			}
			$org = $segments[0];
			$repo = $segments[1];
		}

		$config = MediaWikiServices::getInstance()->getMainConfig();
		try {
			$tokens = $config->get( 'PageExchangeGitHubAccessToken' );
		} catch ( Exception $e ) {
			$tokens = [];
		}
		if ( !is_array( $tokens ) || empty( $tokens ) ) {
			return '';
		}

		// Repo-specific key takes priority over org-only key.
		$repoKey = $org . '/' . $repo;
		if ( isset( $tokens[$repoKey] ) ) {
			return $tokens[$repoKey];
		}
		if ( isset( $tokens[$org] ) ) {
			return $tokens[$org];
		}

		return '';
	}

	public static function readFileDirectory( $fileDirectoryURL ) {
		$packageFiles = [];
		$fileDirectoryContents = self::getWebPageContents( $fileDirectoryURL );
		$fileDirectoryLines = explode( "\n", $fileDirectoryContents );
		foreach ( $fileDirectoryLines as $fileDirectoryLine ) {
			// Allow blank lines, and comments.
			if ( $fileDirectoryLine == '' ) {
				continue;
			}
			$firstChar = $fileDirectoryLine[0];
			if ( in_array( $firstChar, [ ';', '#', '/' ] ) ) {
				continue;
			}
			$packageFiles[] = $fileDirectoryLine;
		}
		return $packageFiles;
	}

	/**
	 * Extracts the repository URL and branch from a raw.githubusercontent.com URL.
	 * Handles both /<owner>/<repo>/<branch>/... and /<owner>/<repo>/refs/heads/<branch>/...
	 * Branch names that contain "/" are not parsed correctly in the first form.
	 *
	 * @param string|null $url
	 * @return array|null [ 'repository' => ..., 'branch' => ... ] or null
	 */
	public static function parseRepositoryURL( $url ) {
		if ( !is_string( $url ) || $url === '' ) {
			return null;
		}
		$parts = parse_url( $url );
		if ( !is_array( $parts ) || !isset( $parts['host'] ) || !isset( $parts['path'] ) ||
			strtolower( $parts['host'] ) !== 'raw.githubusercontent.com'
		) {
			return null;
		}
		$segments = array_values( array_filter( explode( '/', $parts['path'] ), static function ( $s ) {
			return $s !== '';
		} ) );
		if ( count( $segments ) < 3 ) {
			return null;
		}
		$branch = $segments[2];
		if ( $segments[2] === 'refs' && isset( $segments[3] ) && $segments[3] === 'heads' && isset( $segments[4] ) ) {
			$branch = $segments[4];
		}
		return [
			'repository' => "https://github.com/{$segments[0]}/{$segments[1]}",
			'branch' => rawurldecode( $branch )
		];
	}

	/**
	 * Counts the page creation jobs of Page Exchange per package (globalID).
	 *
	 * Reads the job table directly, so it works only if the queue of the job type is a
	 * JobQueueDB. One wiki per job table is assumed. Job states:
	 * - queued: not claimed yet
	 * - claimed: claimed and either running or failed and waiting to be released for a retry
	 *   (a claim is released after claimTTL if attempts are left)
	 * - failed: claimed, all attempts used and the claim is older than claimTTL
	 * Jobs without a package_id parameter (queued before it existed) are counted as unattributed.
	 *
	 * @return array|null [ 'packages' => [ globalID => [ 'queued' => n, 'claimed' => n, 'failed' => n ] ],
	 *  'unattributed' => n ], or null if the job state cannot be determined
	 */
	public static function getPackageJobCounts() {
		$services = MediaWikiServices::getInstance();
		$jobType = 'pageExchangeCreatePage';
		try {
			$queue = $services->getJobQueueGroup()->get( $jobType );
			if ( !( $queue instanceof JobQueueDB ) ) {
				return null;
			}

			$typeConf = $services->getMainConfig()->get( 'JobTypeConf' );
			$conf = $typeConf[$jobType] ?? $typeConf['default'] ?? [];
			// Same defaults as JobQueue and JobQueueDB. With a claimTTL of 0 claims are never released.
			$claimTTL = $conf['claimTTL'] ?? 0;
			$maxTries = $conf['maxTries'] ?? 3;
			$staleCutoff = (int)wfTimestamp( TS_UNIX ) - $claimTTL;

			// The queue may use its own database (e.g. a separate file on SQLite), so use the
			// primary handle of the queue itself (no replica lag). JobQueueDB has no public accessor.
			$getPrimaryDB = new ReflectionMethod( JobQueueDB::class, 'getPrimaryDB' );
			if ( PHP_VERSION_ID < 80100 ) {
				$getPrimaryDB->setAccessible( true );
			}
			$dbr = $getPrimaryDB->invoke( $queue );
			$res = $dbr->newSelectQueryBuilder()
				->select( [ 'job_params', 'job_token', 'job_token_timestamp', 'job_attempts' ] )
				->from( 'job' )
				->where( [ 'job_cmd' => $jobType ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			$packages = [];
			$unattributed = 0;
			foreach ( $res as $row ) {
				$params = unserialize( $row->job_params, [ 'allowed_classes' => false ] );
				$packageID = is_array( $params ) && isset( $params['package_id'] ) ? (string)$params['package_id'] : null;
				if ( $row->job_token === '' ) {
					$state = 'queued';
				} elseif (
					(int)$row->job_attempts >= $maxTries &&
					( $claimTTL <= 0 || (int)wfTimestamp( TS_UNIX, $row->job_token_timestamp ) < $staleCutoff )
				) {
					$state = 'failed';
				} else {
					$state = 'claimed';
				}
				if ( $packageID === null ) {
					if ( $state !== 'failed' ) {
						$unattributed++;
					}
					continue;
				}
				if ( !isset( $packages[$packageID] ) ) {
					$packages[$packageID] = [ 'queued' => 0, 'claimed' => 0, 'failed' => 0 ];
				}
				$packages[$packageID][$state]++;
			}
		} catch ( ReflectionException | JobQueueError | DBError $e ) {
			wfDebugLog( 'PageExchange', 'Could not read the job queue state: ' . $e->getMessage() );
			return null;
		}
		return [ 'packages' => $packages, 'unattributed' => $unattributed ];
	}
}
