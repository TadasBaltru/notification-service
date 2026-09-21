.PHONY: default
default: help

COMPOSE = docker compose

.PHONY: help
help: ## Get this help.
	@echo Tasks:
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_-]+:.*?## / {printf "\033[36m%-12s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

.PHONY: build
build: ## Rebuild the Docker images.
	@$(COMPOSE) build --pull

.PHONY: run
run: ## Start the application and wait until it answers.
	@$(COMPOSE) up -d --wait
	@echo "Ready on http://localhost:$$($(COMPOSE) port app 80 | cut -d: -f2)"

.PHONY: test-db
test-db: run ## Create the app_test database and bring its schema up to date (idempotent).
	@$(COMPOSE) exec -T app bin/console doctrine:database:create --if-not-exists --env=test -q
	@$(COMPOSE) exec -T app bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test -q

.PHONY: test
test: test-db ## Run the test suite. Use ARGS="--filter SomeTest" to narrow it down.
	@$(COMPOSE) exec -T app vendor/bin/phpunit $(ARGS)

.PHONY: test-debug
test-debug: test-db ## Run the test suite with Xdebug attached (XDEBUG_MODE=debug in .env.local, IDE listening).
	@$(COMPOSE) exec -T -e XDEBUG_TRIGGER=1 app vendor/bin/phpunit $(ARGS)

.PHONY: cs
cs: run ## Check coding standards (php-cs-fixer, no changes).
	@$(COMPOSE) exec -T app vendor/bin/php-cs-fixer check --diff

.PHONY: cs-fix
cs-fix: run ## Fix coding standards in place.
	@$(COMPOSE) exec -T app vendor/bin/php-cs-fixer fix

.PHONY: phpstan
phpstan: run ## Static analysis at PHPStan level 8.
	@$(COMPOSE) exec -T app bin/console cache:warmup --env=dev -q
	@$(COMPOSE) exec -T app vendor/bin/phpstan analyse --memory-limit=1G

.PHONY: check-docs
check-docs: run ## Verify reference docs and layer rules match the code.
	@$(COMPOSE) exec -T app php tools/check-docs.php
	@$(COMPOSE) exec -T app php tools/check-layers.php

.PHONY: lint
lint: cs phpstan check-docs ## Run every quality check (cs + phpstan + check-docs).

.PHONY: stop
stop: ## Stop the application.
	@$(COMPOSE) down
