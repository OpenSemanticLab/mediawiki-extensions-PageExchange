<?php
/**
 *
 * @file
 * @ingroup PX
 */

use MediaWiki\MediaWikiServices;
use Wikimedia\AtEase\AtEase;

/**
 * Background job to create a new page.
 *
 * @author Yaron Koren
 * @ingroup PX
 */
class PXCreatePageJob extends Job {

	function __construct( Title $title, array $params ) {
		parent::__construct( 'pageExchangeCreatePage', $title, $params );
		$this->removeDuplicates = true;
	}

	/**
	 * Run a pageExchangeCreatePage job
	 * @return bool success
	 */
	function run() {
		if ( $this->title === null ) {
			$this->error = "pageExchangeCreatePage: Invalid title";
			return false;
		}

		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			// MW 1.36+
			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $this->title );
		} else {
			$wikiPage = new WikiPage( $this->title );
		}
		if ( !$wikiPage ) {
			$this->error = 'pageExchangeCreatePage: Wiki page not found "' . $this->title->getPrefixedDBkey() . '"';
			return false;
		}

		// Fetch everything before saving anything, so that a failed fetch leaves the page,
		// its slots and its file unchanged. An empty response (200) is valid content.
		$pageText = null;
		if ( array_key_exists( 'page_url', $this->params ) ) {
			$pageText = PXUtils::getWebPageContentsOrFail( $this->params['page_url'] );
			if ( $pageText === false ) {
				$this->error = 'pageExchangeCreatePage: Could not fetch ' . $this->params['page_url'];
				return false;
			}
		}
		$slotTexts = [];
		if ( array_key_exists( 'slots', $this->params ) ) {
			foreach ( $this->params['slots'] as $slotName => $slot ) {
				$slotTexts[$slotName] = PXUtils::getWebPageContentsOrFail( $slot->mURL );
				if ( $slotTexts[$slotName] === false ) {
					$this->error = 'pageExchangeCreatePage: Could not fetch ' . $slot->mURL;
					return false;
				}
			}
		}
		$fileContents = null;
		if ( array_key_exists( 'file_url', $this->params ) ) {
			$fileContents = PXUtils::getWebPageContentsOrFail( $this->params['file_url'] );
			if ( $fileContents === false ) {
				$this->error = 'pageExchangeCreatePage: Could not fetch ' . $this->params['file_url'];
				return false;
			}
		}

		$userID = $this->params['user_id'];
		if ( class_exists( 'MediaWiki\User\UserFactory' ) ) {
			// MW 1.35+
			$user = MediaWikiServices::getInstance()
				->getUserFactory()
				->newFromId( (int)$userID );
		} else {
			$user = User::newFromId( (int)$userID );
		}

		$updater = $wikiPage->newPageUpdater( $user );

		if ( array_key_exists( 'page_url', $this->params ) ) {
			if ( $pageText === '' ) {
				$msg = 'PageExchange: empty content fetched for page "' .
					$this->title->getPrefixedDBkey() . '" from ' . $this->params['page_url'];
				// Empty main slot is expected for pages with named slots only (e.g. jsondata, jsonschema)
				if ( array_key_exists( 'slots', $this->params ) ) {
					wfDebugLog( 'PageExchange', $msg );
				} else {
					wfLogWarning( $msg );
				}
			}
			$newContent = ContentHandler::makeContent( $pageText, $this->title );
			$updater->setContent( MediaWiki\Revision\SlotRecord::MAIN, $newContent );
		}

		if ( array_key_exists( 'slots', $this->params ) ) {
			$oldRevisionRecord = $wikiPage->getRevisionRecord();
            $slotRoleRegistry = MediaWikiServices::getInstance()->getSlotRoleRegistry();

			foreach ($this->params['slots'] as $slotName => $slot) {
				if ( $oldRevisionRecord !== null && $oldRevisionRecord->hasSlot( $slotName ) ) {
					$modelId = $oldRevisionRecord
						->getSlot( $slotName )
						->getContent()
						->getContentHandler()
						->getModelID();
				} else {
					$modelId = $slotRoleRegistry
						->getRoleHandler( $slotName )
						->getDefaultModel( $this->title );
				}

				$slotText = $slotTexts[$slotName];
				if ( $slotText === '' ) {
					wfLogWarning( 'PageExchange: empty slot "' . $slotName . '" fetched for page "' .
						$this->title->getPrefixedDBkey() . '" from ' . $slot->mURL );
				}
				$newSlotContent = ContentHandler::makeContent( $slotText, $this->title, $modelId );
				$updater->setContent( $slotName, $newSlotContent );
			}
		}

		$editSummary = $this->params['edit_summary'];
		$flags = EDIT_FORCE_BOT;

		$updater->saveRevision( CommentStoreComment::newUnsavedComment( $editSummary ), $flags );

		$mediaWikiConfig = MediaWikiServices::getInstance()->getMainConfig();
		$protectPages = $mediaWikiConfig->get( 'PageExchangeProtectInstalledPages' );
		// check if protectPages us a non-empty string (default: 'sysop')
		if ( is_string( $protectPages ) && trim( $protectPages ) !== '' ) {
			// Set page protection to the specified level
			$cascade = true;
			// Update protection
			$protectionStatus = $wikiPage->doUpdateRestrictions(
				// $limit: Set of restriction keys
				[ 'edit' => $protectPages, 'move' => $protectPages ],
				// $limit: Set of restriction keys
				[],
				// $cascade: Also protect 
				$cascade,
				"Protected as read-only import via Page Exchange extension",
				$user
			);

			if ( !$protectionStatus->isOK() ) {
				$this->error = 'pageExchangeCreatePage: Failed to set page protection: ' . 
					$protectionStatus->getWikiText();
			}
		}
		else {
			// unprotect page
			$cascade = true;
			// Update protection
			$protectionStatus = $wikiPage->doUpdateRestrictions(
				// $limit: Set of restriction keys
				[ 'edit' => '', 'move' => '' ],
				// $limit: Set of restriction keys
				[],
				// $cascade: Also protect 
				$cascade,
				"Un-Protect during page import/update via Page Exchange extension",
				$user
			);

			if ( !$protectionStatus->isOK() ) {
				$this->error = 'pageExchangeCreatePage: Failed to unset page protection: ' . 
					$protectionStatus->getWikiText();
			}			
		}

		// If this is a template, and Cargo is installed, tell Cargo
		// to automatically generate the table declared in this
		// template, if there is one.
		// @TODO - add a checkbox to the "install" page, to let the
		// user choose whether to create the table?
		if ( $this->title->getNamespace() == NS_TEMPLATE && class_exists( 'CargoDeclare' ) ) {
			CargoDeclare::$settings['createData'] = true;
			CargoDeclare::$settings['userID'] = $userID;
		}

		if ( $fileContents === null ) {
			return true;
		}

		$this->createOrUpdateFile( $user, $editSummary, $fileContents );

		return true;
	}

	public function createOrUpdateFile( $user, $editSummary, $fileContents ) {
		// Code copied largely from /maintenance/importImages.php.
		$tempFile = tmpfile();
		fwrite( $tempFile, $fileContents );
		$tempFilePath = stream_get_meta_data( $tempFile )['uri'];
		$mwServices = MediaWikiServices::getInstance();
		$file = $mwServices->getRepoGroup()->getLocalRepo()->newFile( $this->title );

		$mwProps = new MWFileProps( $mwServices->getMimeAnalyzer() );
		$props = $mwProps->getPropsFromPath( $tempFilePath, true );
		$flags = 0;
		$publishOptions = [];
		$handler = MediaHandler::getHandler( $props['mime'] );
		if ( $handler ) {
			$publishOptions['headers'] = $handler->getContentHeaders( $props['metadata'] );
		} else {
			$publishOptions['headers'] = [];
		}
		$archive = $file->publish( $tempFilePath, $flags, $publishOptions );
		if ( is_callable( [ $file, 'recordUpload3' ] ) ) {
			// MW 1.35+
			$file->recordUpload3(
				$archive->value,
				$editSummary,
				$editSummary, // What does this get used for?
				$user,
				$props
			);
		} else {
			$file->recordUpload2(
				$archive->value,
				$editSummary,
				$editSummary, // What does this get used for?
				$props,
				$timestamp = false,
				$user
			);
		}
	}

}
