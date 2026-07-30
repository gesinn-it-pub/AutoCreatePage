<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoCreatePage;

use CommentStoreComment;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\SpecialPage\Hook\SpecialPageAfterExecuteHook;
use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;
use MediaWiki\Storage\Hook\RevisionDataUpdatesHook;
use MediaWiki\User\UserIdentity;
use Parser;
use Title;
use WikitextContent;

/**
 * This extension provides a parser function #createpageifnotex that can be used to create
 * additional auxiliary pages when a page is saved. New pages are only created if they do
 * not exist yet. The function takes two parameters: (1) the title of the new page,
 * (2) the text to be used on the new page. It is possible to use &lt;nowiki&gt; tags in the
 * text to insert wiki markup more conveniently.
 *
 * The created page is attributed to the user who made the edit. The original idea for this
 * code was developed by Daniel Herzig at AIFB Karlsruhe. In his code, there were some further
 * facilities to show a message to the user about the pages that have been auto-created. This
 * is not implemented here yet (the basic way of doing this would be to insert some custom
 * HTML during 'OutputPageBeforeHTML').
 *
 * The code restricts the use of the parser function to MediaWiki content namespaces. So
 * templates, for example, cannot create new pages by accident. Also, the code prevents created
 * pages from creating further pages to avoid (unbounded) chains of page creations.
 *
 * @author Markus Kroetzsch
 * @author Daniel Herzig
 * @file
 */
class AutoCreatePage implements
	ParserFirstCallInitHook,
	RevisionDataUpdatesHook,
	SpecialPageBeforeExecuteHook,
	SpecialPageAfterExecuteHook
{

	/**
	 * @var SpecialPage[]
	 */
	private static array $specialPageStack = [];

	/**
	 * Recursion depth reached so far for a given page, keyed by prefixed DB key. A page not
	 * present here is at depth 0 (not itself the result of an auto-creation, or unknown).
	 *
	 * This tracks the depth of each individual creation chain directly, rather than a single
	 * shared counter: MediaWiki's RevisionDataUpdates hook for a created page runs via the
	 * DeferredUpdates queue, i.e. only after the save that created it (and any counter
	 * decrement/restore surrounding that save) has already completed. A single global counter
	 * can therefore not reliably observe the depth at the time the created page is itself parsed
	 * (verified against MW 1.39/1.43: the child's canonical parse - the one that evaluates its
	 * own #createpageifnotex calls - runs after the parent's onRevisionDataUpdates returns).
	 *
	 * Entries are intentionally never removed: a page created earlier in this process must not
	 * be able to recurse again as if seen for the first time. Per-title entries stay small in
	 * practice (bounded by the number of auto-created pages, not by every page saved in the
	 * process).
	 *
	 * @var array<string, int>
	 */
	private static array $creationDepthByTitle = [];

	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'createPage', [ self::class, 'createPageIfNotExisting' ] );
	}

	/**
	 * Handles the parser function for creating pages that don't exist yet,
	 * filling them with the given default content. It is possible to use &lt;nowiki&gt;
	 * in the default text parameter to insert verbatim wiki text.
	 */
	public static function createPageIfNotExisting(
		Parser $parser,
		string $newPageTitleText,
		string $newPageContent
	): string {
		global $egAutoCreatePageMaxRecursion, $egAutoCreatePageIgnoreEmptyTitle,
			   $egAutoCreatePageNamespaces, $wgContentNamespaces;

		$autoCreatePageNamespaces = $egAutoCreatePageNamespaces ?? $wgContentNamespaces;

		$currentDepth = self::$creationDepthByTitle[$parser->getTitle()->getPrefixedDBkey()] ?? 0;
		if ( $currentDepth >= $egAutoCreatePageMaxRecursion ) {
			return 'Error: Recursion level for auto-created pages exceeded.'; // TODO i18n
		}

		if ( $newPageTitleText === '' ) {
			if ( $egAutoCreatePageIgnoreEmptyTitle === false ) {
				return 'Error: this function must be given a valid title text for the page to be created.'; // TODO i18n
			}
			return '';
		}

		// Get the raw text of $newPageContent as it was before stripping <nowiki>:
		$newPageContent = $parser->getStripState()->unstripNoWiki( $newPageContent );
		$newPageData = [
			'content' => $newPageContent,
			'user' => $parser->getUserIdentity(),
			'depth' => $currentDepth + 1,
		];

		$enabledSpecialPage = self::enabledSpecialPage();
		if ( $enabledSpecialPage ) {
			// Store data in static variable for later use in onSpecialPageAfterExecute:
			$enabledSpecialPage->addPageToCreate( $newPageTitleText, $newPageData );
		} elseif ( in_array( $parser->getTitle()->getNamespace(), $autoCreatePageNamespaces ) ) {
			// For pages with namespace in $autoCreatePageNamespaces store data in the parser output
			// for later use in onRevisionDataUpdates:
			$createPageData = $parser->getOutput()->getExtensionData( 'createPage' );
			if ( $createPageData === null ) {
				$createPageData = [];
			}
			$createPageData[$newPageTitleText] = $newPageData;
			$parser->getOutput()->setExtensionData( 'createPage', $createPageData );
		}

		return '';
	}

	/**
	 * Create pages that have been requested by the create page parser function for pages with
	 * namespace in $egAutoCreatePageNamespaces.
	 * This is done only after the safe is complete to avoid any concurrent article modifications.
	 */
	public function onRevisionDataUpdates( $title, $renderedRevision, &$updates ) {
		$output = $renderedRevision->getRevisionParserOutput();
		$createPageData = $output->getExtensionData( 'createPage' );
		if ( $createPageData === null ) {
			return true; // no pages to create
		}

		foreach ( $createPageData as $pageTitleText => $pageContentData ) {
			self::createPage( $title, $pageTitleText, $pageContentData );
		}

		// Reset state. Probably not needed since parsing is usually done here anyway:
		$output->setExtensionData( 'createPage', null );

		return true;
	}

	/**
	 * Register special page in static $specialPageStack
	 */
	public function onSpecialPageBeforeExecute( $special, $subPage ) {
		global $egAutoCreatePageOnSpecialPages;

		$isEnabled = in_array( $special->getName(), $egAutoCreatePageOnSpecialPages );
		self::$specialPageStack[] = new SpecialPage( $isEnabled );
	}

	/**
	 * Create pages requested on enabled special pages
	 */
	public function onSpecialPageAfterExecute( $special, $subPage ) {
		$specialPage = array_pop( self::$specialPageStack );

		if ( $specialPage->isEnabled() ) {
			foreach ( $specialPage->pagesToCreate() as $pageTitleText => $pageContentData ) {
				self::createPage( $special->getFullTitle(), $pageTitleText, $pageContentData );
			}
		}
	}

	/**
	 * @return SpecialPage|null the current special page if it is enabled, null otherwise
	 */
	private static function enabledSpecialPage(): ?SpecialPage {
		$count = count( self::$specialPageStack );
		$currentSpecialPage = $count === 0 ? null : self::$specialPageStack[ $count - 1 ];
		return $currentSpecialPage !== null && $currentSpecialPage->isEnabled() ? $currentSpecialPage : null;
	}

	/**
	 * @param Title $sourceTitle
	 * @param string $pageTitleText
	 * @param array{content: string, user: UserIdentity, depth: int} $pageContentData
	 */
	private static function createPage(
		Title $sourceTitle,
		string $pageTitleText,
		array $pageContentData
	): void {
		$pageContent = new WikitextContent( $pageContentData['content'] );
		/** @var UserIdentity $author */
		$author = $pageContentData['user'];
		$sourceTitleText = $sourceTitle->getPrefixedText();
		$pageTitle = Title::newFromText( $pageTitleText );

		if ( $pageTitle !== null && !$pageTitle->isKnown() && $pageTitle->canExist() ) {
			$comment = CommentStoreComment::newUnsavedComment(
				"Page created automatically by parser function on page [[$sourceTitleText]]" // TODO i18n
			);
			self::$creationDepthByTitle[$pageTitle->getPrefixedDBkey()] = $pageContentData['depth'];
			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $pageTitle );
			$updater = $wikiPage->newPageUpdater( $author );
			$updater->setContent( SlotRecord::MAIN, $pageContent );
			$updater->saveRevision( $comment );
		}
	}
}
