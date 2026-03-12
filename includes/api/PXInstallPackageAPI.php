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
			throw new MWException( wfMessage( 'pageexchange-packageidnull' ) );
		}

		$this->mInstalledExtensions = PXUtils::getInstalledExtensions( $this->getConfig() );
		$package = $this->getRemotePackage( $packageID );
		if ( $package == null ) {
			throw new MWException( wfMessage( 'pageexchange-packagenotexists', $packageID ) );
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
				throw new MWException( wfMessage( 'pageexchange-packagealreadyinstalled', $packageID ) );
			}
		}

		$packageFiles = $this->getConfig()->get( 'PageExchangePackageFiles' );
		foreach ( $packageFiles as $fileNum => $url ) {
			$pxFile = PXPackageFile::init( $url, -1, $fileNum + 1, $this->mInstalledExtensions, $installedPackageIDs );
			$packages = $pxFile->getAllPackages( $this->getUser() );
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
				$pxFile = PXPackageFile::init( $packageURL, $dirNum + 1, $fileNum + 1, $this->mInstalledExtensions, $installedPackageIDs );
				$packages = $pxFile->getAllPackages( $this->getUser() );
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
