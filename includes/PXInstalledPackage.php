<?php
/**
 * Class for a package that has been installed.
 *
 * @author Yaron Koren
 * @ingroup PX
 */

use MediaWiki\MediaWikiServices;

class PXInstalledPackage extends PXPackage {

	private $mID;
	private $mAssociatedRemotePackage;
	private $mStoredPackageFileURL;
	private $mJobCounts;
	private $mInvalidData;
	private $mUnmatchedRemotePages = [];

	public static function newFromDB( $dbRow, $user ) {
		$package = new PXInstalledPackage();

		$packageData = json_decode( $dbRow['pxp_package_data'] );
		$package->populateWithData( $directoryData = null, $packageData );

		$package->mName = $dbRow['pxp_name'];
		$package->mID = $dbRow['pxp_id'];
		if ( is_object( $packageData ) && isset( $packageData->packageFileURL ) && is_string( $packageData->packageFileURL ) ) {
			$package->mStoredPackageFileURL = $packageData->packageFileURL;
		}
		$package->mUser = $user;
		if ( $packageData === null ) {
			$package->mInvalidData = true;
		}

		return $package;
	}

	/**
	 * @param array|null $jobCounts [ 'queued' => n, 'claimed' => n, 'failed' => n ] for this package,
	 *  or null if the job state is unknown
	 */
	public function setJobCounts( $jobCounts ) {
		$this->mJobCounts = $jobCounts;
	}

	private function getJobStateHTML() {
		if ( $this->mJobCounts === null ) {
			return '';
		}
		$html = '';
		if ( $this->mJobCounts['queued'] + $this->mJobCounts['claimed'] > 0 ) {
			$html .= $this->displayInfoMessage( wfMessage( 'pageexchange-package-jobsunfinished' )
				->numParams( $this->mJobCounts['queued'], $this->mJobCounts['claimed'] )->parse() );
		}
		if ( $this->mJobCounts['failed'] > 0 ) {
			$html .= $this->displayWarningMessage( wfMessage( 'pageexchange-package-jobsfailed' )
				->numParams( $this->mJobCounts['failed'] )->parse() );
		}
		return $html;
	}

	public function getAssociatedRemotePackage() {
		return $this->mAssociatedRemotePackage;
	}

	/**
	 * The URL of the package file this package was installed or last updated from,
	 * or null for packages installed before that URL was stored.
	 */
	public function getStoredPackageFileURL() {
		return $this->mStoredPackageFileURL;
	}

	/**
	 * Decides whether this package may be updated from the package files that were loaded.
	 *
	 * @param string[] $failedFiles URLs of package files that could not be loaded
	 * @param array[] $packageErrors Package load errors, each with the keys globalid and file
	 * @param array $definingFiles [ globalID => list of package file URLs defining it ]
	 * @return array [ 'error' => [ message key, params... ] or null, 'code' => API error code or null,
	 *  'warnings' => list of [ message key, params... ] ]
	 */
	public function checkUpdateSource( array $failedFiles, array $packageErrors, array $definingFiles ) {
		$packageID = (string)$this->getGlobalID();
		$storedURL = $this->mStoredPackageFileURL;
		$remotePackage = $this->mAssociatedRemotePackage;
		$warnings = [];
		if ( $storedURL !== null ) {
			if ( in_array( $storedURL, $failedFiles, true ) ) {
				return [
					'error' => [ 'pageexchange-packagesourceunavailable', $packageID, $storedURL ],
					'code' => 'packagesourceunavailable',
					'warnings' => $warnings
				];
			}
			foreach ( $packageErrors as $packageError ) {
				if ( $packageError['globalid'] !== null && (string)$packageError['globalid'] === $packageID && $packageError['file'] === $storedURL ) {
					return [
						'error' => [ 'pageexchange-packageloadfailed', $packageID, $packageError['error'] ],
						'code' => 'packageloadfailed',
						'warnings' => $warnings
					];
				}
			}
			if ( $remotePackage !== null && $remotePackage->getPackageFileURL() !== $storedURL ) {
				$warnings[] = [ 'pageexchange-packagesourcechanged', $packageID, $storedURL, $remotePackage->getPackageFileURL() ];
			}
		} elseif ( $remotePackage !== null && count( $definingFiles[$packageID] ?? [] ) > 1 ) {
			$warnings[] = [ 'pageexchange-packagesourceambiguous', $packageID, implode( ', ', $definingFiles[$packageID] ) ];
		}
		if ( $remotePackage === null ) {
			// Not found in any readable file: name the real cause if there is one.
			$nullIDErrors = 0;
			foreach ( $packageErrors as $packageError ) {
				if ( $packageError['globalid'] !== null && (string)$packageError['globalid'] === $packageID ) {
					return [
						'error' => [ 'pageexchange-packageloadfailed', $packageID, $packageError['error'] ],
						'code' => 'packageloadfailed',
						'warnings' => $warnings
					];
				}
				if ( $packageError['globalid'] === null ) {
					$nullIDErrors++;
				}
			}
			$incompleteCount = count( $failedFiles ) + $nullIDErrors;
			if ( $incompleteCount > 0 ) {
				return [
					'error' => [ 'pageexchange-packagenotfoundincomplete', $packageID, $incompleteCount ],
					'code' => 'packagenotfoundincomplete',
					'warnings' => $warnings
				];
			}
			return [
				'error' => [ 'pageexchange-packagenotavailable', $packageID ],
				'code' => 'packagenotavailable',
				'warnings' => $warnings
			];
		}
		return [ 'error' => null, 'code' => null, 'warnings' => $warnings ];
	}

	/**
	 * Associates a remote package, unless one from the stored package file is already associated.
	 * Without a stored URL the last offered remote package wins.
	 */
	public function offerRemotePackage( $remotePackage ) {
		if (
			$this->mStoredPackageFileURL !== null &&
			$this->mAssociatedRemotePackage !== null &&
			$this->mAssociatedRemotePackage->getPackageFileURL() === $this->mStoredPackageFileURL
		) {
			return;
		}
		$this->setAssociatedRemotePackage( $remotePackage );
	}

	public function setAssociatedRemotePackage( $remotePackage ) {
		$this->mAssociatedRemotePackage = $remotePackage;

		foreach ( $remotePackage->mPages as $remotePage ) {
			$foundMatchingLocalPage = false;
			foreach ( $this->mPages as $localPage ) {
				if ( $localPage->getName() == $remotePage->getName() &&
					$localPage->getNamespace() == $remotePage->getNamespace() ) {
					$localPage->setURL( $remotePage->getURL() );
					$foundMatchingLocalPage = true;
					break;
				}
			}
			if ( !$foundMatchingLocalPage ) {
				$this->mUnmatchedRemotePages[] = $remotePage;
			}
		}
	}

	public function getCardBodyHTML() {
		$packageHTML = '';
		if ( !empty( $this->mInvalidData ) ) {
			$packageHTML .= $this->displayWarningMessage( wfMessage( 'pageexchange-package-invaliddata' )->parse() );
			return $packageHTML;
		}
		$packageHTML .= $this->getJobStateHTML();
		$remotePackage = $this->mAssociatedRemotePackage;
		if ( $remotePackage !== null && $remotePackage->mVersion !== $this->mVersion ) {
			$packageHTML .= $this->displayInfoMessage( wfMessage( 'pageexchange-package-morerecent' )->parse() );
		}
		$packageHTML .= $this->displayDescription();
		$packageHTML .= $this->displayWebsite();
		$packageHTML .= $this->displayInstalledAttribute( 'pageexchange-package-publisher', 'mPublisher' );
		$packageHTML .= $this->displayInstalledAttribute( 'pageexchange-package-author', 'mAuthor' );
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-pages', $this->mPagesString );
		$updateMsg = wfMessage( 'pageexchange-package-update' )->parse();
		$uninstallMsg = wfMessage( 'pageexchange-package-uninstall' )->parse();
		$packageLink = $this->getPackageLink(
			"$updateMsg / $uninstallMsg",
			[ 'name' => $this->mName ]
		);
		$packageHTML .= Html::rawElement( 'p', [ 'class' => 'pageExchangeCardBottomLink' ], $packageLink );

		return $packageHTML;
	}

	public function displayInstalledAttribute( $attrMsg, $attrField ) {
		if ( !property_exists( $this, $attrField ) ) {
			return '';
		}

		$localValue = $this->$attrField;
		$remotePackage = $this->mAssociatedRemotePackage;
		if ( $remotePackage !== null ) {
			$remoteValue = $remotePackage->$attrField;
		} else {
			$remoteValue = null;
		}
		if ( $localValue == null && $remoteValue == null ) {
			return '';
		}

		$text = '<p>';
		if ( $attrMsg != '' ) {
			$text .= '<strong>' . wfMessage( $attrMsg )->parse() . '</strong> ';
		}
		if ( is_array( $localValue ) ) {
			$text .= implode( ', ', $localValue );
		} else {
			$text .= $localValue;
		}
		if ( $remotePackage !== null && $remoteValue !== $localValue ) {
			if ( is_array( $remoteValue ) ) {
				$remoteValue = implode( ', ', $remoteValue );
			}
			$latestValueText = wfMessage( 'pageexchange-latestvalue' )->rawParams( "<em>$remoteValue</em>" )->parse();
			$text .= " <span class=\"error\">$latestValueText</span> ";
		}
		$text .= "</p>\n";
		return $text;
	}

	public function processPages() {
		$pageLinks = [];
		foreach ( $this->mPages as $page ) {
			$pageLink = Linker::link( $page->getLocalTitle(), null, [], [ 'action' => 'raw' ] );
			$pageLinks[] = $pageLink;
		}
		if ( count( $pageLinks ) > 7 ) {
			$shownLinks = array_splice( $pageLinks, 0, 7 );
			$shownLinksStr = implode( ', ', $shownLinks );
			$hiddenLinksStr = implode( ', ', $pageLinks );
			$this->mPagesString = <<<END
<span class="pageExchangePageLinks">
$shownLinksStr, <span class="pageExchangeAdditionalPages">$hiddenLinksStr</span>
(<a class="pageExchangeToggle">show more</a>)
</span>

END;
		} else {
			$this->mPagesString = implode( ', ', $pageLinks );
		}
	}

	private function isUpdateable() {
				$userCanEditJS = $this->mUser->isAllowed( 'editinterface' ) && $this->mUser->isAllowed( 'editsitejs' );
				$userCanEditCSS = $this->mUser->isAllowed( 'editinterface' ) && $this->mUser->isAllowed( 'editsitecss' );

		foreach ( $this->mPages as $page ) {
			if ( $page->isJavaScript() && !$userCanEditJS ) {
				return false;
			}
			if ( $page->isCSS() && !$userCanEditCSS ) {
				return false;
			}
		}
		return true;
	}

	public function getFullHTML() {
		$remoteDiffersFromInstalled = false;
		$packageHTML = '';
		$remotePackage = $this->mAssociatedRemotePackage;
		$packageHTML .= $this->getJobStateHTML();
		if ( $remotePackage == null ) {
			$packageHTML .= '<div class="errorbox">' .
				'The external definition of this package can no longer be found.' .
				'</div>';
		}
		$packageHTML .= $this->displayDescription();
		$packageHTML .= $this->displayWebsite( true );
		$packageHTML .= $this->displayInstalledAttribute( 'pageexchange-package-author', 'mAuthor' );
		$packageHTML .= $this->displayInstalledAttribute( 'pageexchange-package-publisher', 'mPublisher' );
		$packageHTML .= $this->displayInstalledAttribute( 'pageexchange-package-version', 'mVersion' );

		$pagesString = "<ul>\n";
		foreach ( $this->mPages as $page ) {
			$pagesString .= "<li>" . $page->getLocalLink();
			$remoteContents = $page->getRemoteContents();
			if ( $remotePackage == null ) {
				// No remote version of anything.
				continue;
			}

			$remotePage = $remotePackage->getRemoteEquivalent( $page );
			if ( $remotePage == null ) {
				$pagesString .= ' - <span class="error">This page no longer exists in the latest version of this package.</span>';
				$remoteDiffersFromInstalled = true;
			} elseif ( $remoteContents == null ) {
				$hasSlots = property_exists( $page, 'slots' ) ||
					( $page->getURL() !== null && strpos( $page->getURL(), '.slot_main.' ) !== false );
				$pagesString .= ' (' . Html::element( 'a', [ 'href' => $page->getURL() ], 'external' ) . ')';
				if ( !$hasSlots ) {
					$pagesString .= ' - <span class="error">' . wfMessage( 'pageexchange-nocontentslocal' )->parse() . '</span>';
				}
			} elseif ( !$page->localTitleExists() ) {
				// Seems impossible that this would happen.
				$remoteDiffersFromInstalled = true;
			} else {
				if ( $page->getNamespace() == NS_FILE ) {
					$pagesString .= ' (' . Html::element( 'a', [ 'href' => $page->getURL() ], 'external text' ) . ', ' .
						Html::element( 'a', [ 'href' => $page->getFileURL() ], 'external file' ) . ')';
				} else {
					$pagesString .= ' (' . Html::element( 'a', [ 'href' => $page->getURL() ], 'external' ) . ')';
				}
				$localContents = $page->getLocalContents();
				// Ignore newlines or spaces at the end of the
				// page contents.
				if ( rtrim( $localContents ) == rtrim( $remoteContents ) ) {
					// Do nothing.
				} else {
					$pagesString .= ' - ' . wfMessage( 'pageexchange-pagehaschanged' )->parse();
					$remoteDiffersFromInstalled = true;
				}
			}
			if ( $page->getNamespace() == NS_FILE && $remotePackage !== null ) {
				$pagesString .= ' (It is unknown whether the latest version of the file matches the local copy.)';
			}
		}
		foreach ( $this->mUnmatchedRemotePages as $unmatchedRemotePage ) {
			$pagesString .= "<li>" . $unmatchedRemotePage->getLocalLink() .
				' (' . Html::element( 'a', [ 'href' => $unmatchedRemotePage->getURL() ], 'external' ) .
				') - this page does not exist locally.';
			$remoteContents = $page->getRemoteContents();
			if ( $remoteContents == null ) {
				$pagesString .= ' <span class="error">' . wfMessage( 'pageexchange-nocontentslocal' )->parse() . '</span>';
			}
			$pagesString .= "</li>\n";
		}

		$pagesString .= "</ul>\n";
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-pages', $pagesString );

		$packageHTML .= $this->displayInstalledAttribute( 'pageexchange-package-language', 'mLanguage' );
		$packageHTML .= $this->displayInstalledAttribute( 'pageexchange-package-license', 'mLicenseName' );

		if ( !$remoteDiffersFromInstalled && $remotePackage !== null ) {
			$remoteDiffersFromInstalled =
				count( $this->mUnmatchedRemotePages ) > 0 ||
				$this->mName !== $remotePackage->mName ||
				$this->mPublisher !== $remotePackage->mPublisher ||
				$this->mVersion !== $remotePackage->mVersion;
		}

		if ( !$this->isUpdateable() ) {
			$packageHTML .= $this->displayWarningMessage( 'You cannot update or uninstall this package because it contains JavaScript and/or CSS pages, which you lack the permission to edit.' );
			return $packageHTML;
		}

		// Normally 'useInputTag' is not supposed to be used for
		// OOUI buttons (you're supposed to make them part of a
		// FieldLayout, and then a FormLayout), but this was much
		// easier to do - in part because there is very minimal
		// documentation for creating OOUI elements in PHP.
		$updateButton = new OOUI\ButtonInputWidget( [
			'label' => wfMessage( 'pageexchange-package-update' )->parse(),
			'useInputTag' => true,
			'type' => 'submit',
			'name' => 'update',
			'flags' => 'progressive'
		] );
		$deleteAllCheckbox = new OOUI\CheckboxInputWidget( [
			'name' => 'deleteAll',
			'selected' => true
		] );
		$deleteAllMsg = wfMessage( 'pageexchange-package-deleteallpages' )->parse();
		$uninstallButton = new OOUI\ButtonInputWidget( [
			'label' => wfMessage( 'pageexchange-package-uninstall' )->parse(),
			'useInputTag' => true,
			'type' => 'submit',
			'name' => 'uninstall',
			'flags' => 'destructive'
		] );
		$uninstallFormSection = <<<END
<p>
<label>
$deleteAllCheckbox
$deleteAllMsg
</label>
<p>
$uninstallButton
</p>

END;

		$packageHTML .= <<<END
<form method="post">
<table style="border-collapse: collapse; margin-top: 20px; border-top: 1px solid #ccc;">
<tr>

END;

		if ( $remotePackage == null || !$remoteDiffersFromInstalled ) {
			// Don't include the update button if the remote
			// package can't be found, or if it's identical to the
			// installed package.
			$packageHTML .= <<<END
<td style="padding: 15px;">
$uninstallFormSection
</td>

END;
		} else {
			$packageHTML .= <<<END
<td style="border-right: 1px solid #ccc; padding: 25px;">
$updateButton
</td>
<td style="border-left: 1px solid #ccc; padding: 25px;">
$uninstallFormSection
</td>

END;
		}

		$packageHTML .= <<<END
</tr>
</table>
</form>

END;

		return $packageHTML;
	}

	public function update( $user ) {
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

		$remotePackage = $this->mAssociatedRemotePackage;
		if ( $remotePackage === null ) {
			throw new MWException( wfMessage( 'pageexchange-packagenotavailable', $this->getGlobalID() )->text() );
		}
		$updateValues = [
			'pxp_name' => $remotePackage->mName,
			'pxp_package_data' => json_encode( $remotePackage->getPackageData() )
		];
		$dbw->update( 'px_packages', $updateValues, [ 'pxp_id' => $this->mID ] );

		$deletedPages = [];

		foreach ( $this->mPages as $localPage ) {
			$remotePage = $this->mAssociatedRemotePackage->getRemoteEquivalent( $localPage );
			if ( $remotePage == null ) {
				$deletedPages[] = $localPage;
			}
		}

		foreach ( $deletedPages as $deletedPage ) {
			$deletedPage->deleteWikiPage( $user, $this->mName, false );
		}

		// Pre-populate cache with all page contents before creating jobs.
		$this->mAssociatedRemotePackage->prefetchPageContents();

		foreach ( $this->mAssociatedRemotePackage->mPages as $page ) {
			$page->createOrUpdateWikiPage( $user, $this->mName, false, $this->mGlobalID );
		}

		$this->logAction( 'updatepackage', $user );
	}

	public function uninstall( $user, $deleteAll ) {
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

		if ( $deleteAll ) {
			foreach ( $this->mPages as $page ) {
				$page->deleteWikiPage( $user, $this->mName, true );
			}
		}

		$dbw->delete( 'px_packages', [ 'pxp_id' => $this->mID ] );

		$this->logAction( 'uninstallpackage', $user );
	}

}
