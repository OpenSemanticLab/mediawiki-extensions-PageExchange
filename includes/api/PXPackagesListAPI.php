<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Query module "list=pxpackages": lists installed and/or available packages.
 *
 * Installed packages are read from the px_packages table only (no remote
 * requests). Available packages are loaded the same way as on Special:Packages.
 */
class PXPackagesListAPI extends ApiQueryBase {

	public function __construct( ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'pxp' );
	}

	public function execute() {
		$this->checkUserRightsAny( 'pageexchange' );

		$params = $this->extractRequestParams();
		$sources = $params['source'];
		$result = $this->getResult();
		$items = [];
		$installedIDs = [];

		$installedItems = $this->getInstalledItems();
		foreach ( $installedItems as $item ) {
			$installedIDs[] = $item['globalid'];
		}
		if ( in_array( 'installed', $sources ) ) {
			$items = array_merge( $items, $installedItems );
		}

		if ( in_array( 'available', $sources ) ) {
			$errors = [];
			$availableItems = $this->getAvailableItems( $installedIDs, $errors );
			$items = array_merge( $items, $availableItems );

			$config = $this->getConfig();
			$packageFiles = array_values( $config->get( 'PageExchangePackageFiles' ) );
			$fileDirectories = array_values( $config->get( 'PageExchangeFileDirectories' ) );
			ApiResult::setIndexedTagName( $packageFiles, 'file' );
			ApiResult::setIndexedTagName( $fileDirectories, 'directory' );
			$result->addValue( 'query', 'pxpackagesources', [
				'packagefiles' => $packageFiles,
				'filedirectories' => $fileDirectories
			] );
			foreach ( $errors as &$error ) {
				ApiResult::setSubelementsList( $error, [ 'url', 'error' ] );
			}
			unset( $error );
			ApiResult::setIndexedTagName( $errors, 'error' );
			$result->addValue( 'query', 'pxpackageerrors', $errors );
		}

		ApiResult::setIndexedTagName( $items, 'package' );
		$result->addValue( 'query', $this->getModuleName(), $items );
	}

	/**
	 * @return array[]
	 */
	private function getInstalledItems() {
		$dbr = $this->getDB();
		$res = $dbr->select(
			'px_packages',
			[
				'pxp_id', 'pxp_name', 'pxp_global_id', 'pxp_date_installed',
				'pxp_installing_user', 'pxp_package_data'
			],
			'',
			__METHOD__,
			[ 'ORDER BY' => 'pxp_id' ]
		);
		$userLookup = MediaWikiServices::getInstance()->getUserIdentityLookup();
		$items = [];
		foreach ( $res as $row ) {
			$dateInstalled = wfTimestamp( TS_ISO_8601, $row->pxp_date_installed );
			$installingUser = $userLookup->getUserIdentityByUserId( (int)$row->pxp_installing_user );

			$pending = $row->pxp_package_data === null;
			$invalidData = false;
			$version = null;
			$baseURL = null;
			$baseURLSource = null;
			$packageFileURL = null;
			if ( !$pending ) {
				$data = json_decode( $row->pxp_package_data );
				if ( !is_object( $data ) ) {
					$invalidData = true;
				} else {
					$version = isset( $data->version ) && is_scalar( $data->version ) ? (string)$data->version : null;
					if ( isset( $data->baseURL ) && is_string( $data->baseURL ) && $data->baseURL !== '' ) {
						$baseURL = $data->baseURL;
						$baseURLSource = 'stored';
					} else {
						$baseURL = self::deriveBaseURL( $data );
						$baseURLSource = $baseURL === null ? null : 'derived';
					}
					if ( isset( $data->packageFileURL ) && is_string( $data->packageFileURL ) ) {
						$packageFileURL = $data->packageFileURL;
					}
				}
			}
			$parsed = PXUtils::parseRepositoryURL( $baseURL ) ?? PXUtils::parseRepositoryURL( $packageFileURL );

			$items[] = [
				'source' => 'installed',
				'globalid' => $row->pxp_global_id,
				'name' => $row->pxp_name,
				'version' => $version,
				'dateinstalled' => $dateInstalled === false ? null : $dateInstalled,
				'installinguser' => $installingUser ? $installingUser->getName() : null,
				'pending' => $pending,
				'invaliddata' => $invalidData,
				'baseurl' => $baseURL,
				'baseurlsource' => $baseURLSource,
				'packagefileurl' => $packageFileURL,
				'repository' => $parsed['repository'] ?? null,
				'branch' => $parsed['branch'] ?? null
			];
		}
		return $items;
	}

	/**
	 * Longest common prefix of the stored page URLs, cut after the last "/".
	 * The result can be deeper than the original baseURL.
	 *
	 * @param stdClass $data
	 * @return string|null
	 */
	private static function deriveBaseURL( $data ) {
		if ( !isset( $data->pages ) || !is_array( $data->pages ) ) {
			return null;
		}
		$prefix = null;
		foreach ( $data->pages as $page ) {
			if ( !is_object( $page ) || !isset( $page->url ) || !is_string( $page->url ) ) {
				continue;
			}
			if ( $prefix === null ) {
				$prefix = $page->url;
				continue;
			}
			$len = min( strlen( $prefix ), strlen( $page->url ) );
			$i = 0;
			while ( $i < $len && $prefix[$i] === $page->url[$i] ) {
				$i++;
			}
			$prefix = substr( $prefix, 0, $i );
		}
		if ( $prefix === null ) {
			return null;
		}
		$pos = strrpos( $prefix, '/' );
		if ( $pos === false ) {
			return null;
		}
		return substr( $prefix, 0, $pos + 1 );
	}

	/**
	 * @param string[] $installedIDs
	 * @param array &$errors
	 * @return array[]
	 */
	private function getAvailableItems( array $installedIDs, array &$errors ) {
		$config = $this->getConfig();
		$installedExtensions = PXUtils::getInstalledExtensions( $config );
		$fileDirectories = $config->get( 'PageExchangeFileDirectories' );
		$packageFileURLs = $config->get( 'PageExchangePackageFiles' );

		// Prefetch phase 1: batch-fetch directory listings in parallel.
		if ( !empty( $fileDirectories ) ) {
			PXUtils::getWebPageContentsBatch( $fileDirectories );
		}

		// Parse directory listings (cache hit) and collect all package file URLs.
		$allPackageFileURLs = [];
		foreach ( $fileDirectories as $fileDirectoryURL ) {
			$allPackageFileURLs = array_merge(
				$allPackageFileURLs, PXUtils::readFileDirectory( $fileDirectoryURL )
			);
		}
		$allPackageFileURLs = array_merge( $allPackageFileURLs, $packageFileURLs );

		// Prefetch phase 2: batch-fetch all package JSON files in parallel.
		if ( !empty( $allPackageFileURLs ) ) {
			PXUtils::getWebPageContentsBatch( $allPackageFileURLs );
		}

		// Prefetch phase 3: batch-fetch GitHub tree URLs (main and master) for GitHub packages.
		$gitHubTreeURLs = [];
		foreach ( $allPackageFileURLs as $url ) {
			$json = PXUtils::getWebPageContents( $url );
			if ( $json === null || $json === '' ) {
				continue;
			}
			$data = json_decode( $json );
			if ( $data === null || !property_exists( $data, 'packages' ) ) {
				continue;
			}
			foreach ( $data->packages as $pkgData ) {
				if ( !property_exists( $pkgData, 'directoryStructure' ) ) {
					continue;
				}
				$ds = $pkgData->directoryStructure;
				if ( !property_exists( $ds, 'service' ) || $ds->service !== 'GitHub' ) {
					continue;
				}
				if ( !property_exists( $ds, 'accountName' ) || !property_exists( $ds, 'repositoryName' ) ) {
					continue;
				}
				$gitHubTreeURLs[] = "https://api.github.com/repos/{$ds->accountName}/{$ds->repositoryName}/git/trees/main?recursive=1";
				$gitHubTreeURLs[] = "https://api.github.com/repos/{$ds->accountName}/{$ds->repositoryName}/git/trees/master?recursive=1";
			}
		}
		if ( !empty( $gitHubTreeURLs ) ) {
			PXUtils::getWebPageContentsBatch( $gitHubTreeURLs );
		}

		$packageFiles = [];
		foreach ( $fileDirectories as $dirNum => $fileDirectoryURL ) {
			$curPackageFiles = PXUtils::readFileDirectory( $fileDirectoryURL );
			if ( count( $curPackageFiles ) === 0 ) {
				$errors[] = [
					'url' => $fileDirectoryURL,
					'error' => 'No package files found in this file directory'
				];
				continue;
			}
			foreach ( $curPackageFiles as $fileNum => $packageURL ) {
				try {
					$packageFiles[] = PXPackageFile::init(
						$packageURL, $dirNum + 1, $fileNum + 1, $installedExtensions, $installedIDs
					);
				} catch ( MWException $e ) {
					$errors[] = [ 'url' => $packageURL, 'error' => $e->getMessage() ];
				}
			}
		}
		foreach ( $packageFileURLs as $i => $url ) {
			try {
				$packageFiles[] = PXPackageFile::init( $url, null, $i + 1, $installedExtensions, $installedIDs );
			} catch ( MWException $e ) {
				$errors[] = [ 'url' => $url, 'error' => $e->getMessage() ];
			}
		}

		$items = [];
		foreach ( $packageFiles as $packageFile ) {
			try {
				$packages = $packageFile->getAllPackages( $this->getUser() );
			} catch ( MWException $e ) {
				$errors[] = [ 'url' => $packageFile->getURL(), 'error' => $e->getMessage() ];
				continue;
			}
			foreach ( $packages as $package ) {
				$baseURL = $package->getBaseURL();
				$packageFileURL = $package->getPackageFileURL();
				$parsed = PXUtils::parseRepositoryURL( $baseURL ) ?? PXUtils::parseRepositoryURL( $packageFileURL );
				$globalID = $package->getGlobalID();
				$packageData = $package->getPackageData();
				$items[] = [
					'source' => 'available',
					'globalid' => $globalID,
					'name' => $package->getName(),
					'version' => $packageData['version'],
					'baseurl' => $baseURL,
					'packagefileurl' => $packageFileURL,
					'repository' => $parsed['repository'] ?? null,
					'branch' => $parsed['branch'] ?? null,
					'installed' => $globalID !== null && in_array( $globalID, $installedIDs )
				];
			}
		}
		return $items;
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'source' => [
				ParamValidator::PARAM_TYPE => [ 'installed', 'available' ],
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_DEFAULT => 'installed',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=query&list=pxpackages&pxpsource=installed'
				=> 'apihelp-query+pxpackages-example-installed',
			'action=query&list=pxpackages&pxpsource=installed|available'
				=> 'apihelp-query+pxpackages-example-all',
		];
	}
}
