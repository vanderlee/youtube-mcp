.DEFAULT_GOAL := help

COMPOSER ?= composer

.PHONY: help install test phpstan cs cs-check cs-fix qa audit

help:
	@echo "install   Install locked development dependencies"
	@echo "test      Run the Pest test suite"
	@echo "phpstan   Run PHPStan at level 1"
	@echo "cs        Check coding standards with PHP_CodeSniffer"
	@echo "cs-check  Preview PHP-CS-Fixer changes without writing files"
	@echo "cs-fix    Apply PHP-CS-Fixer changes (review the diff)"
	@echo "qa        Run tests, PHPStan, and both coding-style checks"
	@echo "audit     Check dependencies for security advisories"

install:
	$(COMPOSER) install --prefer-dist --no-interaction

test:
	$(COMPOSER) test

phpstan:
	$(COMPOSER) phpstan

cs:
	$(COMPOSER) cs

cs-check:
	$(COMPOSER) cs-check

cs-fix:
	$(COMPOSER) cs-fix

qa:
	$(COMPOSER) qa

audit:
	$(COMPOSER) audit
