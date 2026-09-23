.PHONY: default
default: help

COMPOSE = docker compose

.PHONY: help
help: ## Get this help.
	@echo Tasks:
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_-]+:.*?## / {printf "\033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

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

.PHONY: worker-logs
worker-logs: ## Follow the Messenger worker logs.
	@$(COMPOSE) logs -f worker

.PHONY: failed
failed: ## List messages on the failure transport.
	@$(COMPOSE) exec -T app bin/console messenger:failed:show

.PHONY: retry-failed
retry-failed: ## Retry every message on the failure transport.
	@$(COMPOSE) exec -T app bin/console messenger:failed:retry --force -vv

.PHONY: send
send: ## POST an email; the worker delivers it to Mailpit.
	@key=send-$$(date +%s); \
	port=$$($(COMPOSE) port app 80 | cut -d: -f2); \
	curl -fsS -X POST "http://localhost:$$port/notifications" \
		-H 'Content-Type: application/json' \
		-d "{\"userId\":\"user-1\",\"idempotencyKey\":\"$$key\",\"channels\":[\"email\"],\"subject\":\"Hello\",\"body\":\"Your order is confirmed.\"}"; \
	echo

.PHONY: send-failover
send-failover: ## Stop Mailpit, POST an email so SMTP fails over to fake_email, print status, start Mailpit.
	@$(COMPOSE) stop mailpit
	@set -e; \
	trap '$(COMPOSE) start mailpit' EXIT; \
	key=failover-$$(date +%s); \
	port=$$($(COMPOSE) port app 80 | cut -d: -f2); \
	resp=$$(curl -fsS -X POST "http://localhost:$$port/notifications" \
		-H 'Content-Type: application/json' \
		-d "{\"userId\":\"user-1\",\"idempotencyKey\":\"$$key\",\"channels\":[\"email\"],\"subject\":\"Failover\",\"body\":\"SMTP is down.\"}"); \
	echo "$$resp"; \
	id=$$(printf '%s' "$$resp" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p'); \
	i=0; \
	while [ $$i -lt 30 ]; do \
		body=$$(curl -fsS "http://localhost:$$port/notifications/$$id"); \
		case "$$body" in \
			*'"status":"sent"'*) echo "$$body"; exit 0 ;; \
		esac; \
		i=$$((i+1)); \
		sleep 1; \
	done; \
	echo "$$body"; \
	exit 1

.PHONY: stop
stop: ## Stop the application.
	@$(COMPOSE) down
