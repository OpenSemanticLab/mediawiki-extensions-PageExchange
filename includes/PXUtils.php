<?php
use MediaWiki\MediaWikiServices;
use Wikimedia\AtEase\AtEase;

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
		$dbr = wfGetDb( DB_REPLICA );
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
}
