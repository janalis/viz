# Developer entry points. `make verify` runs every gate CI runs.
.DEFAULT_GOAL := help

COMPOSER ?= composer
PHP ?= php
COVERAGE_PHP_FLAGS ?= -d pcov.enabled=1 -d pcov.directory=src
INFECTION_THREADS ?= max

.NOTPARALLEL:
.PHONY: help install validate fmt fmt-check lint analyze test coverage infection audit verify clean

help: ## List developer commands
	@awk 'BEGIN { FS = ":.*## " } /^[a-z-]+:.*## / { printf "  %-12s %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

install: ## Install dependencies
	$(COMPOSER) install --no-interaction --prefer-dist

validate: ## Validate composer.json
	$(COMPOSER) validate --strict --no-check-lock

fmt: ## Apply Mago formatting
	vendor/bin/mago format

fmt-check: ## Check Mago formatting without changes
	vendor/bin/mago format --check

lint: ## Mago lint, failing on any finding
	vendor/bin/mago lint --minimum-fail-level=note

analyze: ## Mago static analysis, failing on any finding
	vendor/bin/mago analyze --minimum-fail-level=note

test: ## Run the test suite
	$(PHP) vendor/bin/phpunit

coverage: ## Require 100% line coverage of src/
	$(PHP) $(COVERAGE_PHP_FLAGS) vendor/bin/phpunit --coverage-clover=var/coverage/clover.xml --log-junit=var/coverage/junit.xml
	$(PHP) tools/check-coverage.php var/coverage/clover.xml

infection: ## Mutation testing against the MSI floor in infection.json5.dist
	$(PHP) $(COVERAGE_PHP_FLAGS) vendor/bin/infection --threads=$(INFECTION_THREADS) --initial-tests-php-options="$(COVERAGE_PHP_FLAGS)"

audit: ## Check dependencies for security advisories
	$(COMPOSER) audit

verify: validate fmt-check lint analyze coverage audit ## Every gate CI runs, except mutation testing

clean: ## Remove generated reports and caches
	rm -rf .phpunit.cache var
