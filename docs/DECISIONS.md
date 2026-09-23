# Architecture and tooling decisions

Numbered so that code comments, `docs/agent/DECISIONS_INDEX.md` and `docs/REQUIREMENTS_TRACE.md` can point at a
section (e.g. "see DECISIONS §3.3"). Sections marked *planned* describe intent written before the code; they are
confirmed or amended in the phase that implements them and the change is noted in `docs/AI_NOTES.md`.

## 1. Tooling

### 1.1 Symfony 7.4 (not 6.4)
- The starter pins `7.4.*`. 7.4 is the current LTS (bug fixes to Nov 2028, security to Feb 2029); 6.4 loses
  bug-fix support in Nov 2026, so starting a new service on it makes no sense.
- 7.4 is feature-identical to 8.0 minus deprecated paths: deprecation-free 7.4 code is 8.0-ready.
- Features relied upon: `#[MapRequestPayload]`, `#[AsTaggedItem]` / `#[AutowireIterator]` / `#[AutowireLocator]`,
  the Clock component, Messenger `RecoverableMessageHandlingException` with retry delay, Doctrine native lazy
  objects (PHP 8.4).

### 1.2 FrankenPHP (kept, classic mode)
- The company's chosen runtime in the starter; reviewers run exactly this image.
- Single process for HTTP + PHP (Caddy with embedded PHP): no nginx/php-fpm pair, HTTP/2-3 and TLS built in; the
  official Symfony Docker template uses it.
- Worker mode (kernel stays booted) is a throughput win but adds state-leak risk; **not enabled** here because
  the assignment gains nothing from it. Documented as a production option.
- The Messenger consumer is a separate `messenger:consume` process regardless of the web runtime. The image
  healthchecks Caddy on `:2019/metrics`, which the consumer does not serve. The `worker` service replaces it
  with a process check (`ps | grep messenger:consume`): `docker compose up --wait` fails a service that has
  no healthcheck at all.

### 1.3 PHPStan level 8 with extensions
- Level 8 adds nullable strictness (calling methods on possibly-null values), the bug class that bites in
  provider adapters and handlers.
- Levels 9/10 (`mixed` strictness) fight Symfony's untyped boundaries (request payloads, container parameters,
  DBAL rows) and third-party generics; the cost exceeds the signal in a five-day task. Level 8 is the common
  industrial ceiling for Symfony applications.
- Extensions: `phpstan/phpstan-symfony` (container-aware, reads `var/cache/dev/App_KernelDevDebugContainer.xml`),
  `phpstan/phpstan-doctrine` (repository / QueryBuilder types via `tests/object-manager.php`),
  `phpstan/phpstan-phpunit`. `phpstan-strict-rules` skipped as noise for a short project; a follow-up.

### 1.4 Formatter: php-cs-fixer, not Pint
- `laravel/pint` is Laravel's wrapper around `friendsofphp/php-cs-fixer`; in a Symfony company the idiomatic
  tool is php-cs-fixer directly. Same engine, no extra layer.
- Ruleset: `@Symfony`, `@Symfony:risky`, `@PER-CS2.0`, `declare_strict_types`, `ordered_imports`,
  `no_unused_imports`, `native_function_invocation`. `final_class` is a convention, not a fixer rule (Doctrine
  mapping and test doubles occasionally need non-final classes). Runs in Docker: `make cs` / `make cs-fix`.

### 1.5 Layering and reference-doc gates as scripts, not dependencies
- `tools/check-layers.php` enforces the dependency rule (Domain has no `Symfony\` / `Doctrine\` imports except the
  mapping exceptions below; Application has no Infrastructure / HTTP / Doctrine imports). `deptrac` evaluated
  and rejected as heavyweight for a single bounded context; it is the production choice.
- Domain mapping exceptions: (1) `Doctrine\Common\Collections` (`Collection` / `ArrayCollection`) because a mapped
  one-to-many can only hydrate into a `Collection`; (2) `Doctrine\ORM\Mapping` attributes on aggregates. This is
  pragmatic DDD: the aggregate is a Doctrine entity (the usual Symfony style as the model grows) while
  `EntityManager`, repositories and DBAL types stay in Infrastructure. Ports stay in Domain. XML mapping was the
  original "zero ORM in Domain" choice; it was replaced because a second file per class does not match the rest of
  the stack (attributes for routing, validation, OpenAPI). A second persistence-model layer was rejected as double
  bookkeeping for this service.
- `tools/check-docs.php` fails the build when `docs/agent/CODE_MAP.md`, `docs/agent/RECIPES.md` or
  `docs/REQUIREMENTS_TRACE.md` reference classes, tests or paths that do not exist. Reference docs that lie are
  worse than none.

### 1.6 Debugger
- Xdebug 3 ships in the dev image (`xdebug.start_with_request=trigger`, `client_host=host.docker.internal`).
  Added: `.vscode/launch.json` ("Listen for Xdebug", port 9003, `pathMappings {"/app": "${workspaceFolder}"}`),
  `.env.local.dist` with `XDEBUG_MODE=debug`, `make test-debug` (`XDEBUG_TRIGGER=1` for CLI).
- `.env.local` is passed to the app and worker through Compose `env_file` (`required: false`). Compose does not
  read `.env.local` on its own and Xdebug reads `XDEBUG_MODE` at PHP start-up, so without this wiring the file
  would be a no-op. A Compose `environment:` entry overrides `env_file` for the same key even when empty, so
  `XDEBUG_MODE` is not declared there. The only `environment:` key is `RUN_MIGRATIONS=1` on `app` (the worker
  must not migrate). That name is not in `.env.local`, so the two mechanisms do not clash. Do not put
  `MAILER_DSN` in `.env.local`: `env_file` injects a real variable, which wins over `.env.test`'s `null://null`.

### 1.7 Runtime dependencies (why each)
- `symfony/messenger` + `symfony/doctrine-messenger`: async delivery, retry/backoff, failure transport. The
  Doctrine transport in the same database gives transactional-outbox semantics without a broker container.
- `symfony/mailer`: SMTP is a real provider that needs no account (Mailpit locally, any SMTP/SES-SMTP in prod).
- `symfony/http-client`: Twilio adapter; `MockHttpClient` in tests.
- `symfony/uid` (UUID v7 ids, time-ordered), `symfony/clock` (testable time, `MockClock`).
- `symfony/validator` + `symfony/serializer` + `property-access` + `property-info`: `#[MapRequestPayload]` DTOs.
- `symfony/rate-limiter` with `cache.adapter.doctrine_dbal` storage so app and worker share the counters.
- `symfony/monolog-bundle`: structured provider logs.
- `nelmio/api-doc-bundle` (+ `symfony/twig-bundle`, `symfony/asset`): OpenAPI 3 spec and Swagger UI for evaluators
  (DECISIONS §1.9). Twig/Asset are required only by the UI HTML route.
- Dev: `dama/doctrine-test-bundle` (transaction rollback per test, mature, no fixture churn),
  `friendsofphp/php-cs-fixer`, `phpstan/*`.

### 1.8 Not `symfony/notifier`
- Notifier's `failover()` / `roundrobin()` transport DSNs hide exactly the behaviour the task asks us to design:
  per-attempt tracking, transient vs permanent vs unknown classification, no failover on unknown outcome.
- Own thin `NotificationProvider` port instead; Notifier bridges could be wrapped as adapters later.

### 1.9 API documentation: NelmioApiDocBundle (OpenAPI + Swagger UI)
- Evaluators need to *exercise* the endpoints. `nelmio/api-doc-bundle` v5 serves Swagger UI at `/api/doc` and
  the raw spec at `/api/doc.json`; a generated `docs/openapi.json` is importable into Postman/Insomnia.
- Nelmio derives paths, methods and (later) `#[MapRequestPayload]` DTO constraints from the code. What can
  still drift — response status codes, body fields, examples — is gated by PHPUnit: `OpenApiCoverageTest`
  (every area route has an operation and a 2xx schema), `OpenApiSnapshotTest` (committed dump equals generated
  spec), and `assertResponseIsDocumented()` on every `WebTestCase` request. Updating the docs is a failing
  test, not a reminder.
- Rejected: a hand-written `openapi.yaml` (no link to the code, drifts silently) and API Platform (imposes its
  resource/serializer model on a handful of endpoints that return explicit arrays).
- Twig + Asset exist only because the Swagger UI HTML route needs them; we have no application templates.
- OpenAPI attributes live only in UserInterface (`src/Controller/` for the starter health probe,
  `NotificationPublisher/UserInterface/` for the bounded context). `tools/check-layers.php` rejects `OpenApi\`
  / `Nelmio\` anywhere else. Full JSON-Schema validation of responses (`opis/json-schema`) is a follow-up;
  the trait checks status + required/declared body keys.
- The docs route is unauthenticated in every environment so evaluators can use it out of the box.

## 2. Configuration model (*planned*, implemented in 2.1)

### 2.1 Channels and providers are configuration
```yaml
# config/packages/notifications.yaml — values come from .env (csv / bool).
parameters:
  notifications.channels:
    email: { enabled: true, strategy: priority,    providers: ['smtp', 'fake_email'] }
    sms:   { enabled: true, strategy: round_robin, providers: ['twilio', 'fake_sms'] }
  notifications.throttle: { limit: 300, interval: '1 hour' }
  notifications.recipients: { 'user-1': { email: 'user1@example.test', phone: '+37060000001' } }
```
- Env overrides: `NOTIFICATIONS_SMS_PROVIDERS` / `NOTIFICATIONS_EMAIL_PROVIDERS` (csv), `NOTIFICATIONS_*_ENABLED`,
  `MAILER_DSN`, `MAILER_FROM`, `TWILIO_*`, `FAKE_SMS_MODE` / `FAKE_EMAIL_MODE` =
  `success|transient|permanent_recipient|permanent_provider|timeout`.
- Validated when the container is compiled (`ValidateChannelConfigurationPass` in `Kernel::build()`, not in a
  service constructor): unknown channel, unknown strategy, unknown provider name, or an enabled channel with an
  empty provider list fails `cache:clear` with `InvalidChannelConfiguration`. Misconfiguration must not reach runtime.

### 2.2 Provider ordering strategy answers "define how providers are used"
- `priority` (default): always start with the first provider, fail over down the list.
- `round_robin`: rotate the starting provider per delivery, then fail over in order.
- Same failover semantics (§3) for both; only the starting point differs. Implemented as a tiny
  `ProviderOrdering` interface with two implementations.

### 2.3 Naming follows the task text
- The throttling flag on a request is `requiresUserAction: true` (the task's wording), column
  `requires_user_action`; not a vague `throttled` flag.
- Recipient contacts come from an identity service; stubbed by a config-seeded `InMemoryRecipientResolver`.
  A request may carry an explicit `recipient` override.
- Unknown user, or a known user without a contact for a requested channel, is rejected with 422 at accept time
  (the caller asked for something we cannot address; failing fast beats a delivery that can never succeed).
  Nothing is persisted for a rejected request.
- Disabled channels are a configuration concern: the Domain expands one `Delivery` per *requested* channel;
  `SendNotificationHandler` marks deliveries for disabled channels `skipped` once `ChannelConfiguration` exists
  (2.1). Before that (1.3) every requested delivery is `pending`.

## 3. Failure semantics (strategy in 2.2; Messenger mapping still 3.1)

`FailoverDeliveryStrategy` returns a `DeliveryResult` and does not throw for control flow. Attempts are recorded
on the `Delivery` (`startAttempt` before the provider call, then `completeAttempt` / `failAttempt`). Phase 3.1's
handler maps the result once: `retryLater` → `RecoverableMessageHandlingException`, `failed` →
`UnrecoverableMessageHandlingException`.

### 3.1 Transient failure (5xx, 429, connection refused / DNS)
- Try the next provider in order. If every provider fails transiently, the strategy returns `retryLater` and
  leaves the delivery `pending`. The handler (3.1) throws
  `RecoverableMessageHandlingException` and Messenger retries the whole delivery:
  `max_retries: 5, delay: 2000, multiplier: 3, max_delay: 300000` (2 s, 6 s, 18 s, 54 s, 162 s).
- After the last retry the message lands in the `failed` transport and the delivery stays `pending`.
  Marking it `failed` would make the handler no-op on `isFinal()`, so `messenger:failed:retry`
  (`make retry-failed`) would do nothing. A permanent failure still marks the delivery `failed`
  before the unrecoverable exception. A notification never silently disappears.

### 3.2 Permanent failure
- Recipient-level (invalid number / address, e.g. Twilio 21211 / 21614): no failover, delivery `failed`,
  `UnrecoverableMessageHandlingException`. Another provider cannot fix a wrong address.
- Provider-level (401 / 403, or a Twilio 400 whose code is not 21211 / 21614, e.g. 21212 invalid `From`): fail over once to the next provider, then stop.
  The strategy keeps a counter. The second provider-level failure (or a list that ends on the first) returns
  `failed` and marks the delivery `failed`. A plain `continue` would keep walking three or more providers.
  If that one failover succeeds, the result is `sent`.
- `TwilioSmsProvider` reads `$response->getStatusCode()` and `toArray(false)`, so a 4xx/5xx is a status, not a
  `ClientException` / `ServerException`. 201 with a `sid` is success. 400 with Twilio `21211` or `21614` is
  recipient-level. Any other 4xx is provider-level. 429 and 5xx are transient (§3.1). A
  `TransportExceptionInterface` is unknown (§3.3), because the request may already have left.

### 3.3 Unknown outcome (timeout after the request was sent, dropped connection)
- Record the attempt as `unknown`, **no same-run failover** (the message may have been delivered). The strategy
  returns `retryLater` and leaves the delivery `pending`; the handler (3.1) throws recoverable so Messenger retries later.
- At-least-once delivery is accepted; exactly-once is not achievable with these providers. Mitigations:
  deterministic SMTP `Message-ID` derived from the delivery id, provider idempotency key where supported.
- SMTP cannot tell us whether a `TransportException` happened before or after the message left. The adapter
  classifies from `TransportException::getDebug()` (Symfony 7.4 dialogue: `[timestamp] > COMMAND` /
  `[timestamp] < REPLY`; the body is not logged). A `4xx`/`5xx` reply to `RCPT TO` is
  `PermanentProviderFailure` (recipient-level): another provider cannot fix a rejected address. Any other
  failure before `DATA` (connect, EHLO, AUTH, `MAIL FROM`, empty debug) is `TransientProviderFailure`. Once
  `DATA` was sent (`> DATA` or reply `354`) the outcome is `UnknownProviderOutcome`. HTTP providers are simpler:
  a `TransportExceptionInterface` (timeout, dropped connection) is always unknown, an HTTP status is never.

### 3.4 Duplicates
- Duplicate HTTP request: `idempotency_key` is unique; a replay returns the existing notification with 200.
- Messenger redelivery: `DeliverNotificationHandler` no-ops when the delivery is final (`sent`, `failed`,
  `skipped`; `throttled` is not final and is retried).
- The attempt row is written `in_progress` **and flushed** before the provider call so a crash mid-call leaves
  evidence.

### 3.5 Disabled channel
- A delivery for a disabled channel is created as `skipped` (visible in tracking) and never dispatched.

### 3.6 Two message buses; why the delivery handler is not wrapped in a transaction
- `command.bus` (`SendNotification`, handled synchronously) runs with the `doctrine_transaction` middleware:
  the notification, its deliveries and the `DeliverNotification` rows in `messenger_messages` commit together
  (Doctrine transport on the same connection = outbox without a broker).
- `delivery.bus` (`DeliverNotification`, consumed by the worker) runs **without** `doctrine_transaction`. That
  middleware rolls back on any exception — and the design *relies* on throwing `RecoverableMessageHandlingException`
  / `UnrecoverableMessageHandlingException` to drive retries. Wrapped, every attempt row and status change
  would vanish exactly when a provider fails. The handler therefore saves (flushes) explicitly, then throws.
- Test environment: `async` and `failed` transports are `in-memory://`. `sync://` was rejected: it would execute
  the delivery inside the command transaction, so a failing provider would roll back the notification and turn
  `POST /notifications` into a 500 — the opposite of "accepted, delivered later". End-to-end tests pull the
  envelope from the in-memory transport and dispatch it on `delivery.bus` themselves (`tests/Support/DeliveryPipeline`).
- Throttled deliveries are re-dispatched with a `DelayStamp` instead of throwing, so throttling never consumes
  one of the five failure retries.

## 4. Data model (one migration per phase that introduces the need)

- 1.2 — `notifications`: id (uuid v7), user_id (idx), idempotency_key (unique), subject, body,
  requires_user_action, created_at, updated_at. No `status` column: a notification-level status is derived
  from its deliveries in the read model, so there is one source of truth and nothing to keep in sync.
- 1.2 — `notification_deliveries`: id, notification_id (fk), channel, recipient, status
  (`pending|sent|failed|skipped|throttled`), attempts_count, sent_via_provider, provider_message_id, sent_at,
  timestamps; unique (notification_id, channel).
- 1.2 — `notification_delivery_attempts`: id, delivery_id (fk), provider, outcome
  (`in_progress|succeeded|transient_failure|permanent_failure|unknown`), error_code, error_message,
  provider_message_id, started_at, finished_at.

- 1.2 — `notifications`: id (uuid v7), user_id (idx), idempotency_key (unique), subject, body,
  requires_user_action, created_at, updated_at. No `status` column: a notification-level status is derived
  from its deliveries in the read model, so there is one source of truth and nothing to keep in sync.
- 1.2 — `notification_deliveries`: id, notification_id (fk), channel, recipient, status
  (`pending|sent|failed|skipped|throttled`), attempts_count, sent_via_provider, provider_message_id, sent_at,
  timestamps; unique (notification_id, channel).
- 1.2 — `notification_delivery_attempts`: id, delivery_id (fk), provider, outcome
  (`in_progress|succeeded|transient_failure|permanent_failure|unknown`), error_code, error_message,
  provider_message_id, started_at, finished_at.
- 3.1 — `messenger_messages` (`async` and `failed` queues). `.env` sets `doctrine://default?auto_setup=0`, so
  the table is created by a migration (`doctrine:migrations:diff` includes it through the Doctrine bridge schema
  listener once a doctrine transport is configured), never by a worker racing auto-setup.
- 4.1 — DBAL cache table for the rate limiter (Symfony default name `cache_items`), generated by `diff` once the
  `cache.adapter.doctrine_dbal` pool is configured (hand-written DDL if the listener does not pick it up);
  created by migration so app and worker never race auto-creation.
- Mapping: PHP attributes on Domain model classes (`type: attribute` in `doctrine.yaml`, prefix
  `App\NotificationPublisher\Domain\Model`). Replaces the starter's broad `App` attribute mapping so only these
  aggregates are entities. Custom DBAL types stay in Infrastructure; type names on columns are strings
  (`notification_id`), so Domain does not import adapter classes.

### 4.1 Tables deliberately not created
- users / contacts: owned by the identity service; we hold only `user_id`.
- providers / channels: configuration, not data (§2).
- templates: content arrives pre-rendered (subject / body); templating is out of scope.
- outbox: the Doctrine Messenger transport writes the message row in the same transaction as the
  notification, which is the outbox pattern already.

### 4.2 Test database
- `app_test` is created and granted to `app` by `docker/mariadb/init.sql` (runs once per volume);
  `make test` runs `doctrine:database:create --if-not-exists --env=test` and
  `doctrine:migrations:migrate --allow-no-migration --env=test` before PHPUnit, so a fresh clone needs no
  manual step.
- `dama/doctrine-test-bundle` wraps every test in a rolled-back transaction (static connection, PHPUnit
  extension). Integration tests therefore need no fixtures cleanup and can run in any order.

## 5. Out of scope (stated in README, finalised in 4.4)
- Push channel (documented as an extension via `add-notification-provider`), templating, provider cooldown /
  circuit breaker, delivery receipt webhooks, API authentication, multi-tenancy, FrankenPHP worker mode.
