<?php
/**
 * Class for the page Special:Packages
 *
 * @author Yaron Koren
 */

class SpecialPackages extends SpecialPage {

	private $mInstalledExtensions = [];
	private $mInstalledPackages = [];
	private $mMatchingPackages = [];
	private $mNonMatchingPackages = [];
	private $mUnusablePackages = [];

	public function __construct() {
		parent::__construct( 'Packages', 'pageexchange' );
	}

	public function execute( $query ) {
		$this->checkPermissions();

		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		$this->setHeaders();
		$out->enableOOUI();
		$out->addModules( [ 'ext.pageexchange' ] );
		$out->addModuleStyles( [ 'oojs-ui.styles.icons-alerts' ] );
		$this->mInstalledExtensions = PXUtils::getInstalledExtensions( $this->getConfig() );
		$packageName = $request->getVal( 'name' );
		$directoryNum = $request->getVal( 'directoryNum' );
		$fileNum = $request->getVal( 'fileNum' );

		if ( $packageName !== null && $fileNum !== null ) {
			$out->setPageTitle( $this->msg( 'packages' )->parse() . ': ' . $packageName );
			$this->addBreadcrumb();
			$package = $this->getRemotePackage( $directoryNum, $fileNum, $packageName );
			if ( $package == null ) {
				$out->addHTML( '<span class="error">' . $this->msg( 'pageexchange-noremotepackage', $packageName )->parse() . '</span>' );
				return;
			} elseif ( $request->getCheck( 'install' ) ) {
				$package->install( $user );
				$text = $this->displaySuccessMessage( $this->msg( 'pageexchange-packageinstalled' )->parse() );
			} else {
				$package->prefetchForDisplay();
				$text = $package->getFullHTML();
			}
		} elseif ( $packageName !== null ) {
			$package = null;
			$this->mInstalledPackages = PXUtils::getInstalledPackages( $user );
			$this->loadAllFiles();
			foreach ( $this->mInstalledPackages as $installedPackage ) {
				if ( $installedPackage->getName() == $packageName ) {
					$this->addBreadcrumb();
					$package = $installedPackage;
					$out->setPageTitle( $this->msg( 'packages' )->parse() . ': ' . $packageName );
					break;
				}
			}
			if ( $package == null ) {
				$out->addHTML( '<span class="error">' . $this->msg( 'pageexchange-nolocalpackage', $packageName )->parse() . '</span>' );
				return;
			}
			if ( $request->getCheck( 'update' ) ) {
				$package->update( $user );
				$text = $this->displaySuccessMessage( $this->msg( 'pageexchange-packageupdated' )->parse() );
			} elseif ( $request->getCheck( 'uninstall' ) ) {
				$deleteAll = $request->getCheck( 'deleteAll' );
				$package->uninstall( $user, $deleteAll );
				$text = $this->displaySuccessMessage( $this->msg( 'pageexchange-packageuninstalled' )->parse() );
			} else {
				$package->prefetchForDisplay();
				$text = $package->getFullHTML();
			}
		} else {
			$this->mInstalledPackages = PXUtils::getInstalledPackages( $user );
			$this->loadAllFiles();
			$text = $this->displayAll();
		}
		$out->addHTML( $text );
	}

	private function loadAllFiles() {
		$packageFiles = [];
		$installedPackageIDs = [];
		foreach ( $this->mInstalledPackages as $installedPackage ) {
			$installedPackageIDs[] = $installedPackage->getGlobalID();
		}

		$fileDirectories = $this->getConfig()->get( 'PageExchangeFileDirectories' );
		$packageFileURLs = $this->getConfig()->get( 'PageExchangePackageFiles' );

		// === Phase 1: Batch-fetch directory listings in parallel ===
		if ( !empty( $fileDirectories ) ) {
			PXUtils::getWebPageContentsBatch( $fileDirectories );
		}

		// Parse directory listings (cache hit) and collect all package file URLs.
		$dirPackageFiles = [];
		$allPackageFileURLs = [];
		foreach ( $fileDirectories as $dirNum => $fileDirectoryURL ) {
			$curPackageFiles = PXUtils::readFileDirectory( $fileDirectoryURL );
			$dirPackageFiles[$dirNum] = $curPackageFiles;
			$allPackageFileURLs = array_merge( $allPackageFileURLs, $curPackageFiles );
		}
		$allPackageFileURLs = array_merge( $allPackageFileURLs, $packageFileURLs );

		// === Phase 2: Batch-fetch all package JSON files in parallel ===
		if ( !empty( $allPackageFileURLs ) ) {
			PXUtils::getWebPageContentsBatch( $allPackageFileURLs );
		}

		// === Phase 3: Pre-scan for GitHub tree API URLs and batch-fetch ===
		// Fetch both 'main' and 'master' branch trees in parallel.
		// Only the valid branch returns content; empty responses are not cached.
		// addToPagesFromGitHubData() will hit cache for whichever branch exists.
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

		// === Now process as before — all fetches hit cache ===
		foreach ( $fileDirectories as $dirNum => $fileDirectoryURL ) {
			foreach ( $dirPackageFiles[$dirNum] as $fileNum => $packageURL ) {
				try {
					$packageFiles[] = PXPackageFile::init( $packageURL, $dirNum + 1, $fileNum + 1, $this->mInstalledExtensions, $installedPackageIDs );
				} catch ( MWException $e ) {
					$this->getOutput()->addHtml( Html::element( 'div', [ 'class' => 'error' ], $e->getMessage() ) );
					continue;
				}
			}
		}

		foreach ( $packageFileURLs as $i => $url ) {
			try {
				$packageFiles[] = PXPackageFile::init( $url, null, $i + 1, $this->mInstalledExtensions, $installedPackageIDs );
			} catch ( MWException $e ) {
				$this->getOutput()->addHtml( Html::element( 'div', [ 'class' => 'error' ], $e->getMessage() ) );
				continue;
			}
		}

		foreach ( $packageFiles as $packageFile ) {
			try {
				$packages = $packageFile->getAllPackages( $this->getUser() );
			} catch ( MWException $e ) {
				$this->getOutput()->addHtml( Html::element( 'div', [ 'class' => 'error' ],
					"Error in file {$packageFile->getURL()}: " . $e->getMessage() )
				);
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

		if ( $remotePackage->isUninstallable() ) {
			$this->mUnusablePackages[] = $remotePackage;
		} elseif ( $remotePackage->isMatch() ) {
			$this->mMatchingPackages[] = $remotePackage;
		} else {
			$this->mNonMatchingPackages[] = $remotePackage;
		}
	}

	private function getRemotePackage( $directoryNum, $fileNum, $packageName ) {
		if ( $directoryNum == null ) {
			$packageFiles = $this->getConfig()->get( 'PageExchangePackageFiles' );
		} else {
			$fileDirectories = $this->getConfig()->get( 'PageExchangeFileDirectories' );
			if ( count( $fileDirectories ) < $directoryNum ) {
				return null;
			}
			$packageFiles = PXUtils::readFileDirectory( $fileDirectories[$directoryNum - 1] );
		}

		if ( count( $packageFiles ) < $fileNum ) {
			return null;
		}

		$fileURL = $packageFiles[$fileNum - 1];

		$dbr = wfGetDb( DB_REPLICA );
		$res = $dbr->select(
			'px_packages',
			'pxp_global_id'
		);
		$installedPackageIDs = [];
		while ( $row = $res->fetchRow() ) {
			$installedPackageIDs[] = $row[0];
		}

		$pxFile = PXPackageFile::init( $fileURL, $directoryNum, $fileNum, $this->mInstalledExtensions, $installedPackageIDs );

		return $pxFile->getPackage( $packageName, $this->getUser() );
	}

	private function displayAll() {
		$text = '';
		$installedPackagesText = '';
		foreach ( $this->mInstalledPackages as $package ) {
			$installedPackagesText .= $package->displayCard();
		}

		if ( $installedPackagesText != '' ) {
			$text .= Html::element( 'h2', null, wfMessage( 'pageexchange-installed' )->parse() ) . "\n";
			$text .= <<<END
$installedPackagesText
<br style="clear: both;" />

END;
		}

		$matchingPackagesText = '';
		foreach ( $this->mMatchingPackages as $package ) {
			$matchingPackagesText .= $package->displayCard();
		}
		if ( $matchingPackagesText != '' ) {
			$text .= Html::element( 'h2', null, wfMessage( 'pageexchange-available' )->parse() ) . "\n";
			$text .= <<<END
<p>Page names in <em>italics</em> already exist on this wiki.</p>
$matchingPackagesText
<br style="clear: both;" />

END;
		}

		$nonMatchingPackagesText = '';
		foreach ( $this->mNonMatchingPackages as $package ) {
			$nonMatchingPackagesText .= $package->displayCard();
		}
		if ( $nonMatchingPackagesText != '' ) {
			$text .= <<<END
<h2>Non-matching</h2>
<p>Page names in <em>italics</em> already exist on this wiki.</p>
$nonMatchingPackagesText
<br style="clear: both;" />

END;
		}

		$unusablePackagesText = '';
		foreach ( $this->mUnusablePackages as $package ) {
			$unusablePackagesText .= $package->displayCard();
		}
		if ( $unusablePackagesText !== '' ) {
			$text .= <<<END
<h2>Unusable</h2>
$unusablePackagesText
<br style="clear: both;" />

END;
		}

		return $text;
	}

	private function addBreadcrumb() {
		$linkRenderer = $this->getLinkRenderer();
		$packagesPage = $this;
		$mainPageLink =
			$linkRenderer->makeLink( $this->getPageTitle(),
				htmlspecialchars( $this->getDescription() ) );
		$this->getOutput()->setSubtitle( '< ' . $mainPageLink );
	}

	private function displaySuccessMessage( $msg ) {
		$text = Html::element( 'p', null, $msg ) . "\n";
		$linkRenderer = $this->getLinkRenderer();
		$mainPageLink = $linkRenderer->makeLink(
			$this->getPageTitle(),
			$this->msg( 'returnto' )->rawParams( $this->getDescription() )->parse()
		);
		$text .= Html::rawElement( 'p', null, $mainPageLink );
		return $text;
	}
}
