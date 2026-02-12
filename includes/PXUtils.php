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
		$gitHubToken = self::getGitHubToken( $url );

		// Use cURL, if it's installed - it seems to have a better
		// chance of working.
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
			curl_setopt( $ch, CURLOPT_URL, $url );
			curl_setopt( $ch, CURLOPT_USERAGENT, 'request' );
			if ( $gitHubToken !== '' ) {
				curl_setopt( $ch, CURLOPT_HTTPHEADER, [
					'Authorization: token ' . $gitHubToken
				] );
			}
			$contents = curl_exec( $ch );
			$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			if ( $httpCode !== 200 ) {
				// @todo - return/throw $contents['message']?
				// It may contain useful information.
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
						"User-Agent: request\r\n"
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
		$tokens = $config->get( 'PageExchangeGitHubAccessToken' );
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
