<?php

use MediaWiki\MediaWikiServices;

class PXInstallPackageAPI extends ApiBase {

	private $mInstalledExtensions = [];

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$packageID = $params['packageid'];
		$user = $this->getUser();

		if ( $packageID == null ) {
			$this->dieWithError( 'pageexchange-packageidnull', 'packageidnull' );
		}

		$this->mInstalledExtensions = PXUtils::getInstalledExtensions( $this->getConfig() );
		$package = $this->getRemotePackage( $packageID );
		if ( $package == null ) {
			$this->dieWithError( [ 'pageexchange-packagenotexists', $packageID ], 'packagenotexists' );
		}
		$package->install( $user );
	}

	private function getRemotePackage( $packageID ) {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$res = $dbr->select(
			'px_packages',
			'pxp_global_id'
		);
		$installedPackageIDs = [];
		while ( $row = $res->fetchRow() ) {
			$installedPackageIDs[] = $row[0];
			if ( $row[0] == $packageID ) {
				$this->dieWithError( [ 'pageexchange-packagealreadyinstalled', $packageID ], 'packagealreadyinstalled' );
			}
		}

		$packageFiles = $this->getConfig()->get( 'PageExchangePackageFiles' );
		foreach ( $packageFiles as $fileNum => $url ) {
			try {
				$pxFile = PXPackageFile::init( $url, -1, $fileNum + 1, $this->mInstalledExtensions, $installedPackageIDs );
				$packages = $pxFile->getAllPackages( $this->getUser() );
			} catch ( MWException $e ) {
				$this->addWarning( [ 'pageexchange-packagefileerror', $url, $e->getMessage() ] );
				continue;
			}
			foreach ( $packages as $package ) {
				if ( $package->getGlobalID() == $packageID ) {
					return $pxFile->getPackage( $package->getName(), $this->getUser() );
				}
			}
		}

		$fileDirectories = $this->getConfig()->get( 'PageExchangeFileDirectories' );
		foreach ( $fileDirectories as $dirNum => $fileDirectoryURL ) {
			$curPackageFiles = PXUtils::readFileDirectory( $fileDirectoryURL );
			foreach ( $curPackageFiles as $fileNum => $packageURL ) {
				try {
					$pxFile = PXPackageFile::init( $packageURL, $dirNum + 1, $fileNum + 1, $this->mInstalledExtensions, $installedPackageIDs );
					$packages = $pxFile->getAllPackages( $this->getUser() );
				} catch ( MWException $e ) {
					$this->addWarning( [ 'pageexchange-packagefileerror', $packageURL, $e->getMessage() ] );
					continue;
				}
				foreach ( $packages as $package ) {
					if ( $package->getGlobalID() == $packageID ) {
						return $pxFile->getPackage( $package->getName(), $this->getUser() );
					}
				}
			}
		}
	}

	protected function getAllowedParams() {
		return [
			'packageid' => null
		];
	}

	protected function getParamDescription() {
		return [
			'packageid' => 'The globalID of a package'
		];
	}

	protected function getDescription() {
		return 'Installing a package. Defined by https://www.mediawiki.org/wiki/Extension:Page_Exchange';
	}

	protected function getExamples() {
		return [
			'api.php?action=pxinstallpackage&packageid=com.wikiworks.books-demo-cargo'
		];
	}

}
