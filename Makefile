.PHONY: install lint lint-check analyse refactor refactor-check gate php-api error-codes config-schema ai-sync test test-pg test-arch fixtures ci

PHP84 := ./bin/php84
COMPOSER := $(PHP84) composer

install:
	$(COMPOSER) install

lint:
	$(COMPOSER) lint

lint-check:
	$(COMPOSER) lint:check

analyse:
	$(COMPOSER) analyse

refactor:
	$(COMPOSER) refactor

refactor-check:
	$(COMPOSER) refactor:check

gate:
	$(COMPOSER) gate

php-api:
	$(COMPOSER) php-api

error-codes:
	$(COMPOSER) error-codes

config-schema:
	$(COMPOSER) config-schema

ai-sync:
	$(COMPOSER) ai:sync

test:
	$(COMPOSER) test

test-pg:
	$(COMPOSER) test:pg

test-arch:
	$(COMPOSER) test:arch

fixtures:
	set -e; \
	for dir in fixtures/*; do \
		if [ -f "$$dir/composer.json" ]; then \
			$(PHP84) composer --working-dir="$$dir" install --no-interaction || exit $$?; \
			$(PHP84) composer --working-dir="$$dir" test || exit $$?; \
		fi; \
	done

ci: lint-check analyse refactor-check gate php-api test test-pg fixtures
