# AI and tooling notes

Tools used: Cursor IDE with Claude (agent mode), project-level Cursor rules and skills under `.cursor/`,
PHPStan, php-cs-fixer, PHPUnit. The agent was used for scaffolding, boilerplate, test drafting and documentation drafts;
every architectural decision was reviewed and is recorded in `docs/DECISIONS.md`.

Format per phase: what the assistant proposed, what was accepted or rejected, what had to be verified or corrected.

## Phase 0.1 — repository and dependencies
- Proposed and accepted: own `NotificationProvider` abstraction instead of `symfony/notifier` failover transports
  (reason in DECISIONS). Doctrine Messenger transport instead of a broker container.
- Proposed and accepted: add `symfony/property-access` + `symfony/property-info` alongside serializer/validator because
  `#[MapRequestPayload]` cannot denormalize DTOs without them.
- Rejected: Laravel Pint as formatter (wrapper around php-cs-fixer); php-cs-fixer used directly with the Symfony ruleset.
- Verified: Flex ignored contrib recipes for `dama/doctrine-test-bundle` and `phpstan/phpstan` (`allow-contrib: false`),
  so their bundle registration and config are done by hand rather than assumed present.

## Phase 0.2 — Cursor rules
- Accepted: six small rules instead of one large one; only `workflow` and `symfony-conventions` are always applied,
  the rest are glob-scoped so they cost context only when relevant files are edited.
- Accepted: the failure-semantics decisions (no same-run failover on unknown outcome, recoverable vs unrecoverable
  exception mapping, attempt row written before the provider call) are stated in `messenger-reliability` so the
  assistant cannot silently drift from them while generating handlers.
- Rejected: enforcing `final_class` through php-cs-fixer; kept as a convention in the rule because Doctrine
  entity mapping and test doubles occasionally need non-final classes.

## Phase 0.3 — Cursor skills
- Accepted: knowledge split into on-demand skills (`symfony-ddd-patterns`, `symfony-testing`,
  `add-notification-provider`) so vendor code is not re-read each session; only `phase-runner` auto-triggers.
- Accepted: in-repo fake providers with explicit modes instead of PHPUnit mocks for our own port — fakes double as
  demo providers and keep tests about behaviour rather than call sequences.
- To verify before relying on them (flagged in the fragments): `RecoverableMessageHandlingException` retry-delay
  constructor argument, `MockResponse` `error` info producing a `TransportException`, ORM 3 XML `enum-type`
  attribute, `#[AutowireIterator(defaultIndexMethod: 'name')]` keys. Each is checked by a real test when first used
  (Day 1-3) and the proven version is copied into `docs/agent/RECIPES.md`.

## Phase 0.4 — quality gate
- Accepted: PHPStan level 8 (nullable strictness) with the Symfony, Doctrine and PHPUnit extensions wired by explicit
  `includes` rather than the `extension-installer` plugin, so the configuration is visible in one file.
- Accepted: two tiny hand-written checkers instead of a dependency — `check-layers.php` (dependency rule, no
  `Symfony\`/`Doctrine\` in Domain, no Doctrine/HTTP/Infrastructure in Application) and `check-docs.php`
  (reference docs must describe real classes/tests). Evaluated `deptrac` for layering; rejected as heavyweight for a
  single bounded context — noted as the production choice.
- Rejected (after seeing the diff): the assistant's `concat_space: one` override, which contradicts Symfony house
  style; reverted to the `@Symfony` default.
- Verified by breaking on purpose: a nullable method call fails PHPStan, an unmapped class fails check-docs, a
  `Doctrine\ORM` import inside Domain fails check-layers; all green again after removing the scratch file.

## Phase 0.5 — debugger
- Proposed and accepted: committed `.vscode/launch.json` (port 9003, `/app -> ${workspaceFolder}`) plus
  `.vscode/extensions.json` recommending `xdebug.php-debug`; personal variants stay in gitignored `*.local.json`.
- Corrected (plan assumption was wrong): the plan's "`.env.local` with `XDEBUG_MODE=debug`" would have been a no-op —
  Compose only reads `.env`, and Xdebug reads `XDEBUG_MODE` at PHP start-up, not through Symfony's Dotenv. Fixed by
  `env_file: [{path: .env.local, required: false}]` on the app service.
- Rejected (after `docker compose config` showed it): keeping an `environment: - XDEBUG_MODE` pass-through next to
  `env_file` — an unset shell variable resolves to `null` and still overrides the file. `.env.local` is the only switch.
- Verified mechanically instead of "should work": a raw TCP listener on the host received Xdebug 3.5.3's DBGp `init`
  packet (`fileuri="file:///app/public/index.php"`) after `GET /health?XDEBUG_TRIGGER=1`; the path prefix confirms the mapping.

## Phase 0.6 — docs skeleton and test database
- Accepted: `docker/mariadb/init.sql` mounted into `/docker-entrypoint-initdb.d/` (create `app_test`, grant to `app`)
  instead of widening the `app` user's grants or using root in tests; verified on a fresh volume (`down -v`).
- Accepted: Makefile `test-db` (idempotent create + `migrations:migrate --allow-no-migration`) as a prerequisite of
  `test` and `test-debug`; `--allow-no-migration` added after running it — without it the empty migrations dir is a warning
  that reads like a failure.
- Accepted: dama registered for `test` only, all three static options explicit in `dama_doctrine_test_bundle.yaml`, PHPUnit
  extension in `phpunit.xml.dist`. Verified by booting both kernels: test -> `dbname=app_test` + DAMA middleware, dev -> neither.
- Accepted: `REQUIREMENTS_TRACE.md` rows R01-R30 derived from the plan's requirement-by-requirement check (§4c), all
  `pending`; the original task text is not in the repository, so the user should diff the rows against it once.
- Verified by breaking on purpose: flipping R01 to `done` makes `check-docs` fail with the two missing test names.

## Plan review before Day 1 (architect pass over PLAN §3–§4b and briefs 1.1–5.1)
- Corrected (plan contradicted itself): "zero Doctrine in Domain" vs. `Notification -> Delivery -> DeliveryAttempt`
  mapped as one-to-many — the ORM hydrates only into `Doctrine\Common\Collections\Collection`. Decision: whitelist that
  one standalone namespace in `tools/check-layers.php` (DECISIONS §1.5) rather than push the "one channel per
  notification" invariant into a DB index.
- Corrected (would have failed in 1.2): adding XML mapping next to the starter's `App` attribute mapping — the
  driver chain returns the first prefix match, so `App` would shadow `App\NotificationPublisher\...`. Brief now says
  replace, not add. Dropped the unspecified `notifications.status` column (derived from deliveries).
- Corrected (would have failed in 2.1/2.2): fakes were created in 2.3 but needed by 2.2's tests and by 2.1's
  compile-time validation (config listed `smtp`/`twilio` before they existed). Fakes moved to 2.1; config lists only
  existing providers per phase; validation is a compiler pass (a constructor check fires at runtime only);
  `defaultIndexMethod: 'name'` rejected because that option needs a static method — `#[AsTaggedItem]` instead.
- Corrected (design flaw): `doctrine_transaction` middleware on the bus that handles `DeliverNotification` rolls back
  attempt rows on the Recoverable/Unrecoverable exceptions the design throws. Two buses now (DECISIONS §3.6); the
  delivery handler flushes, then throws. `sync://` in tests rejected for the same reason (delivery would run inside
  the command transaction; provider failure -> rolled-back notification + HTTP 500); `in-memory://` drained on
  `delivery.bus` instead.
- Corrected (facts vs. `.env`): `MESSENGER_TRANSPORT_DSN` already has `auto_setup=0`, so `messenger_messages` is a
  migration in 3.1, not "auto_setup"; the rate-limiter cache table cannot appear in a 1.2 `diff` (no pool yet) and
  its default name is `cache_items` — moved to 4.1. `docker/entrypoint.sh` has no `RUN_MIGRATIONS` yet; 3.2 adds it.
- Added product decisions the implementer would otherwise have invented: known user without a contact for a
  requested channel -> 422 (DECISIONS §2.3); SMTP transient-vs-unknown heuristic on the SMTP dialogue (`DATA`)
  (§3.3); `SmtpMailerProvider` uses `TransportInterface`, not `MailerInterface` (bus wrapping / async routing).
- Found, not fixed (user decision): there is **no `.git` directory** although PLAN 0.1 says `git init` on Day 0 and
  TRACE R28 grades the history. Recorded in STATUS; the agent does not run git write commands.
- Verified after the changes (inside the container): `check-layers: OK` with the new whitelist, `check-docs: OK
  (2 classes mapped)`, PHPStan `[OK] No errors`, phpunit `OK (1 test, 4 assertions)`, php-cs-fixer `0 of 8` after
  normalising three CRLF files (`tools/*.php`, `tests/object-manager.php`) that a Windows editor had rewritten.

## Phase pack — copy-paste briefs (after 0.6)
- Proposed and accepted: one markdown file per PLAN §4 phase under `.notes/phases/` (gitignored) so a new Grok
  chat starts from a slice, not the whole plan. Index and "where planning data lives" table: `.notes/phases/README.md`.
- Rejected: seeding `CODE_MAP.md` with planned classes in 0.6 — `check-docs.php` would fail on missing files.
- Verified this session: `HealthEndpointTest` against `app_test`; php-cs-fixer 0/8; phpstan OK; check-docs OK;
  check-layers OK; DBGp `init` from the container (`fileuri="file:///app/public/index.php"`) after
  `GET /health?XDEBUG_TRIGGER=1`.

## Phase 0.7 — API documentation (OpenAPI + Swagger UI)
- Proposed and accepted: NelmioApiDocBundle v5.12 + Swagger UI as the evaluator surface; coverage + snapshot +
  per-response assertions so "docs always updated" is a failing test. DECISIONS numbered **§1.9** because §1.6
  is already the debugger.
- Rejected: hand-written `openapi.yaml` and API Platform (DECISIONS §1.9). Flex contrib recipe for Nelmio ignored
  (`allow-contrib: false`) — bundle, `nelmio_api_doc.yaml` and routes registered by hand, same as dama on 0.1.
- Verified / corrected against the running container:
  - JSON UI controller is `nelmio_api_doc.controller.swagger`, **not** `swagger_json` (brief was wrong).
  - Generator used in tests: `nelmio_api_doc.generator` (alias of `nelmio_api_doc.generator.default`). The locator
    exists (`nelmio_api_doc.generator_locator`) but `->get('default')` is a phpstan-symfony false positive.
  - `generate()` returns `OpenApi\Annotations\OpenApi` with `toJson()` / `jsonSerialize()`, **no** `toArray()`.
  - Dump command: `nelmio:apidoc:dump --format=json`. Model attribute namespace: `Nelmio\ApiDocBundle\Attribute\Model`.
  - `OpenApi\` allowed in `src/Controller/` (starter health probe) as well as `**/UserInterface/**`.
  - phpunit `--filter "OpenApi|HealthEndpointTest"` → `OK (3 tests, 56 assertions)`.

## Phase 1.1 — Domain model
- Proposed and accepted: children held as `Doctrine\Common\Collections\Collection` (DECISIONS §1.5); providers
  receive `OutboundMessage` so an adapter cannot call `markSent` on the aggregate; `DeliveryAlreadyFinal` as the
  illegal-transition exception (T1), covering double `markSent`.
- Rejected: `ProviderFailure::$code` as a promoted property — it collides with `\Exception::$code` (fatal at
  autoload). Renamed to `$errorCode`. Rejected generating attempt ids inside Domain (no `Uuid::v7()` here);
  `startAttempt(DeliveryAttemptId, ...)` takes the id from the caller.
- Verified: `DeliveryTest` + `NotificationTest` + `ValueObjectTest` green with zero `Symfony\` imports;
  `check-layers` allows `Doctrine\Common\Collections` and rejects other Doctrine/Symfony in Domain.

## Phase 1.2 — Persistence
- Proposed and accepted: XML mapping only (`prefix: App\NotificationPublisher\Domain\Model`) replacing the
  starter `App` attribute driver; custom DBAL types for VO ids (`Uuid` only in the type) and string VOs;
  string-backed enums via XML `enum-type`; `NotificationContent` as an embeddable (`use-column-prefix=false`
  so columns are `subject`/`body`); repository adapters behind Domain ports with `#[AsAlias(..., public: true)]`.
- Rejected: keeping both attribute and XML mappings (driver chain would claim `App\NotificationPublisher\...`);
  a `notifications.status` column; `messenger_messages` / `cache_items` in this migration (3.1 / 4.1);
  fetching the port from the container without a public alias (the alias was inlined at compile time).
- Verified / corrected:
  - Bundled ORM XSD (`doctrine-mapping.xsd`) has `enum-type` on `<field>`; website XSD is stale.
    `validate_xml_mapping: true` + `doctrine:schema:validate` → `[OK]` mapping and database.
  - Doctrine `UnitOfWork` stringifies identifiers: UUID VOs gained `__toString()` (no Doctrine import).
  - Unique `(notification_id, channel)` and `idempotency_key` throw `UniqueConstraintViolationException`
    (`DoctrineNotificationRepositoryTest`, 3 tests). Migration applied on `app` and `app_test`.

## Phase 1.3 — Accept notification API
- Proposed and accepted: controller dispatches `SendNotification` on the default `MessageBusInterface` and reads
  `HandledStamp` (no `command.bus` yet — 3.1). `InMemoryRecipientResolver` seeded from `notifications.recipients`
  (`user-1` has email+phone, `user-email-only` has email). Optional `recipient` override on the DTO. Notification
  status is derived in `NotificationPresenter` (no column). Error listener always emits the five `Problem` fields.
- Rejected: `#[OA\JsonContent(ref: Schema::class)]` string refs (Nelmio left a FQCN `$ref`, coverage failed);
  `Model()` on empty attribute-only schema classes (swagger-php warned `Multiple definitions for @OA\Schema()->schema`
  and PHPUnit `failOnWarning` failed). Schema classes are now property DTOs aliased in `nelmio_api_doc.yaml`.
- Rejected: listener priority `-256` (after `ErrorListener` `-128`). `ExceptionEvent::setResponse()` stops
  propagation, so domain exceptions stayed HTML 500. Listener runs at `-8` (after log, before HTML renderer).
- Verified: `NotificationApiTest` POST 202 + two pending deliveries; replay 200 same id; unknown user 422 and no
  rows; missing sms contact 422 names `sms`; malformed JSON 400; missing field 422; unknown id 404; each call
  `assertResponseIsDocumented()`. `OpenApiCoverageTest` + `OpenApiSnapshotTest` green after dump.

## Phase 1.4 — Day 1 wrap
- Proposed and accepted: no product code in this phase; wrap is the five-command gate plus STATUS / TRACE / learning.
- Rejected: starting 2.1 in the same chat (brief: do not start Day 2). Extra product/lint commit — 1.3 already left
  the tree green; only handoff docs change.
- Verified / corrected (inside the app container, 2026-09-21):
  - `vendor/bin/phpunit` → `OK (37 tests, 409 assertions)`
  - `vendor/bin/php-cs-fixer check --diff` → `Found 0 of 86 files that can be fixed`
  - `vendor/bin/phpstan analyse --memory-limit=1G` (after `cache:warmup`) → `[OK] No errors`
  - `php tools/check-docs.php` → `check-docs: OK (67 classes mapped)`
  - `php tools/check-layers.php` → `check-layers: OK`
  - `GET http://localhost:18080/health` → `{"status":"ok"}`
  - TRACE: no 1.1–1.3 row was still `pending`. R22 promoted to `done` (`check-layers` + XML mapping).
    R01/R02/R17/R19/R23/R31 stay `in progress` (providers, e2e send, tracking polish, later tests).

## Phase 2.1 — Channel configuration, provider registry and fake providers
- Proposed and accepted: validation in `ValidateChannelConfigurationPass` (`Kernel::build()`), not in
  `ChannelConfiguration`'s constructor. A constructor check runs only when the service is created, so `cache:clear`
  can succeed and the bad config reaches a request. The pass calls `resolveEnvPlaceholders(..., true)` so a typo in
  `NOTIFICATIONS_*_PROVIDERS` fails compilation. Trade-off: changing those env vars requires a cache rebuild.
- Proposed and accepted: `_instanceof` tags `NotificationProvider` so the Domain port stays free of Symfony
  attributes; each fake carries `#[AsTaggedItem(index: ...)]`. The registry prefers that iterator key and falls
  back to `name()` for `fromList()`. `#[Autoconfigure(public: true)]` on the registry keeps the tagged fakes
  from being removed as unused before 2.2 injects them.
- Rejected: `defaultIndexMethod: 'name'` (that option requires a static method). Rejected listing `smtp` / `twilio`
  in this phase's config (the compiler pass would fail until those adapters exist).
- Verified: `cache:clear` with `NOTIFICATIONS_SMS_PROVIDERS=fake_smss` exits 1 with
  `Unknown provider "fake_smss" configured for channel "sms".` (`--no-warmup` does not compile, so it does not
  check). `debug:container --tag=notification.provider` lists both fakes. `FAKE_SMS_MODE=transient` in `.env.local`
  made `FakeSmsProvider::send()` throw `TransientProviderFailure` with no code change; `.env.local` was reverted.

## Phase 2.2 — Failover strategy
- Proposed and accepted: `DeliveryResult` (`sent` / `retryLater` / `failed`) instead of throwing Messenger
  exceptions inside the strategy. Phase 3.1 maps the result once. Provider-level permanent failure uses a
  counter so a third provider is not called; a plain `continue` (the old skill fragment B3) would keep going.
- Proposed and accepted: `ProviderDirectory` in Application, implemented by `ProviderRegistry`. The strategy
  cannot typehint the registry: `check-layers` forbids Application → Infrastructure.
- Rejected: `createMock(NotificationProvider::class)`. Tests use `FakeSmsProvider::withMode($mode, $name)` so
  three fakes can share one registry. `beforeSend()` observes that the attempt is `in_progress` before `send()`.
- Verified / corrected: skill fragment B3's `startAttempt($name, $now)` does not match `Delivery::startAttempt`,
  which needs a `DeliveryAttemptId` (UUID v7). B3 was rewritten to the counter and the real signatures.
  R08's proof no longer names `TwilioSmsProviderTest` (that class arrives in 2.4); the policy is encoded by
  `FailoverDeliveryStrategyTest`.

## Phase 2.3 — SMTP provider
- Proposed and accepted: inject `TransportInterface` (the `MAILER_DSN` transport), not `MailerInterface`.
  `MailerInterface` routes through the message bus, so a later `SendEmailMessage` routing entry would send
  asynchronously and transport errors would arrive as `HandlerFailedException`. `TransportInterface::send()`
  returns a `SentMessage` on the calling thread. `Message-ID` is `<{deliveryId}@notifications.local>` and that
  string is `provider_message_id`, so a redelivery after an unknown outcome is a duplicate an MTA can drop.
- Proposed and accepted: `SmtpFailureClassifier` reads `TransportException::getDebug()`. Verified against
  symfony/mailer 7.4 `AbstractStream` and `SmtpTransport::doSend`: client lines are `[timestamp] > COMMAND`,
  server lines are `[timestamp] < REPLY`, and the message body is written with debug disabled. `DATA` is written
  before the `354` reply. `> DATA` or `< 354` → `UnknownProviderOutcome`. A `4xx`/`5xx` reply to `RCPT TO` →
  `PermanentProviderFailure` (recipient-level), including `450`. Any other pre-DATA failure, including a `5xx`
  on `MAIL FROM` and an empty log, → `TransientProviderFailure`.
- Rejected: treating every failure before `DATA`, including `RCPT TO` `550`, as transient. That was the earlier
  §3.3 wording; a rejected address must not fail over to `fake_email`.
- Verified by running it: `SmtpMailerProviderTest` on `null://null` captured one address, subject, and the same
  `Message-ID` for two sends of one delivery id. `cache:clear` succeeded with
  `NOTIFICATIONS_EMAIL_PROVIDERS=smtp,fake_email`. `debug:container --tag=notification.provider` lists three
  services.

## Phase 2.4 — Twilio SMS adapter
- Proposed and accepted: `TwilioSmsProvider` posts `Messages.json` with basic auth and `timeout: 5.0`.
  Credentials come from `TWILIO_*` (`ACtest` / `test-token` / `+15005550006` in `.env`). `getStatusCode()` plus
  `toArray(false)` keep 4xx/5xx as data. `TwilioFailureClassifier`: 201 + `sid` success; 400 `21211`/`21614`
  recipient permanent; any other 4xx (including `21212` invalid From) provider permanent; 429 and 5xx transient;
  `TransportExceptionInterface`, or a 201 with no sid, unknown.
- Rejected: treating every 400 as recipient-level. `21212` is the sender, so another provider may still deliver.
  Rejected: calling `toArray()` without `false` (that throws `ClientException` / `ServerException` and would skip
  classification).
- Verified / corrected: official error pages (2026-09-22) title `21211` "Invalid 'To' Phone Number", `21614`
  "'To' number is not a valid mobile number", `21212` "Invalid 'From' Number". The Message resource docs show
  `POST /2010-04-01/Accounts/{AccountSid}/Messages.json`, basic auth, form fields `To`/`From`/`Body`, and a body
  with `sid` (`status: queued`). That page did not print the HTTP status; 201 is the create status in the plan
  and in Twilio's create-message success criterion. `MockHttpClient` moves `auth_basic` into
  `normalized_headers['authorization']` before the callback, so the test asserts that header.
  `cache:clear` OK. `debug:container --tag=notification.provider` lists four services.
  Gate: phpunit `OK (88 tests, 592 assertions)`, php-cs-fixer `Found 0 of 117 files`, phpstan `[OK] No errors`,
  `check-docs: OK (86 classes mapped)`, `check-layers: OK`.

## Phase 3.1 — Messenger wiring
- Proposed and accepted: two buses. `command.bus` (`doctrine_transaction`) commits the notification and the
  `DeliverNotification` rows together. `delivery.bus` has only `doctrine_ping_connection`. A transaction there
  would roll back attempt rows when the handler throws `RecoverableMessageHandlingException` or
  `UnrecoverableMessageHandlingException`. The flush of `in_progress` is a callback into
  `FailoverDeliveryStrategy::deliver()`, because `startAttempt` and `send()` are inside that loop; the handler
  cannot save between them. `failed` uses `auto_setup=0` as well, so a worker does not create the table.
- Proposed and accepted: transient exhaustion leaves the delivery `pending`. `DECISIONS` §3.1 used to say it is
  marked `failed`, which would make `isFinal()` ignore `messenger:failed:retry`.
- Rejected: skill fragment B1's throttle / `DelayStamp` (rate limiting is 4.1). Rejected `sync://` in test
  (DECISIONS §3.6). Rejected `DeliveryResult::isSent()` / `isFailed()` from the old R3 fragment; the class
  exposes `succeeded()` and `isPermanent()`.
- Verified / corrected: `Delivery.notification` must be `EAGER`. A lazy `Notification` is born with its readonly
  `id` set, and Doctrine's `ReadonlyAccessor` throws when hydration assigns a second instance. `test_it_sends_a_pending_delivery`
  failed on that before the fetch change. Symfony 7.4 `debug:messenger` lists buses and handlers, not senders;
  `debug:config framework messenger` shows `DeliverNotification` -> `async`. Dev POST of email queued
  `messenger_messages` (`queue_name=default`); `messenger:consume async --limit=1 -vv` marked it `sent` via smtp.
  `GetNotificationStatusHandler` is pinned to `command.bus` so a handler with no bus is not registered on every bus.
  Gate: phpunit `OK (93 tests, 684 assertions)`, php-cs-fixer `Found 0 of 122 files`, phpstan `[OK] No errors`,
  `check-docs: OK (88 classes mapped)`, `check-layers: OK`.

## Phase 3.2 — Worker and Mailpit
- Proposed and accepted: `worker` uses the same dev image and volume, command `messenger:consume async --time-limit=3600 --memory-limit=256M -vv`, `restart: unless-stopped`, `depends_on` app healthy and Mailpit started. `RUN_MIGRATIONS=1` is the only Compose `environment:` key, and only on `app`. Dev `MAILER_DSN=smtp://mailpit:1025`; `.env.test` keeps `null://null`.
- Rejected: `healthcheck.disable: true`. This Compose version then fails `up --wait` with "has no healthcheck configured". Rejected putting `MAILER_DSN` in `.env.local`, because `env_file` would override `.env.test` for PHPUnit. Rejected `curl.exe -d "{...}"` in PowerShell (it strips the quotes and curl treats `[` as a range).
- Verified / corrected: the FrankenPHP image healthchecks `http://localhost:2019/metrics`, so the worker replaces it with `ps | grep messenger:consume`. `docker/entrypoint.sh` is copied into the image, so the migration hook needs a rebuild. App log: `Already at the latest version ("DoctrineMigrations\Version20260923110356")` before FrankenPHP starts; the worker log does not migrate. `debug:dotenv` shows dev `smtp://mailpit:1025` and test `null://null`. `SmtpMailerProviderTest` OK (2 tests, 11 assertions). POST email → worker `was handled successfully`; Mailpit UI shows To `user1@example.test`, subject `Hello`. With Mailpit stopped, attempts are `smtp`/`transient_failure` then `fake_email`/`succeeded`. `messenger:failed:show` prints `[OK] No failed messages were found.` Gate: phpunit `OK (93 tests, 684 assertions)`, php-cs-fixer `Found 0 of 122 files`, phpstan `[OK] No errors`, `check-docs: OK (88 classes mapped)`, `check-layers: OK`.

## Phase 3.3 — End-to-end delivery tests
- Proposed and accepted: `tests/Support/DeliveryPipeline` posts, pulls `messenger.transport.async`, and dispatches each envelope on `delivery.bus` with `ReceivedStamp('async')`. The test resolves those services (PHPStan treats them as private outside a `TestCase`). Fake modes and `NOTIFICATIONS_SMS_ENABLED` are written to `$_ENV` and `$_SERVER` before `createClient()`. Email tests set `NOTIFICATIONS_EMAIL_PROVIDERS=fake_email,smtp` so `fake_email` is the primary (priority order) and `smtp` on `null://null` is the secondary that succeeds. All-fail sets `MAILER_DSN=smtp://127.0.0.1:1`: connection refused is about 1ms and is classified as transient before `DATA`, so the delivery stays `pending` with two attempts. The same envelope dispatched twice after success records one attempt.
- Rejected: `sync://` (DECISIONS §3.6). Rejected mocking the fakes. Rejected a second fake class, and rejected listing `fake_email` twice: the null transport cannot fail, but a refused SMTP connection is a real second provider. Rejected saving the previous env with `getenv()` — Symfony Dotenv fills `$_ENV` / `$_SERVER` and does not call `putenv`, so `getenv()` was false and restore deleted `MAILER_DSN` for later tests.
- Verified / corrected: `DeliveryPipelineTest` — `OK (8 tests, 579 assertions)`. Full gate: phpunit `OK (101 tests, 1263 assertions)`, php-cs-fixer `Found 0 of 124 files`, phpstan `[OK] No errors`, `check-docs: OK (88 classes mapped)`, `check-layers: OK`. The first full run failed after all-fail because restore had wiped `MAILER_DSN`, `NOTIFICATIONS_EMAIL_PROVIDERS`, and `NOTIFICATIONS_SMS_ENABLED`. Snapshot is now `$_ENV` / `$_SERVER`.

## Phase 3.4 — Manual verification
- Proposed and accepted: `retryLater` throws `DeliveryRequiresRetry`, not `RecoverableMessageHandlingException`. Symfony's `SendFailedMessageForRetryListener` returns true for `RecoverableExceptionInterface` before it consults `max_retries`, so a transient exhaustion retried forever (observed retry #7 at the 300s cap). The delivery stayed `pending`. After the change, the same message was removed after 8 retries and sent to the `failed` transport.
- Rejected: marking that delivery `failed` in the aggregate. `isFinal()` would make `messenger:failed:retry` a no-op.
- Verified by running it: `make send` → Mailpit UI shows To `user1@example.test`, subject `Hello`. Worker stopped → POST stays `pending` with a `messenger_messages` row; worker started → `sent` in 2s and Mailpit shows `Queued`. Mailpit stopped → attempts `smtp`/`transient_failure` then `fake_email`/`succeeded`, GET `status` `sent`. GET still has no attempt list (that is 4.2); the trail is `notification_delivery_attempts`. Both providers transient → `messenger:failed:show` listed id 18; `messenger:failed:retry --force -vv` printed `was handled successfully`; Mailpit shows subject `AllFail`; failed transport empty. `DeliveryPipelineTest` OK (9 tests, 647 assertions), including `test_it_moves_the_message_to_the_failed_transport_when_retries_are_exhausted` (test env `max_retries: 1`, `delay: 0`). Gate: phpunit OK (102 tests, 1331 assertions), php-cs-fixer 0/125, phpstan OK, check-docs 89 classes, check-layers OK.

## Mapping follow-up — ORM attributes on aggregates
- Proposed and accepted: replace XML mapping with `#[ORM\Entity]` on Domain model classes. Reason: pragmatic DDD
  (aggregates as Doctrine entities) scales with the Symfony stack; XML was a second file per class.
- Rejected: a separate Infrastructure `*Entity` layer (double bookkeeping); putting `EntityManager` in Domain.
- Still in Infrastructure: repositories, custom DBAL types. `check-layers` allows `Doctrine\ORM\Mapping` only.

## Phase 4.1 — Throttling
- Proposed and accepted: `cache.rate_limiter` on `cache.adapter.doctrine_dbal` so the app and the worker share counters. `per_user_notifications` is a sliding window (`NOTIFICATIONS_THROTTLE_LIMIT` / `NOTIFICATIONS_THROTTLE_INTERVAL`, defaults 300 and `1 hour`). `lock_factory: null` because the default flock lock is local to one container. `RateLimiterDeliveryThrottle` keys the limiter by user id. `DeliverNotificationHandler` calls it only when `requiresUserAction` is true, then `markThrottled()`, saves, re-dispatches on `delivery.bus` with `DelayStamp`, and returns. `.env.test` sets the limit to 3.
- Rejected: throwing on throttle (that would consume one of the five failure retries, DECISIONS §3.6). Rejected a `throttled` request field (§2.3). Rejected checking the flag inside the adapter; the port answers allow-or-delay, and the handler decides which deliveries are in scope.
- Verified / corrected: `doctrine:migrations:diff` emitted `cache_items` (`item_id VARBINARY(255)`, `item_data MEDIUMBLOB`, lifetime and time unsigned). No hand-written DDL. `doctrine:schema:validate` mapping and database `[OK]`. Symfony 7.4 `SlidingWindow` reads `microtime()`, and `MockClock` does not replace that, so `tests/bootstrap.php` registers `ClockMock` for the policy namespace before the class is loaded. `isExpired()` is a strict `>`, so the unit test steps `+1 hour +1 second`. `RateLimiterDeliveryThrottleTest` OK (1 test, 8 assertions). `test_it_throttles_the_fourth_user_action_and_ignores_the_rest` OK (1 test, 402 assertions): 4th user-action delivery `throttled` with one `DelayStamp` envelope; a delivery with the flag false is sent and is not counted, including one sent after the window is full. A second process run of that test still passes, so the DBAL counter rolls back with the test transaction. Gate: phpunit `OK (104 tests, 1736 assertions)`, php-cs-fixer `Found 0 of 128 files`, phpstan `[OK] No errors`, `check-docs: OK (90 classes mapped)`, `check-layers: OK`.


