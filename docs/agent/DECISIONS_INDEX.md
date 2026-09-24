# Decisions index (one-liners -> `docs/DECISIONS.md`)

Read this instead of re-deriving a settled decision. If a phase needs to change one, edit the DECISIONS section,
update the line here and record the change in `docs/AI_NOTES.md`.

| Decision | Where |
|---|---|
| Symfony 7.4 LTS, not 6.4; write deprecation-free code so it is 8.0-ready | §1.1 |
| FrankenPHP kept in classic mode; worker mode documented, not enabled; Messenger worker healthcheck is the consume process, not Caddy | §1.2 |
| PHPStan level 8 with symfony/doctrine/phpunit extensions; no strict-rules, no level 9 | §1.3 |
| php-cs-fixer with `@Symfony` + risky + `@PER-CS2.0`; Pint rejected; `final` is convention not fixer rule | §1.4 |
| Layer and doc gates are `tools/check-layers.php` / `tools/check-docs.php`, not deptrac | §1.5 |
| Domain may import `Doctrine\Common\Collections` and `Doctrine\ORM\Mapping`; EntityManager / DBAL types stay in Infrastructure | §1.5 |
| Xdebug via `.vscode/launch.json` port 9003, `/app -> ${workspaceFolder}`; `.env.local` via Compose `env_file`; `environment:` is only `RUN_MIGRATIONS` on app | §1.6 |
| Messenger + Doctrine transport = outbox without a broker; Mailer, HttpClient, Uid v7, Clock, RateLimiter on DBAL cache | §1.7 |
| `symfony/notifier` rejected: its failover DSN hides the semantics we must design | §1.8 |
| OpenAPI via NelmioApiDocBundle + Swagger UI; dump gated by PHPUnit; not a hand-written spec, not API Platform | §1.9 |
| Channels/providers are parameters in `notifications.yaml`, env-overridable, validated at container build; throttle limit/interval from env, `lock_factory: null` | §2.1 |
| Provider ordering `priority` (default) or `round_robin`; same failover rules after the starting point | §2.2 |
| Request flag is `requiresUserAction`; recipients resolved by a config-seeded stub resolver | §2.3 |
| Unknown user or missing contact for a requested channel -> 422 at accept time; disabled -> `skipped` only once config exists (2.1) | §2.3 |
| Transient -> next provider; all transient -> `DeliveryRequiresRetry` (not `RecoverableExceptionInterface`) -> Messenger retry 5x (2 s x3 backoff, max 5 min) -> `failed` transport; delivery stays `pending` | §3.1 |
| Permanent recipient-level -> stop, delivery `failed`; permanent provider-level (401/403, other Twilio 400) -> one failover (counter), then `failed` | §3.2 |
| Twilio: 201 + sid success; 400 21211/21614 recipient; other 4xx provider; 429/5xx transient; transport error unknown | §3.2 |
| Unknown outcome (timeout after send) -> attempt `unknown`, **no same-run failover**, retry later; at-least-once accepted | §3.3 |
| Duplicate request -> unique `idempotency_key`, replay returns 200; redelivery -> handler no-ops when `sent`; attempt row `in_progress` before the call | §3.4 |
| Disabled channel -> delivery `skipped`, never dispatched | §3.5 |
| SMTP debug: 4xx/5xx on `RCPT TO` -> permanent recipient; other pre-DATA failures -> transient; from `DATA` on -> unknown | §3.3 |
| Two buses: `command.bus` with `doctrine_transaction` (outbox), `delivery.bus` without (handler flushes, then throws); tests use `in-memory://`, never `sync://` | §3.6 |
| Three domain tables (1.2, no `notifications.status`) + `messenger_messages` (3.1, `auto_setup=0`) + `cache_items` (4.1); aggregates mapped with ORM attributes | §4 |
| No users/contacts, providers/channels, templates or outbox tables | §4.1 |
| `app_test` from `docker/mariadb/init.sql`; schema prepared by `make test`; dama rollback per test | §4.2 |
| Out of scope: push channel, templating, cooldown/circuit breaker, receipt webhooks, API auth, multi-tenancy | §5 |
