-include .env
export

# setup for docker-compose-ci build directory
# delete "build" directory to update docker-compose-ci

ifeq (,$(wildcard ./build/))
    $(shell git submodule update --init --remote)
endif

EXTENSION=AutoCreatePage

# docker images
MW_VERSION?=1.43
PHP_VERSION?=8.3
DB_TYPE?=sqlite

# composer
# Enables "composer update" inside of extension
COMPOSER_EXT?=true

# coverage: PCOV is several times faster than Xdebug (line coverage only)
COVERAGE_DRIVER?=pcov

include build/Makefile

# PHP development cycle: lint (full) + phpunit
# Optional:
#   FILTER=AutoCreatePageTest   restricts phpunit via --filter
.PHONY: php-test
php-test: .init
ifdef COMPOSER_EXT
	$(show-current-target)
	$(compose-exec-wiki) bash -c "cd $(EXTENSION_FOLDER) && composer lint"
	$(compose-exec-wiki) bash -c "cd $(EXTENSION_FOLDER) && composer phpunit$(if $(FILTER), -- --filter $(FILTER),)"
endif

# Full development cycle without reinstalling: lint + phpcs + phpunit
# Equivalent to 'make ci' but skips 'make install'. Run before committing.
.PHONY: dev-test
dev-test: .init
ifdef COMPOSER_EXT
	$(show-current-target)
	$(compose-exec-wiki) bash -c "cd $(EXTENSION_FOLDER) && composer lint"
	$(compose-exec-wiki) bash -c "cd $(EXTENSION_FOLDER) && composer phpcs"
	$(compose-exec-wiki) bash -c "cd $(EXTENSION_FOLDER) && composer phpunit"
endif
