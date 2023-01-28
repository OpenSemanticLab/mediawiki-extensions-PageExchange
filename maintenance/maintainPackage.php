<?php


$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";


/**
 * installs, updates or uninstalls a package
 *
 * Note: if this extension is not installed in its standard path under ./extensions
 *       then the MW_INSTALL_PATH environment variable must be set.
 *       See README in the maintenance directory.
 *
 * Usage:
 * php maintainPackage.php [options...]
 *
 * --packageid=<packageid> will refresh only the pages of the given names, with | used as a separator.
 *              Example: --packageid org.open-semantic-lab.demo.test-package
 * --update     updates the package if already installed, default disabled
 * --uninstall     uninstall the package if already installed, default disabled
 * --deletepages    deletes related pages while uninstalling, default disabled
 * --user    Set username of uploader, default 'Maintenance script'
 *
 * @author Yaron Koren
 * @author Simon Stier
 */
class PXMaintainPackage extends \Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			"installs, updates or uninstalls a package."
		);

		$this->addOption( 'packageid', '<package> The package to install.', true, true );
		$this->addOption( 'update', 'updates the package if already installed', false, false );
		$this->addOption( 'uninstall', 'uninstalls the package', false, false );
		$this->addOption( 'deletepages', 'deletes related pages while uninstalling', false, false );
        $this->addOption( 'user',
            "Set username of uploader, default 'Maintenance script'",
            false,
            true
        );
    }

    /**
	 * @see Maintenance::execute
	 */
	public function execute() {

        # Initialise the user for this operation
        $user = $this->hasOption( 'user' )
            ? User::newFromName( $this->getOption( 'user' ) )
            : User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
        if ( !$user instanceof User ) {
            $user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
        }

        $this->mUser = $user;

        $packageID = $this->getOption( 'packageid' );
		$install = true;
		$uninstall = $this->getOption( 'uninstall' );
		$update = $this->getOption( 'update' );
		$deletePages = $this->getOption( 'deletepages' );
		if ($uninstall) {
			$install = false;
		}

		if ( $packageID == null ) {
			throw new MWException( wfMessage( 'pageexchange-packageidnull' ) );
		}

		if ($install) {
			/**
			 * @see PXInstallPackageAPI
			 */
			$this->mInstalledExtensions = PXUtils::getInstalledExtensions( $this->getConfig() );
			try {
				$package = $this->getRemotePackage( $packageID );
				if ( $package == null ) {
					throw new MWException( wfMessage( 'pageexchange-packagenotexists', $packageID ) );
				}
				$package->install( $user );
				$this->output( "$packageID installed\n" );
			} catch (Exception $e) {
				if ($update) {
					$this->output( "$packageID already installed, run update\n" );
					/**
					 * @see PXUpdatePackageAPI
					 */
					//$this->mInstalledExtensions = PXUtils::getInstalledExtensions( $this->getConfig() );
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
						throw new MWException( wfMessage( 'pageexchange-packagenotexists', $packageID ) );
					}
					$package->update( $user );
				}
				else $this->output( "$packageID already installed, re-run with --update to update the package\n" );
			}
		}

		if ($uninstall) {
			/**
			 * @see PXUninstallPackageAPI
			 */
			$this->mInstalledPackages = PXUtils::getInstalledPackages( $user );
			$package = null;
			foreach ( $this->mInstalledPackages as $installedPackage ) {
				if ( $installedPackage->getGlobalID() == $packageID ) {
					$package = $installedPackage;
					break;
				}
			}
			if ( $package == null ) {
				throw new MWException( wfMessage( 'pageexchange-packagenotexists', $packageID ) );
			}
			$package->uninstall( $user, $deletePages );
			$this->output( "$packageID uninstalled\n" );
		}
	}

    private function getUser() {
        return $this->mUser;
    }

    /**
     * @see PXInstallPackageAPI
     */
	private function getRemotePackage( $packageID ) {
		$dbr = wfGetDb( DB_REPLICA );
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

	/**
     * @see PXUpdatePackageAPI
     */
	private function loadAllFiles() {
		$pxFiles = [];
		$installedPackageIDs = [];
		foreach ( $this->mInstalledPackages as $installedPackage ) {
			$installedPackageIDs[] = $installedPackage->getGlobalID();
		}

		$packageFileURLs = $this->getConfig()->get( 'PageExchangePackageFiles' );
		foreach ( $packageFileURLs as $i => $url ) {
			$pxFiles[] = PXPackageFile::init( $url, -1, $i + 1, $this->mInstalledExtensions, $installedPackageIDs );
		}

		$fileDirectories = $this->getConfig()->get( 'PageExchangeFileDirectories' );
		foreach ( $fileDirectories as $dirNum => $fileDirectoryURL ) {
			$curPackageFiles = PXUtils::readFileDirectory( $fileDirectoryURL );
			foreach ( $curPackageFiles as $fileNum => $packageURL ) {
				$pxFiles[] = PXPackageFile::init( $packageURL, $dirNum + 1, $fileNum + 1, $this->mInstalledExtensions, $installedPackageIDs );
			}
		}

		foreach ( $pxFiles as $pxFile ) {
			$packages = $pxFile->getAllPackages( $this->getUser() );
			foreach ( $packages as $remotePackage ) {
				$this->loadRemotePackage( $remotePackage );
			}
		}
	}

	/**
     * @see PXUpdatePackageAPI
     */
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
}

$maintClass = "PXMaintainPackage";
require_once RUN_MAINTENANCE_IF_MAIN;