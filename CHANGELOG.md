# Changelog

All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/) and
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Breaking Changes
- Change PHP namespace from `ACP\` to `MediaWiki\Extension\AutoCreatePage\` [`857c148`](https://github.com/gesinn-it-pub/AutoCreatePage/commit/857c148)
- Raise minimum required MediaWiki version from 1.35 to 1.39 [`857c148`](https://github.com/gesinn-it-pub/AutoCreatePage/commit/857c148)

### Changed
- Replace `phpstan` static analysis with `mediawiki-codesniffer` (phpcs) only [`7b42b78`](https://github.com/gesinn-it-pub/AutoCreatePage/commit/7b42b78)

### Fixed
- Replace deprecated `WikiPage::doEditContent()`/`WikiPage::factory()` with `WikiPage::newPageUpdater()`/`WikiPageFactory` [`857c148`](https://github.com/gesinn-it-pub/AutoCreatePage/commit/857c148)
- Fix recursion depth tracking for auto-created pages: the previous global
  counter could be restored before a created page's own content was parsed,
  allowing recursion past the configured limit, or (if never restored) get
  permanently exhausted by unrelated, non-nested page saves in the same
  process [`857c148`](https://github.com/gesinn-it-pub/AutoCreatePage/commit/857c148)

[Unreleased]: https://github.com/gesinn-it-pub/AutoCreatePage/compare/master...HEAD
