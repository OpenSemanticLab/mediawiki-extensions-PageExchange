<?php

/**
 * Class for a JSON file holding the definitions of one or more packages.
 *
 * @author Yaron Koren
 * @ingroup PX
 */
class PXPackageFile {

	private $mURL;
	private $mData;
	private $mDirectoryNum;
	private $mFileNum;
	private $mInstalledExtensions;
	private $mInstalledPackages;

	public static function init( $url, $directoryNum, $fileNum, $installedExtensions, $installedPackages ) {
		$json = PXUtils::getWebPageContents( $url );
		if ( $json === null || $json === '' ) {
			throw new MWException( 'Error: no file found at ' . $url );
		}
		$fileData = json_decode( $json );
		if ( $fileData === null ) {
			throw new MWException( 'Error: the file at ' . $url . ' contains invalid JSON.' );
		}

		$packageFile = new PXPackageFile();
		$packageFile->mURL = $url;
		$packageFile->mData = $fileData;
		$packageFile->mDirectoryNum = $directoryNum;
		$packageFile->mFileNum = $fileNum;
		$packageFile->mInstalledExtensions = $installedExtensions;
		$packageFile->mInstalledPackages = $installedPackages;

		return $packageFile;
	}

	public function getPackage( $packageName, $user ) {
		$packageData = $this->mData->packages->$packageName;
		$package = PXRemotePackage::newFromData(
			$this->mDirectoryNum,
			$this->mFileNum,
			$this->mData,
			$packageName,
			$packageData,
			$this->mInstalledExtensions,
			$this->mInstalledPackages,
			$user
		);
		$package->setPackageFileURL( $this->mURL );
		return $package;
	}

	public function getAllPackages( $user ) {
		$packages = [];
		foreach ( $this->mData->packages as $name => $packageData ) {
			$package = PXRemotePackage::newFromData(
				$this->mDirectoryNum,
				$this->mFileNum,
				$this->mData,
				$name,
				$packageData,
				$this->mInstalledExtensions,
				$this->mInstalledPackages,
				$user
			);
			$package->setPackageFileURL( $this->mURL );
			$packages[] = $package;
		}
		return $packages;
	}

	function getURL() {
		return $this->mURL;
	}

}
