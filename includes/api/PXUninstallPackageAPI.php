<?php

class PXUninstallPackageAPI extends ApiBase {

	private $mInstalledPackages = [];

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$packageID = $params['packageid'];
		$deletePages = $params['deletepages'];
		$user = $this->getUser();

		if ( $packageID == null ) {
			$this->dieWithError( 'pageexchange-packageidnull', 'packageidnull' );
		}

		$this->mInstalledPackages = PXUtils::getInstalledPackages( $user );
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
		$package->uninstall( $user, $deletePages );
	}

	protected function getAllowedParams() {
		return [
			'packageid' => null,
			'deletepages' => false
		];
	}

	protected function getParamDescription() {
		return [
			'packageid' => 'The globalID of a package',
			'deletepages' => 'Whether to delete the pages associated with a package'
		];
	}

	protected function getDescription() {
		return 'Uninstalling a package. Defined by https://www.mediawiki.org/wiki/Extension:Page_Exchange';
	}

	protected function getExamples() {
		return [
			'api.php?action=pxunistallpackage&packageid=com.wikiworks.books-demo-cargo',
			'api.php?action=pxunistallpackage&packageid=com.wikiworks.books-demo-cargo&deletepages=true'
		];
	}

}
