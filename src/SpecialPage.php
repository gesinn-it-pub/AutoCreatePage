<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoCreatePage;

class SpecialPage {

	/**
	 * @var array<string, array{content: string, user: \MediaWiki\User\UserIdentity, depth: int}>
	 */
	private array $pagesToCreate = [];

	public function __construct( private readonly bool $isEnabled ) {
	}

	public function isEnabled(): bool {
		return $this->isEnabled;
	}

	/**
	 * @param string $titleText
	 * @param array{content: string, user: \MediaWiki\User\UserIdentity, depth: int} $data
	 */
	public function addPageToCreate( string $titleText, array $data ): void {
		$this->pagesToCreate[$titleText] = $data;
	}

	/**
	 * @return array<string, array{content: string, user: \MediaWiki\User\UserIdentity, depth: int}>
	 */
	public function pagesToCreate(): array {
		return $this->pagesToCreate;
	}
}
