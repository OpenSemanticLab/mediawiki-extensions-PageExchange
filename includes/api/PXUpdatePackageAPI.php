<?php

class PXUpdatePackageAPI extends ApiBase {

	private $mInstalledExtensions = [];
	private $mInstalledPackages = [];
	private $mFailedFiles = [];
	private $mPackageErrors = [];
	private $mDefiningFiles = [];

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
		$storedURL = $package->getStoredPackageFileURL();
		$remotePackage = $package->getAssociatedRemotePackage();
		if ( $storedURL !== null ) {
			if ( in_array( $storedURL, $this->mFailedFiles, true ) ) {
				$this->dieWithError( [ 'pageexchange-packagesourceunavailable', $packageID, $storedURL ], 'packagesourceunavailable' );
			}
			foreach ( $this->mPackageErrors as $packageError ) {
				if ( $packageError['globalid'] !== null && (string)$packageError['globalid'] === (string)$packageID && $packageError['file'] === $storedURL ) {
					$this->dieWithError( [ 'pageexchange-packageloadfailed', $packageID, $packageError['error'] ], 'packageloadfailed' );
				}
			}
			if ( $remotePackage !== null && $remotePackage->getPackageFileURL() !== $storedURL ) {
				$this->addWarning( [ 'pageexchange-packagesourcechanged', $packageID, $storedURL, $remotePackage->getPackageFileURL() ] );
			}
		} elseif ( $remotePackage !== null && count( $this->mDefiningFiles[$packageID] ?? [] ) > 1 ) {
			$this->addWarning( [ 'pageexchange-packagesourceambiguous', $packageID, implode( ', ', $this->mDefiningFiles[$packageID] ) ] );
		}
		if ( $remotePackage === null ) {
			// Not found in any readable file: name the real cause if there is one.
			$nullIDErrors = 0;
			foreach ( $this->mPackageErrors as $packageError ) {
				if ( $packageError['globalid'] !== null && (string)$packageError['globalid'] === (string)$packageID ) {
					$this->dieWithError( [ 'pageexchange-packageloadfailed', $packageID, $packageError['error'] ], 'packageloadfailed' );
				}
				if ( $packageError['globalid'] === null ) {
					$nullIDErrors++;
				}
			}
			$incompleteCount = count( $this->mFailedFiles ) + $nullIDErrors;
			if ( $incompleteCount > 0 ) {
				$this->dieWithError( [ 'pageexchange-packagenotfoundincomplete', $packageID, $incompleteCount ], 'packagenotfoundincomplete' );
			}
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
				$this->mFailedFiles[] = $url;
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
					$this->mFailedFiles[] = $packageURL;
					$this->addWarning( [ 'pageexchange-packagefileerror', $packageURL, $e->getMessage() ] );
				}
			}
		}

		foreach ( $pxFiles as $pxFile ) {
			try {
				$packages = $pxFile->getAllPackages( $this->getUser() );
			} catch ( MWException $e ) {
				$this->mFailedFiles[] = $pxFile->getURL();
				$this->addWarning( [ 'pageexchange-packagefileerror', $pxFile->getURL(), $e->getMessage() ] );
				continue;
			}
			foreach ( $pxFile->getPackageErrors() as $packageError ) {
				$packageError['file'] = $pxFile->getURL();
				$this->mPackageErrors[] = $packageError;
				$this->addWarning( [ 'pageexchange-packageerror', $packageError['name'], $pxFile->getURL(), $packageError['error'] ] );
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
				$definingFiles = $this->mDefiningFiles[$remotePackage->getGlobalID()] ?? [];
				if ( !in_array( $remotePackage->getPackageFileURL(), $definingFiles, true ) ) {
					$this->mDefiningFiles[$remotePackage->getGlobalID()][] = $remotePackage->getPackageFileURL();
				}
				$installedPackage->offerRemotePackage( $remotePackage );
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
