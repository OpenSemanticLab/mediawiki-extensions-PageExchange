<?php

class PXUpdatePackageAPI extends ApiBase {

	private $mInstalledExtensions = [];
	private $mInstalledPackages = [];

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
		$this->mInstalledPackages = PXUtils::getInstalledPackages( $user );
		$this->loadAllFiles();
		$package = null;
		foreach ( $this->mInstalledPackages as $installedPackage ) {
			if ( $installedPackage->getGlobalID() == $packageID ) {
				$package = $installedPackage;
				break;
			}
		}
		if ( $package == null ) {
			$this->dieWithError( [ 'pageexchange-packagenotexists', $packageID ], 'packagenotexists' );
		}
		if ( $package->getAssociatedRemotePackage() === null ) {
			$this->dieWithError( [ 'pageexchange-packagenotavailable', $packageID ], 'packagenotavailable' );
		}
		$package->update( $user );
	}

	private function loadAllFiles() {
		$pxFiles = [];
		$installedPackageIDs = [];
		foreach ( $this->mInstalledPackages as $installedPackage ) {
			$installedPackageIDs[] = $installedPackage->getGlobalID();
		}

		$packageFileURLs = $this->getConfig()->get( 'PageExchangePackageFiles' );
		foreach ( $packageFileURLs as $i => $url ) {
			try {
				$pxFiles[] = PXPackageFile::init( $url, -1, $i + 1, $this->mInstalledExtensions, $installedPackageIDs );
			} catch ( MWException $e ) {
				$this->addWarning( [ 'pageexchange-packagefileerror', $url, $e->getMessage() ] );
			}
		}

		$fileDirectories = $this->getConfig()->get( 'PageExchangeFileDirectories' );
		foreach ( $fileDirectories as $dirNum => $fileDirectoryURL ) {
			$curPackageFiles = PXUtils::readFileDirectory( $fileDirectoryURL );
			foreach ( $curPackageFiles as $fileNum => $packageURL ) {
				try {
					$pxFiles[] = PXPackageFile::init( $packageURL, $dirNum + 1, $fileNum + 1, $this->mInstalledExtensions, $installedPackageIDs );
				} catch ( MWException $e ) {
					$this->addWarning( [ 'pageexchange-packagefileerror', $packageURL, $e->getMessage() ] );
				}
			}
		}

		foreach ( $pxFiles as $pxFile ) {
			try {
				$packages = $pxFile->getAllPackages( $this->getUser() );
			} catch ( MWException $e ) {
				$this->addWarning( [ 'pageexchange-packagefileerror', $pxFile->getURL(), $e->getMessage() ] );
				continue;
			}
			foreach ( $packages as $remotePackage ) {
				$this->loadRemotePackage( $remotePackage );
			}
		}
	}

	private function loadRemotePackage( $remotePackage ) {
		// Check if it matches an installed package.
		foreach ( $this->mInstalledPackages as &$installedPackage ) {
			if (
				$installedPackage->getGlobalID() !== null &&
				$installedPackage->getGlobalID() == $remotePackage->getGlobalID()
			) {
				$installedPackage->setAssociatedRemotePackage( $remotePackage );
				return;
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
		return 'Updating a package. Defined by https://www.mediawiki.org/wiki/Extension:Page_Exchange';
	}

	protected function getExamples() {
		return [
			'api.php?action=pxupdatepackage&packageid=com.wikiworks.books-demo-cargo'
		];
	}

}
