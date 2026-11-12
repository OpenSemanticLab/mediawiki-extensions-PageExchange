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
	private $mPackageErrors = [];

	public static function init( $url, $directoryNum, $fileNum, $installedExtensions, $installedPackages ) {
		$json = PXUtils::getWebPageContents( $url );
		if ( $json === null || $json === '' ) {
			throw new MWException( 'Error: no file found at ' . $url );
		}
		$fileData = json_decode( $json );
		if ( $fileData === null ) {
			throw new MWException( 'Error: the file at ' . $url . ' contains invalid JSON.' );
		}
		if ( !is_object( $fileData ) || !isset( $fileData->packages ) ||
			!( is_object( $fileData->packages ) || is_array( $fileData->packages ) ) ) {
			throw new MWException( 'Error: the file at ' . $url . ' contains no valid list of packages.' );
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
		$packages = $this->mData->packages;
		$packageData = is_object( $packages ) ? ( $packages->$packageName ?? null ) : ( $packages[$packageName] ?? null );
		$definitionError = self::getDefinitionError( $packageData );
		if ( $definitionError !== null ) {
			throw new MWException( $definitionError );
		}
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
		$this->mPackageErrors = [];
		foreach ( $this->mData->packages as $name => $packageData ) {
			$definitionError = self::getDefinitionError( $packageData );
			if ( $definitionError !== null ) {
				$this->mPackageErrors[] = [
					'name' => $name,
					'globalid' => is_object( $packageData ) && is_string( $packageData->globalID ?? null ) ? $packageData->globalID : null,
					'error' => $definitionError
				];
				continue;
			}
			try {
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
			} catch ( MWException $e ) {
				// Skip this package only, the other packages in the file stay usable.
				$this->mPackageErrors[] = [
					'name' => $name,
					'globalid' => $packageData->globalID ?? null,
					'error' => $e->getMessage()
				];
				continue;
			}
			$package->setPackageFileURL( $this->mURL );
			$packages[] = $package;
		}
		return $packages;
	}

	/**
	 * @param mixed $packageData One decoded entry of the package list
	 * @return string|null An error message if the entry cannot be a package definition
	 */
	private static function getDefinitionError( $packageData ) {
		if ( !is_object( $packageData ) ) {
			return 'invalid package definition';
		}
		if ( property_exists( $packageData, 'directoryStructure' ) && !is_object( $packageData->directoryStructure ) ) {
			return 'invalid package definition';
		}
		$ds = $packageData->directoryStructure ?? null;
		if ( $ds !== null && ( $ds->service ?? null ) == 'GitHub' ) {
			foreach ( [ 'accountName', 'repositoryName' ] as $field ) {
				if ( property_exists( $ds, $field ) && !is_string( $ds->$field ) ) {
					return 'invalid package definition';
				}
			}
		}
		return null;
	}

	/**
	 * Packages that could not be loaded by the last call of getAllPackages().
	 *
	 * @return array[] Each with the keys name, globalid (or null) and error
	 */
	public function getPackageErrors() {
		return $this->mPackageErrors;
	}

	function getURL() {
		return $this->mURL;
	}

}
