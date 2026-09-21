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

