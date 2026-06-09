<?php
/**
 * Abstract parent class for package classes.
 *
 * @author Yaron Koren
 * @ingroup PX
 */

use MediaWiki\MediaWikiServices;

abstract class PXPackage {

	protected $mName;
	protected $mVersion;
	protected $mGlobalID;
	protected $mDescription;
	protected $mPages = [];
	protected $mPagesString;
	protected $mPagesStatus;
	protected $mPublisher;
	protected $mPublisherURL;
	protected $mAuthor;
	protected $mLanguage;
	protected $mURL;
	protected $mLicenseName;
	protected $mRequiredExtensions = [];
	protected $mRequiredPackages = [];
	protected $mUser;
	protected $mGitHubAccount;
	protected $mGitHubRepo;
	protected $mGitHubBranch;

	public function populateWithData( $fileData, $packageData, $packageFileURL = null ) {
		// If the packages.json itself was served from a non-default GitHub
		// branch, rewrite any same-repo URLs in the package data (baseURL,
		// pages[].url, pages[].fileURL, pages[].slots[].url) to point at
		// that same branch — so installing/updating from a branch doesn't
		// require editing the upstream packages.json.
		if ( $packageFileURL !== null ) {
			$pxBranch = PXUtils::parseGitHubBranchFromURL( $packageFileURL );
			$pxOrgRepo = PXUtils::parseGitHubOrgRepoFromURL( $packageFileURL );
			if ( $pxBranch !== null && $pxOrgRepo !== null ) {
				self::rewriteGitHubBranchInPackageData( $fileData, $pxOrgRepo, $pxBranch );
				self::rewriteGitHubBranchInPackageData( $packageData, $pxOrgRepo, $pxBranch );
			}
		}

		$baseURL = self::getPackageField( 'baseURL', $fileData, $packageData );
		$pagesData = self::getPackageField( 'pages', $fileData, $packageData, false );
		if ( $pagesData !== null ) {
			foreach ( $pagesData as $pageData ) {
				$page = PXPage::newFromData( $pageData, $baseURL );
				if ( $page === null ) {
					continue;
				}
				$this->mPages[] = $page;
			}
		}
		$directoryStructureData = self::getPackageField( 'directoryStructure', null, $packageData );
		if ( $directoryStructureData != null && property_exists( $directoryStructureData, 'service' ) ) {
			$directoryStructureService = $directoryStructureData->service;
			if ( $directoryStructureService == 'GitHub' ) {
				self::addToPagesFromGitHubData( $directoryStructureData, $packageFileURL );
			}
		}
		$this->processPages();
		$this->mVersion = self::getPackageField( 'version', null, $packageData );
		$this->mGlobalID = self::getPackageField( 'globalID', null, $packageData );
		$this->mDescription = self::getPackageField( 'description', null, $packageData, true, true );
		$this->mPublisher = self::getPackageField( 'publisher', $fileData, $packageData );
		$this->mPublisherURL = self::getPackageField( 'publisherURL', $fileData, $packageData );
		if ( substr( $this->mPublisherURL, 0, 4 ) !== 'http' ) {
			$this->mPublisherURL = null;
		}
		$this->mAuthor = self::getPackageField( 'author', $fileData, $packageData );
		$this->mLanguage = self::getPackageField( 'language', $fileData, $packageData );
		$this->mURL = self::getPackageField( 'url', $fileData, $packageData );
		if ( substr( $this->mURL, 0, 4 ) !== 'http' ) {
			$this->mURL = null;
		}
		$this->mLicenseName = self::getPackageField( 'licenseName', $fileData, $packageData );
		$this->mRequiredExtensions = self::getPackageField( 'requiredExtensions', $fileData, $packageData );
		$this->mRequiredPackages = self::getPackageField( 'requiredPackages', $fileData, $packageData );
	}

	public static function getSpecialPageTitle( $pageName ) {
		return MediaWikiServices::getInstance()
			->getSpecialPageFactory()
			->getPage( $pageName )
			->getPageTitle();
	}

	public static function getPackageField( $fieldName, $fileData, $packageData, $escapeHTML = true, $isWikitext = false ) {
		if ( $packageData === null ) {
			return null;
		}
		if ( property_exists( $packageData, $fieldName ) ) {
			$value = $packageData->$fieldName;
		} elseif ( $fileData !== null && property_exists( $fileData, $fieldName ) ) {
			$value = $fileData->$fieldName;
		} else {
			return null;
		}
		if ( $isWikitext ) {
			$mwServices = MediaWikiServices::getInstance();
			$parser = $mwServices->getParser();
			$packagesTitle = self::getSpecialPageTitle( 'Packages' );
			return $parser->parse( $value, $packagesTitle, ParserOptions::newFromAnon(), false )->getText();
		}
		if ( !$escapeHTML ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			return array_map( function( $v ) {
				return htmlspecialchars( $v, ENT_QUOTES, 'UTF-8', false );
			}, $value );
		}
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false );
	}

	public function getName() {
		return $this->mName;
	}

	public function getGlobalID() {
		return $this->mGlobalID;
	}

	public function getCardStatusClass() {
		return 'pageExchangeCard--default';
	}

	public function getCardBodyHTML() {
		return '';
	}

	public function displayCard() {
		$statusClass = $this->getCardStatusClass();
		$packageHTML = <<<END
<div class="pageExchangeCardWrapper">
<div class="pageExchangeCard {$statusClass}">
<div class="pageExchangeCardHeader">
{$this->mName}
</div>

END;
		$packageHTML .= $this->getCardBodyHTML();
		$packageHTML .= "</div>\n</div>\n";

		return $packageHTML;
	}

	public function displayAttribute( $attrMsg, $value, $hasError = false ) {
		if ( $value == '' ) {
			return '';
		}
		$text = '<p>';
		if ( $hasError ) {
			$text .= new OOUI\IconWidget( [
				'icon' => 'error',
				'flags' => 'warning',
				'title' => 'Error'
			] ) . ' ';
		}
		if ( $attrMsg != '' ) {
			$text .= '<strong>' . wfMessage( $attrMsg )->parse() . '</strong> ';
		}
		if ( is_array( $value ) ) {
			$text .= implode( ', ', $value );
		} else {
			$text .= $value;
		}
		$text .= "</p>\n";
		return $text;
	}

	public function displayDescription() {
		if ( $this->mDescription == null ) {
			return '<p><em>(No description)</em></p>';
		}

		return $this->mDescription;
	}

	public function displayWebsite( $showURL = false ) {
		if ( $this->mURL == null ) {
			return '';
		}
		$linkText = $showURL ? $this->mURL : 'Link';
		$link = Html::element( 'a', [ 'href' => $this->mURL ], $linkText );
		return $this->displayAttribute( 'pageexchange-package-website', $link );
	}

	public function displayInfoMessage( $msg ) {
		$text = <<<END
<table class="pageExchangeInfoMessage">
<tr>
<td>

END;
		$text .= new OOUI\IconWidget( [
			'icon' => 'notice',
			// 'title' => 'Notice'
		] );

		$text .= <<<END
</td>
<td>
<div>$msg</div>
</td>
</tr>
</table>

END;

		return $text;
	}

	public function displayWarningMessage( $msg ) {
		$text = <<<END
<table class="pageExchangeWarningMessage">
<tr>
<td>

END;
		$text .= new OOUI\IconWidget( [
			'icon' => 'notice', // 'warning',
			//'title' => 'Notice'
		] );

		$text .= <<<END
</td>
<td>
<div class="error">$msg</div>
</td>
</tr>
</table>

END;

		return $text;
	}

	private static function rewriteGitHubBranchInPackageData( $data, $orgRepo, $branch ) {
		if ( $data === null || !is_object( $data ) ) {
			return;
		}
		if ( property_exists( $data, 'baseURL' ) && is_string( $data->baseURL ) ) {
			$data->baseURL = PXUtils::rewriteGitHubBranchInURL( $data->baseURL, $orgRepo, $branch );
		}
		if ( property_exists( $data, 'pages' ) ) {
			$pages = $data->pages;
			if ( is_array( $pages ) || is_object( $pages ) ) {
				foreach ( $pages as $page ) {
					if ( !is_object( $page ) ) {
						continue;
					}
					if ( property_exists( $page, 'url' ) && is_string( $page->url ) ) {
						$page->url = PXUtils::rewriteGitHubBranchInURL( $page->url, $orgRepo, $branch );
					}
					if ( property_exists( $page, 'fileURL' ) && is_string( $page->fileURL ) ) {
						$page->fileURL = PXUtils::rewriteGitHubBranchInURL( $page->fileURL, $orgRepo, $branch );
					}
					if ( property_exists( $page, 'slots' ) && ( is_array( $page->slots ) || is_object( $page->slots ) ) ) {
						foreach ( $page->slots as $slot ) {
							if ( is_object( $slot ) && property_exists( $slot, 'url' ) && is_string( $slot->url ) ) {
								$slot->url = PXUtils::rewriteGitHubBranchInURL( $slot->url, $orgRepo, $branch );
							}
						}
					}
				}
			}
		}
	}

	protected function addToPagesFromGitHubData( $gitHubData, $packageFileURL = null ) {
		if (
			!property_exists( $gitHubData, 'accountName' ) ||
			!property_exists( $gitHubData, 'repositoryName' ) ||
			!property_exists( $gitHubData, 'namespaceSettings' )
		) {
			return;
		}
		$accountName = $gitHubData->accountName;
		$repositoryName = $gitHubData->repositoryName;
		$namespaceSettings = $gitHubData->namespaceSettings;
		$this->mGitHubAccount = $accountName;
		$this->mGitHubRepo = $repositoryName;
		$allGitHubPages = [];

		// Resolve the branch up front when possible: explicit override in
		// the package data, otherwise derived from the packages.json URL.
		// This is the fix for child pages always being fetched from "main".
		$explicitBranch = null;
		if ( property_exists( $gitHubData, 'branch' ) && is_string( $gitHubData->branch ) && $gitHubData->branch !== '' ) {
			$explicitBranch = $gitHubData->branch;
		} elseif ( $packageFileURL !== null ) {
			$explicitBranch = PXUtils::parseGitHubBranchFromURL( $packageFileURL );
		}

		if ( $explicitBranch !== null ) {
			$defaultBranch = $explicitBranch;
			$gitHubAPIURL = "https://api.github.com/repos/$accountName/$repositoryName/git/trees/"
				. rawurlencode( $defaultBranch ) . "?recursive=1";
			$gitHubPagesJSON = PXUtils::getWebPageContents( $gitHubAPIURL );
			if ( $gitHubPagesJSON == '' ) {
				throw new MWException( "No data found at https://github.com/$accountName/$repositoryName on branch $defaultBranch" );
			}
		} else {
			$defaultBranch = 'main';
			$gitHubAPIURL = "https://api.github.com/repos/$accountName/$repositoryName/git/trees/main?recursive=1";
			$gitHubPagesJSON = PXUtils::getWebPageContents( $gitHubAPIURL );
			// GitHub changed the default branch for new repos from "master" to "main" in 2020.
			if ( $gitHubPagesJSON == '' ) {
				$defaultBranch = 'master';
				$gitHubAPIURL = "https://api.github.com/repos/$accountName/$repositoryName/git/trees/master?recursive=1";
				$gitHubPagesJSON = PXUtils::getWebPageContents( $gitHubAPIURL );
			}
			if ( $gitHubPagesJSON == '' ) {
				throw new MWException( "No data found at https://github.com/$accountName/$repositoryName" );
			}
		}
		$this->mGitHubBranch = $defaultBranch;
		$gitHubPagesData = json_decode( $gitHubPagesJSON );
		$gitHubPageNames = [];
		foreach ( $gitHubPagesData->tree as $gitHubPageData ) {
			$gitHubPageNames[] = $gitHubPageData->path;
		}
		// Build raw page data without creating PXPage objects (deferred to materializeGitHubPages)
		$this->mPendingGitHubPageData = [];
		foreach ( $gitHubPageNames as $gitHubPageName ) {
			$pageName = $gitHubPageName;
			foreach ( $namespaceSettings as $settings ) {
				if ( property_exists( $settings, 'fileNamePrefix' ) ) {
					$fileNamePrefix = $settings->fileNamePrefix;
					if ( strpos( $pageName, $fileNamePrefix ) === 0 ) {
						$pageName = substr_replace( $pageName, '', 0, strlen( $fileNamePrefix ) );
					} else {
						continue;
					}
				}
				if ( property_exists( $settings, 'fileNameSuffix' ) ) {
					$fileNameSuffix = $settings->fileNameSuffix;
					$suffixLen = strlen( $fileNameSuffix );
					if ( substr_compare( $pageName, $fileNameSuffix, -$suffixLen ) === 0 ) {
						$pageName = substr_replace( $pageName, '', -$suffixLen, $suffixLen );
					} else {
						continue;
					}
				}
				$pageURL = "https://raw.githubusercontent.com/$accountName/$repositoryName/$defaultBranch/" .
					rawurlencode( $gitHubPageName );
				$pageData = (object)[
					'name' => $pageName,
					'namespace' => $settings->namespace,
					'url' => $pageURL
				];
				if ( $settings->namespace == 'NS_FILE' ) {
					$actualFileName = $settings->actualFileNamePrefix .
						$pageName . $settings->actualFileNameSuffix;
					if ( in_array( $actualFileName, $gitHubPageNames ) ) {
						$pageData->fileURL = "https://raw.githubusercontent.com/$accountName/$repositoryName/$defaultBranch/" .
							rawurlencode( $actualFileName );
					}
				}
				$this->mPendingGitHubPageData[] = $pageData;
			}
		}
		$this->mGitHubPageCount = count( $this->mPendingGitHubPageData );
	}

	/**
	 * Create PXPage objects from deferred GitHub data.
	 * Called on demand when pages are actually needed (display or install).
	 */
	public function materializeGitHubPages() {
		if ( empty( $this->mPendingGitHubPageData ) ) {
			return;
		}
		foreach ( $this->mPendingGitHubPageData as $pageData ) {
			$page = PXPage::newFromData( $pageData, null );
			if ( $page !== null ) {
				$this->mPages[] = $page;
			}
		}
		$this->mPendingGitHubPageData = [];
	}

	/**
	 * Get total page count including pending (not yet materialized) GitHub pages.
	 */
	public function getTotalPageCount() {
		return count( $this->mPages ) + ( $this->mGitHubPageCount ?? 0 );
	}

	public function getGitHubRepoInfo() {
		if ( $this->mGitHubAccount === null || $this->mGitHubRepo === null ) {
			return null;
		}
		return [
			'account' => $this->mGitHubAccount,
			'repo' => $this->mGitHubRepo,
			'branch' => $this->mGitHubBranch ?? 'main'
		];
	}

	public function prefetchForDisplay() {
		$urls = [];
		foreach ( $this->mPages as $page ) {
			if ( $page === null ) {
				continue;
			}
			$url = $page->getURL();
			if ( $url !== null ) {
				$urls[] = $url;
			}
		}
		if ( !empty( $urls ) ) {
			PXUtils::getWebPageContentsBatch( $urls );
		}
	}

	abstract public function processPages();

	abstract public function getFullHTML();

	public function getPackageLink( $linkText, $query ) {
		$packagesTitle = self::getSpecialPageTitle( 'Packages' );
		$packageURL = $packagesTitle->getLocalURL( $query );
		return Html::element( 'a', [ 'href' => $packageURL ], $linkText );
	}

	public function logAction( $actionName, User $user ) {
		$log = new LogPage( 'pageexchange', false );

		$packagesTitle = self::getSpecialPageTitle( 'Packages' );
		$logParams = [
			$this->mName,
			$this->mPublisher
		];

		// Every log entry requires an associated title; these
		// actions don't involve an actual page, so we just use
		// Special:Packages as the title.
		$log->addEntry( $actionName, $packagesTitle, '', $logParams, $user );
	}

}
