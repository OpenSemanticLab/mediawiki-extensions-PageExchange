<?php
/**
 * Class for a "remote" (uninstalled) package.
 *
 * @author Yaron Koren
 * @ingroup PX
 */

class PXRemotePackage extends PXPackage {

	private $mDirectoryNum;
	private $mFileNum;
	private $mMissingExtensions = [];
	private $mMissingPackages = [];
	private $mUninstallableReasons = [];
	private $mIsMatch = true;

	public static function newFromData( $directoryNum, $fileNum, $fileData, $packageName, $packageData, $installedExtensions, $installedPackages, $user ) {
		$package = new PXRemotePackage();

		$package->mName = $packageName;
		$package->mDirectoryNum = $directoryNum;
		$package->mFileNum = $fileNum;
		$package->mUser = $user;
		$package->populateWithData( $fileData, $packageData );
		$package->checkApplicabilityToSite( $installedExtensions, $installedPackages );

		return $package;
	}

	public function getRemoteEquivalent( $installedPage ) {
		foreach ( $this->mPages as $remotePage ) {
			if ( $installedPage->getName() == $remotePage->getName() &&
				$installedPage->getNamespace() == $remotePage->getNamespace() ) {
				return $remotePage;
			}
		}
		return null;
	}

	public function processPages() {
		$matchingPageFound = false;
		$nonMatchingPageFound = false;
		$pageLinks = [];
		if ( count( $this->mPages ) == 0 ) {
			$this->mUninstallableReasons[] = 'no-pages';
		}
		$userCanEditJS = $this->mUser->isAllowed( 'editinterface' ) && $this->mUser->isAllowed( 'editsitejs' );
		$userCanEditCSS = $this->mUser->isAllowed( 'editinterface' ) && $this->mUser->isAllowed( 'editsitecss' );
		foreach ( $this->mPages as $page ) {
			if ( $page == null ) {
				continue;
			}
			if ( $page->getLocalTitle() == null && !in_array( 'bad-namespace', $this->mUninstallableReasons ) ) {
				$this->mUninstallableReasons[] = 'bad-namespace';
			}
			if ( $page->isJavaScript() && !$userCanEditJS ) {
				$this->mUninstallableReasons[] = 'cannot-edit-js';
			} elseif ( $page->isCSS() && !$userCanEditCSS ) {
				$this->mUninstallableReasons[] = 'cannot-edit-css';
			}
			$pageLink = $page->getLink();
			if ( $page->localTitleExists() ) {
				$pageLink = "<em>" . $pageLink . "</em>";
				// $localLink = Linker::link( $page->getLocalTitle(), 'local', [], [ 'action' => 'raw' ] );
				//$pageLink .= " ($localLink)";
				$matchingPageFound = true;
			} else {
				$nonMatchingPageFound = true;
			}
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
		if ( $matchingPageFound ) {
			if ( $nonMatchingPageFound ) {
				$this->mPagesStatus = 'partial';
			} else {
				$this->mPagesStatus = 'complete';
			}
		} else {
			$this->mPagesStatus = 'none';
		}
	}

	public function isMatch() {
		return $this->mIsMatch;
	}

	public function isUninstallable() {
		return count( $this->mUninstallableReasons ) > 0;
	}

	public function getCardBGColor() {
		if ( $this->mIsMatch ) {
			if ( $this->mPagesStatus == 'complete' ) {
				// Possibly installed already
				return [ 254, 246, 231 ];
			} elseif ( $this->mPagesStatus == 'partial' ) {
				return [ 255, 200, 200 ];
			} else {
				return [ 255, 255, 255 ];
			}
		} else {
			return [ 248, 249, 250 ];
		}
	}

	public function getCardHeaderBGColor() {
		if ( $this->mIsMatch ) {
			if ( $this->mPagesStatus == 'complete' ) {
				// Possibly installed already
				return '#fc3';
			} elseif ( $this->mPagesStatus == 'partial' ) {
				return '#d33';
			} else {
				return '#c8ccd1';
			}
		} else {
			return '#a2a9b1';
		}
	}

	public function getCardBodyHTML() {
		global $wgLanguageCode;

		$packageHTML = '';

		if ( $this->mPagesStatus == 'partial' ) {
			$packageHTML .= $this->displayWarningMessage( wfMessage( 'pageexchange-overwrite' )->parse() );
		} elseif ( $this->mPagesStatus == 'complete' ) {
			// Is this message necessary? Or would it be better to just use the "overwrite" message?
			$packageHTML .= $this->displayWarningMessage( 'Every page in this package is already found on your wiki; perhaps you have already unofficially installed it.' );
		}
		if ( $this->isUninstallable() ) {
			$errorMsg = $this->getUninstallableReasonsString();
			$packageHTML .= $this->displayWarningMessage( $errorMsg );
		} elseif ( !$this->mIsMatch ) {
			$packageHTML .= $this->displayWarningMessage( wfMessage( 'pageexchange-inappropriatepackage' )->parse() );
		}

		$packageHTML .= $this->displayDescription();
		$packageHTML .= $this->displayWebsite();
		$publisher = $this->mPublisher;
		if ( $this->mPublisherURL !== null ) {
			$publisher = Html::element( 'a', [ 'href' => $this->mPublisherURL ], $publisher );
		}
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-publisher', $publisher );
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-author', $this->mAuthor );

		$packageHTML .= $this->displayAttribute( 'pageexchange-package-pages', $this->mPagesString );
		$hasMismatchedLanguage = ( $this->mLanguage != '' ) && ( $this->mLanguage != $wgLanguageCode );
		$languageString = $hasMismatchedLanguage ? "<span class=\"error\">{$this->mLanguage}</span>" : $this->mLanguage;
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-language', $languageString, $hasMismatchedLanguage );
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-license', $this->mLicenseName );
		$hasMismatchedExtensions = count( $this->mMissingExtensions ) > 0;
		$packageRequiredExtensionsString = $this->getRequiredExtensionsString();
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-requiredextensions', $packageRequiredExtensionsString, $hasMismatchedExtensions );
		$hasMismatchedPackages = count( $this->mMissingPackages ) > 0;
		$packageRequiredPackagesString = $this->getRequiredPackagesString();
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-requiredpackages', $packageRequiredPackagesString, $hasMismatchedPackages );

		if ( count( $this->mUninstallableReasons ) == 0 ) {
			$packageLink = $this->getPackageLink(
				wfMessage( 'pageexchange-package-install' )->parse(),
				[ 'name' => $this->mName, 'directoryNum' => $this->mDirectoryNum, 'fileNum' => $this->mFileNum ]
			);
			$packageHTML .= Html::rawElement( 'p', [ 'class' => 'pageExchangeCardBottomLink' ], $packageLink );
		}

		return $packageHTML;
	}

	public function getUninstallableReasonsString() {
		$text = wfMessage( 'pageexchange-uninstallablepackage' )->parse();
		$text .= "\n<ul>";
		foreach ( $this->mUninstallableReasons as $reason ) {
			if ( $reason == 'no-pages' ) {
				$text .= '<li>No pages are defined</li>';
			} elseif ( $reason == 'no-identifier' ) {
				$text .= '<li>Lacks a unique global ID</li>';
			} elseif ( $reason == 'bad-namespace' ) {
				$text .= '<li>It uses a namespace not defined on this wiki</li>';
			} elseif ( $reason == 'cannot-edit-js' ) {
				$text .= '<li>You do not have permission to modify JavaScript pages</li>';
			} elseif ( $reason == 'cannot-edit-css' ) {
				$text .= '<li>You do not have permission to modify CSS pages</li>';
			}
		}
		$text .= '</ul>';
		return $text;
	}

	public function getRequiredExtensionsString() {
		if ( $this->mRequiredExtensions == null ) {
			return '';
		}

		$text = '';
		foreach ( $this->mRequiredExtensions as $i => $requiredExt ) {
			if ( $i > 0 ) {
				$text .= ', ';
			}
			if ( in_array( $requiredExt, $this->mMissingExtensions ) ) {
				$text .= "<span class=\"error\">$requiredExt</span>";
			} else {
				$text .= $requiredExt;
			}
		}
		return $text;
	}

	public function getRequiredPackagesString() {
		if ( $this->mRequiredPackages == null ) {
			return '';
		}

		$text = '';
		foreach ( $this->mRequiredPackages as $i => $requiredPackage ) {
			if ( $i > 0 ) {
				$text .= ', ';
			}
			if ( in_array( $requiredPackage, $this->mMissingPackages ) ) {
				$text .= "<span class=\"error\">$requiredPackage</span>";
			} else {
				$text .= $requiredPackage;
			}
		}
		return $text;
	}

	private function checkApplicabilityToSite( $installedExtensions, $installedPackages ) {
		global $wgLanguageCode;

		if ( $this->mGlobalID == null ) {
			$this->mUninstallableReasons[] = 'no-identifier';
		}

		if ( $this->mRequiredExtensions != null ) {
			foreach ( $this->mRequiredExtensions as $requiredExt ) {
				$requiredExtNoSpaces = str_replace( ' ', '', $requiredExt );
				if ( !in_array( $requiredExtNoSpaces, $installedExtensions ) ) {
					$this->mMissingExtensions[] = $requiredExt;
				}
			}
		}

		if ( $this->mRequiredPackages != null ) {
			foreach ( $this->mRequiredPackages as $requiredPackage ) {
				if ( !in_array( $requiredPackage, $installedPackages ) ) {
					$this->mMissingPackages[] = $requiredPackage;
				}
			}
		}

		if ( count( $this->mMissingExtensions ) > 0 || count( $this->mMissingPackages ) > 0 ) {
			$this->mIsMatch = false;
			return;
		}

		if ( $this->mLanguage != '' && $this->mLanguage !== $wgLanguageCode ) {
			$this->mIsMatch = false;
			return;
		}
	}

	public function getFullHTML() {
		$packageHTML = '';
		$packageHTML .= $this->displayDescription();
		$packageHTML .= $this->displayWebsite( true );
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-publisher', $this->mPublisher );
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-author', $this->mAuthor );
		$pagesString = "<ul>\n";
		foreach ( $this->mPages as $page ) {
			$pagesString .= "<li>" . $page->getLink();
			$remoteContents = $page->getRemoteContents();
			if ( $remoteContents == null && !property_exists( $page, 'slots' ) ) {
				$pagesString .= ' - <span class="error">' . wfMessage( 'pageexchange-nocontentsremote' )->parse() . '</span>';
			} elseif ( !$page->localTitleExists() ) {
				// Do nothing.
			} else {
				$localContents = $page->getLocalContents();
				if ( $localContents == $remoteContents ) {
					$pagesString .= ' - there is a local copy of this page that matches the external version.';
				} else {
					$pagesString .= ' - there is a local copy of this page that differs from the external version.';
				}
				if ( $page->getNamespace() == NS_FILE ) {
					$pagesString .= ' (It is unknown whether the local copy of the file itself matches the external version.)';
				}
			}
		}
		$pagesString .= "</ul>\n";
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-pages', $pagesString );
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-language', $this->mLanguage );
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-license', $this->mLicenseName );
		$packageRequiredExtensionsString = $this->getRequiredExtensionsString();
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-requiredextensions', $packageRequiredExtensionsString );
		$packageRequiredPackagesString = $this->getRequiredPackagesString();
		$packageHTML .= $this->displayAttribute( 'pageexchange-package-requiredpackages', $packageRequiredPackagesString );

		if ( $this->isUninstallable() ) {
			$packageHTML .= $this->displayWarningMessage( $this->getUninstallableReasonsString() );
			return $packageHTML;
		}

		$installButton = new OOUI\ButtonInputWidget( [
			'label' => wfMessage( 'pageexchange-package-install' )->parse(),
			'useInputTag' => true,
			'type' => 'submit',
			'name' => 'install',
			'flags' => 'progressive'
		] );

		$packageHTML .= <<<END
<form method="post">
<div style="border-top: 1px #ccc solid; margin-top: 20px; padding: 15px;">
$installButton
</div>
</form>

END;

		return $packageHTML;
	}

	public function getPackageData() {
		$pagesData = [];
		foreach ( $this->mPages as $page ) {
			$pagesData[] = $page->getPageData();
		}
		$packageData = [
			'globalID' => $this->mGlobalID,
			'version' => $this->mVersion,
			'description' => $this->mDescription,
			'publisher' => $this->mPublisher,
			'publisherURL' => $this->mPublisherURL,
			'author' => $this->mAuthor,
			'language' => $this->mLanguage,
			'licenseName' => $this->mLicenseName,
			'url' => $this->mURL,
			'requiredExtensions' => $this->mRequiredExtensions,
			'requiredPackages' => $this->mRequiredPackages,
			'pages' => $pagesData
		];

		return $packageData;
	}

	public function install( $user ) {
		$dbw = wfGetDB( DB_PRIMARY );

		$maxPackageID = $dbw->selectField( 'px_packages', 'MAX(pxp_id)' );
		$packageID = $maxPackageID + 1;

		// We do special storage of MediaWiki:XXX.js and .css pages,
		// because they will then get loaded on every page.
		$jsPages = [];
		$cssPages = [];
		foreach ( $this->mPages as $page ) {
			if ( $page->isJavaScript() ) {
				$jsPages[] = $page->getName();
			} elseif ( $page->isCSS() ) {
				$cssPages[] = $page->getName();
			}
		}
		$jsPagesString = ( count( $jsPages ) > 0 ) ? implode( ',', $jsPages ) : null;
		$cssPagesString = ( count( $cssPages ) > 0 ) ? implode( ',', $cssPages ) : null;
		$installValues = [
			'pxp_id' => $packageID,
			'pxp_date_installed' => 'NOW()',
			'pxp_installing_user' => $user->getID(),
			'pxp_name' => $this->mName,
			'pxp_global_id' => $this->mGlobalID,
			'pxp_js_pages' => $jsPagesString,
			'pxp_css_pages' => $cssPagesString,
			'pxp_package_data' => json_encode( $this->getPackageData() )
		];
		$dbw->insert( 'px_packages', $installValues );

		// Pre-populate cache with all page contents before creating jobs.
		$this->prefetchPageContents();

		foreach ( $this->mPages as $page ) {
			$page->createOrUpdateWikiPage( $user, $this->mName, true );
		}

		$this->logAction( 'installpackage', $user );
	}

	public function prefetchPageContents() {
		// For GitHub-based packages, download the entire repo as a single zip.
		$gitHubInfo = $this->getGitHubRepoInfo();
		if ( $gitHubInfo !== null ) {
			// Skip zip download if all content is already cached (e.g., from a previous install).
			// Only check main URL AND slot URLs — detail page view only caches main URLs,
			// so a cached slot URL proves a prior zip download covered everything.
			if ( !empty( $this->mPages ) ) {
				$firstPage = $this->mPages[0];
				if ( $firstPage !== null && PXUtils::getCached( $firstPage->getURL() ) !== null ) {
					$allCached = true;
					if ( property_exists( $firstPage, 'slots' ) ) {
						foreach ( $firstPage->slots as $slot ) {
							if ( $slot->mURL !== null && PXUtils::getCached( $slot->mURL ) === null ) {
								$allCached = false;
								break;
							}
						}
					}
					if ( $allCached ) {
						return;
					}
				}
			}
			PXUtils::downloadGitHubRepoContents(
				$gitHubInfo['account'],
				$gitHubInfo['repo'],
				$gitHubInfo['branch']
			);
			return;
		}

		// For non-GitHub packages, fetch all page URLs in parallel.
		$urls = [];
		foreach ( $this->mPages as $page ) {
			if ( $page === null ) {
				continue;
			}
			$url = $page->getURL();
			if ( $url !== null ) {
				$urls[] = $url;
			}
			$fileURL = $page->getFileURL();
			if ( $fileURL !== null ) {
				$urls[] = $fileURL;
			}
			if ( property_exists( $page, 'slots' ) ) {
				foreach ( $page->slots as $slot ) {
					if ( $slot->mURL !== null ) {
						$urls[] = $slot->mURL;
					}
				}
			}
		}
		if ( !empty( $urls ) ) {
			PXUtils::getWebPageContentsBatch( $urls );
		}
	}

}
