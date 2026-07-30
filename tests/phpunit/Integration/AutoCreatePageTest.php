<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoCreatePage\Tests\Integration;

use FauxRequest;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use ParserOptions;
use RequestContext;
use Title;
use WikiPage;

/**
 * @covers \MediaWiki\Extension\AutoCreatePage\AutoCreatePage
 * @group Database
 */
class AutoCreatePageTest extends MediaWikiIntegrationTestCase {

	private const EXPECTED_TEXT = 'expected text';
	private static int $defaultAutoCreatePageMaxRecursion;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		global $egAutoCreatePageMaxRecursion;
		self::$defaultAutoCreatePageMaxRecursion = $egAutoCreatePageMaxRecursion;
	}

	protected function setUp(): void {
		parent::setUp();
		global $egAutoCreatePageMaxRecursion;
		$egAutoCreatePageMaxRecursion = self::$defaultAutoCreatePageMaxRecursion;
	}

	protected function tearDown(): void {
		parent::tearDown();
		global $egAutoCreatePageOnSpecialPages;
		$egAutoCreatePageOnSpecialPages = [];
	}

	public function testCreatesPage(): void {
		[ $x, $y ] = $this->randomize( [ 'x', 'y' ] );
		$this->insertPage( $x, "{{#createpageifnotex:$y|" . self::EXPECTED_TEXT . "}}" );

		$text = $this->textOf( $y );
		$this->assertEquals( self::EXPECTED_TEXT, $text );

		$text = $this->textOf( $x );
		$this->assertEquals( "{{#createpageifnotex:$y|" . self::EXPECTED_TEXT . "}}", $text );
	}

	public function testOnlyCreatesPageIfItDoesntExist(): void {
		[ $x, $y ] = self::randomize( [ 'x', 'y' ] );
		$this->insertPage( $y, self::EXPECTED_TEXT );
		$this->insertPage( $x, "{{#createpageifnotex:$y|other text}}" );

		$text = $this->textOf( $y );

		$this->assertEquals( self::EXPECTED_TEXT, $text );
	}

	public function testIgnoresRequestToCreateCurrentPage(): void {
		[ $x ] = self::randomize( [ 'x' ] );
		$this->insertPage( $x, "{{#createpageifnotex:$x|x}}" );

		$text = $this->textOf( $x );

		$this->assertEquals( "{{#createpageifnotex:$x|x}}", $text );
	}

	public function testDoesntRecurseByDefault(): void {
		[ $x1, $x2, $x3 ] = self::randomize( [ 'x1', 'x2', 'x3' ] );
		$this->insertPage( $x1,
			"{{#createpageifnotex:$x2|<nowiki>{{#createpageifnotex:$x3|" . self::EXPECTED_TEXT .
			'}}</nowiki>}}' );

		$text2 = $this->textOf( $x2 );
		$this->assertEquals( "{{#createpageifnotex:$x3|" . self::EXPECTED_TEXT . '}}', $text2 );

		$text3 = $this->textOf( $x3 );
		$this->assertNull( $text3 );
	}

	public function testRecursesIfToldSo(): void {
		global $egAutoCreatePageMaxRecursion;
		$egAutoCreatePageMaxRecursion = 2;

		[ $x1, $x2, $x3 ] = self::randomize( [ 'x1', 'x2', 'x3' ] );
		$this->insertPage( $x1,
			"{{#createpageifnotex:$x2|<nowiki>{{#createpageifnotex:$x3|" . self::EXPECTED_TEXT .
			'}}</nowiki>}}' );

		$text2 = $this->textOf( $x2 );
		$this->assertEquals( "{{#createpageifnotex:$x3|" . self::EXPECTED_TEXT . '}}', $text2 );

		$text3 = $this->textOf( $x3 );
		$this->assertEquals( self::EXPECTED_TEXT, $text3 );
	}

	public function testRecursionBudgetIsRestoredAfterEachTopLevelSave(): void {
		global $egAutoCreatePageMaxRecursion;
		$egAutoCreatePageMaxRecursion = 1;

		[ $a1, $a2, $b1, $b2 ] = self::randomize( [ 'a1', 'a2', 'b1', 'b2' ] );

		// First, unrelated save that uses up the recursion depth of 1.
		$this->insertPage( $a1, "{{#createpageifnotex:$a2|" . self::EXPECTED_TEXT . "}}" );
		$this->assertEquals( self::EXPECTED_TEXT, $this->textOf( $a2 ) );

		// A second, independent top-level save must still be able to recurse to depth 1 -
		// the max recursion setting is a per-save depth limit, not a process-wide budget
		// that gets exhausted by earlier, unrelated saves.
		$this->insertPage( $b1, "{{#createpageifnotex:$b2|" . self::EXPECTED_TEXT . "}}" );
		$this->assertEquals( self::EXPECTED_TEXT, $this->textOf( $b2 ) );
	}

	public function testReturnsLocalizedErrorWhenRecursionLevelExceeded(): void {
		$this->setContentLang( 'qqx' );
		global $egAutoCreatePageMaxRecursion;
		$egAutoCreatePageMaxRecursion = 0;

		[ $x, $y ] = self::randomize( [ 'x', 'y' ] );
		$html = $this->parse( "{{#createpageifnotex:$y|" . self::EXPECTED_TEXT . '}}' );

		$this->assertStringContainsString( '(autocreatepage-recursion-exceeded)', $html );
	}

	public function testReturnsLocalizedErrorWhenTitleIsEmpty(): void {
		$this->setContentLang( 'qqx' );

		$html = $this->parse( '{{#createpageifnotex:|' . self::EXPECTED_TEXT . '}}' );

		$this->assertStringContainsString( '(autocreatepage-empty-title)', $html );
	}

	private function parse( string $wikitext ): string {
		$parserOutput = $this->getServiceContainer()->getParser()->parse(
			$wikitext,
			Title::makeTitle( NS_MAIN, 'Test' ),
			ParserOptions::newFromAnon()
		);

		return $parserOutput->getRawText();
	}

	public function testIsNotCalledFromUnenabledSpecialPage(): void {
		$page = self::createOnSpecialExpandTemplates( 'NotCreated' );

		$this->assertFalse( $page->exists() );
	}

	public function testIsCalledFromEnabledSpecialPage(): void {
		global $egAutoCreatePageOnSpecialPages;
		$egAutoCreatePageOnSpecialPages = [ 'ExpandTemplates' ];

		$page = self::createOnSpecialExpandTemplates( 'Created' );

		$this->assertTrue( $page->exists() );
	}

	private static function createOnSpecialExpandTemplates( string $title ): WikiPage {
		$ctx = new RequestContext();
		$ctx->setRequest( new FauxRequest( [
			'wpContextTitle' => 'X',
			'wpInput' => "{{#createpageifnotex:$title|Some content...}}",
		] ) );
		$sp = Title::makeTitle( NS_SPECIAL, 'ExpandTemplates' );
		MediaWikiServices::getInstance()->getSpecialPageFactory()->executePath( $sp, $ctx );

		return MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( Title::newFromText( $title ) );
	}

	private function textOf( string $title ): ?string {
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( Title::newFromText( $title ) );

		return $page->exists() ? $page->getContent()->getText() : null;
	}

	/**
	 * @param string[] $titles
	 * @return string[]
	 */
	private static function randomize( array $titles ): array {
		return array_map( static function ( string $t ): string {
			return $t . '-' . mt_rand();
		}, $titles );
	}

}
