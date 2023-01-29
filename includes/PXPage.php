<?php

/**
 * Class for a single page (and, possibly, an associated file) within a
 * package.
 *
 * @author Yaron Koren
 * @ingroup PX
 */

use MediaWiki\MediaWikiServices;

class PXPage {

	private $mName;
	private $mNamespace;
	private $mNamespaceConstant;
	private $mURL;
	private $mFileURL;
	private $mLocalTitle;
	private $mLocalTitleExists;
	private $mLink;
	private $mLocalLink;

	public static function newFromData( $packagePageData, $baseURL ) {
		$page = new PXPage();
		$page->mName = $packagePageData->name;
		if ( property_exists( $packagePageData, 'namespace' ) ) {
			$page->mNamespaceConstant = $packagePageData->namespace;
		} else {
			// NS_MAIN is the default.
			$page->mNamespaceConstant = 'NS_MAIN';
		}
		if ( is_int( $page->mNamespaceConstant ) ) {
			// namespace id already provided
			$page->mNamespace = $page->mNamespaceConstant;
		} elseif ( defined( $page->mNamespaceConstant ) ) {
			// get the namespace id from the provided namespace constant
			$page->mNamespace = constant( $page->mNamespaceConstant );
		}
		if ( $page->mNamespace ) {
			$page->mLocalTitle = Title::makeTitleSafe( $page->mNamespace, $page->mName );
			if ( $page->mLocalTitle == null ) {
				return null;
			}
			$page->mLocalLink = Linker::link( $page->mLocalTitle );
			$page->mLocalTitleExists = $page->mLocalTitle->exists();
			$pageFullName = $page->mLocalTitle->getFullText();
			if ( $page->mNamespace == NS_FILE ) {
				if ( property_exists( $packagePageData, 'fileURL' ) ) {
					$page->mFileURL = $packagePageData->fileURL;
				} elseif ( property_exists( $packagePageData, 'fileURLPath' ) ) {
					$page->mFileURL = $baseURL . $packagePageData->fileURLPath;
				}
				if ( substr( $page->mFileURL, 0, 4 ) !== 'http' ) {
					return null;
				}
			}
		} else {
			// If the specified namespace is not defined on this
			// wiki, we'll just do the best we can.
			$page->mNamespace = $page->mNamespaceConstant;
			$page->mLocalTitleExists = false;
			$pageFullName = $page->mNamespace . ':' . $page->mName;
			$page->mLocalLink = $pageFullName;
		}
		if ( property_exists( $packagePageData, 'url' ) ) {
			$page->mURL = $packagePageData->url;
		} elseif ( property_exists( $packagePageData, 'urlPath' ) ) {
			$page->mURL = $baseURL . $packagePageData->urlPath;
		}
		if ( substr( $page->mURL, 0, 4 ) !== 'http' ) {
			return null;
		}
		//support for multiple content slots
		if ( property_exists( $packagePageData, 'slots' ) ) {
			$page->slots = [];
			foreach ($packagePageData->slots as $slotName => $slot) {
				$page->slots[$slotName] = new stdClass();
				if ( property_exists( $slot, 'url' ) ) {
					$page->slots[$slotName]->mURL = $slot->url;
				} elseif ( property_exists( $slot, 'urlPath' ) ) {
					$page->slots[$slotName]->mURL = $baseURL . $slot->urlPath;
				}
				if ( substr( $page->slots[$slotName]->mURL, 0, 4 ) !== 'http' ) {
					unset($page->slots[$slotName]);
				}
			}
		}
		if ( $page->mNamespace == NS_FILE ) {
			$page->mLink = Html::element( 'a', [ 'href' => $page->mFileURL ], $pageFullName ) . ' (' .
				Html::element( 'a', [ 'href' => $page->mURL ], 'text contents' ) . ')';
		} else {
			$page->mLink = Html::element( 'a', [ 'href' => $page->mURL ], $pageFullName );
		}

		return $page;
	}

	public function getName() {
		return $this->mName;
	}

	public function getNamespace() {
		return $this->mNamespace;
	}

	public function getLocalTitle() {
		return $this->mLocalTitle;
	}

	public function localTitleExists() {
		return $this->mLocalTitleExists;
	}

	public function getLink() {
		return $this->mLink;
	}

	public function getLocalLink() {
		return $this->mLocalLink;
	}

	public function getURL() {
		return $this->mURL;
	}

	public function setURL( $url ) {
		$this->mURL = $url;
	}

	public function getFileURL() {
		return $this->mFileURL;
	}

	public function isGadget() {
		// Ignore any pages named "Gadget-...", or "gadget-...",
		// in the MediaWiki namespace - these are presumably
		// meant to be handled by the Gadgets extension, and
		// thus have a separate loading mechanism.
		return $this->mNamespace == NS_MEDIAWIKI &&
			( substr( $this->mName, 0, 7 ) == 'Gadget-'
			|| substr( $this->mName, 0, 7 ) == 'gadget-'
			);
	}

	public function isJavaScript() {
		return $this->mNamespace == NS_MEDIAWIKI &&
			!$this->isGadget() &&
			substr( $this->mName, -3 ) == '.js';
	}

	public function isCSS() {
		return $this->mNamespace == NS_MEDIAWIKI &&
			!$this->isGadget() &&
			substr( $this->mName, -4 ) == '.css';
	}

	public function getRemoteContents() {
		return PXUtils::getWebPageContents( $this->mURL );
	}

	public function getLocalContents() {
		if ( !$this->mLocalTitleExists ) {
			return null;
		}

		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			// MW 1.36+
			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $this->mLocalTitle );
		} else {
			$wikiPage = new WikiPage( $this->mLocalTitle );
		}
		$content = $wikiPage->getContent();
		if ( $content !== null ) {
			return $content->getNativeData();
		} else {
			return null;
		}
	}

	/**
	 * Create or update a wiki page, and its associated file, if there is
	 * one.
	 */
	public function createOrUpdateWikiPage( $user, $packageName, $isInstall ) {
		// Most of the work - including creating the associated file,
		// if there is one - is actually done by a job.
		$editSummaryMsg = $isInstall ? 'pageexchange-installpackage' : 'pageexchange-updatepackage';
		$editSummary = wfMessage( $editSummaryMsg )->rawParams( $packageName )->inContentLanguage()->parse();

		$jobs = [];
		$params = [
			'page_url' => $this->mURL,
			'user_id' => $user->getID(),
			'edit_summary' => $editSummary
		];
		if ( $this->mNamespace == NS_FILE ) {
			$params['file_url'] = $this->mFileURL;
		}
		if ( property_exists($this, 'slots' ) ) {
			$params['slots'] = $this->slots;
		}
		$jobs[] = new PXCreatePageJob( $this->mLocalTitle, $params );
		if ( method_exists( MediaWikiServices::class, 'getJobQueueGroup' ) ) {
			// MW 1.37+
			MediaWikiServices::getInstance()->getJobQueueGroup()->push( $jobs );
		} else {
			JobQueueGroup::singleton()->push( $jobs );
		}
	}

	/**
	 * Delete a wiki page, and its associated file, if there is one.
	 */
	public function deleteWikiPage( $user, $packageName, $isUninstall ) {
		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			// MW 1.36+
			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $this->mLocalTitle );
		} else {
			$wikiPage = new WikiPage( $this->mLocalTitle );
		}
		$editSummaryMsg = $isUninstall ? 'pageexchange-uninstallpackage' : 'pageexchange-updatepackage';
		$editSummary = wfMessage( $editSummaryMsg )->rawParams( $packageName )->inContentLanguage()->parse();
		$error = '';
		if ( version_compare( MW_VERSION, '1.35', '<' ) ) {
			$wikiPage->doDeleteArticle( $editSummary, false, null, null, $error, $user );
		} else {
			$wikiPage->doDeleteArticleReal( $editSummary, $user, false, null, $error );
		}
		if ( $error != '' ) {
			throw new MWException( $error );
		}
		if ( $this->mNamespace == NS_FILE ) {
			$mwServices = MediaWikiServices::getInstance();
			$file = $mwServices->getRepoGroup()->getLocalRepo()->newFile( $this->mLocalTitle );
			$file->delete( $editSummary );
		}
	}

	public function getPageData() {
		$pageData = [
			'name' => $this->mName,
			'namespace' => $this->mNamespaceConstant,
			'url' => $this->mURL
		];
		if ( $this->mFileURL !== null ) {
			$pageData['fileURL'] = $this->mFileURL;
		}
		return $pageData;
	}
}
