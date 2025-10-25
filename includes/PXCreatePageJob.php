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
			$pageText = PXUtils::getWebPageContents( $this->params['page_url'] );
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

				$slotText = PXUtils::getWebPageContents( $slot->mURL );
				$newSlotContent = ContentHandler::makeContent( $slotText, $this->title, $modelId );
				$updater->setContent( $slotName, $newSlotContent );
			}
		}

		$editSummary = $this->params['edit_summary'];
		$flags = 0;

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

		if ( !array_key_exists( 'file_url', $this->params ) ) {
			return true;
		}

		$fileURL = $this->params['file_url'];
		$this->createOrUpdateFile( $user, $editSummary, $fileURL );

		return true;
	}

	public function createOrUpdateFile( $user, $editSummary, $fileURL ) {
		// Code copied largely from /maintenance/importImages.php.
		$fileContents = PXUtils::getWebPageContents( $fileURL );
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
