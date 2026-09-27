# Notification service

Accept a notification over HTTP, deliver it asynchronously on email and SMS, and record every attempt.

Symfony 7.4 LTS · PHP 8.4 · MariaDB 11.4 · FrankenPHP. Email is real SMTP into Mailpit. SMS is a Twilio adapter; local credentials in `.env` are dummies, and `fake_sms` is the failover.

Do not install PHP or Composer on the host. Both run in the `app` container. This README is written for Windows PowerShell. Every container command uses `docker compose exec -T` (no TTY). On bash, `curl.exe` is `curl`.

If `make` is installed, the same actions are `make run`, `make test`, `make send`, `make stop`. The table is `docs/agent/COMMANDS.md`.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) with Compose v2 (Docker Desktop on Windows)

From the project root for every command below.

## Start

```powershell
docker compose up -d --wait
```

The first `up` builds the image, runs `composer install` when `vendor/` is missing, starts MariaDB (`app` and `app_test`), Mailpit, and the Messenger worker. The `app` container applies migrations (`RUN_MIGRATIONS=1`). `.env` is enough to boot (`HTTP_PORT=18080`, database user and password `app`).

Health:

```powershell
curl.exe -fsS http://localhost:18080/health
```

Expected body: `{"status":"ok"}`.

## Test

Tests use the `app_test` database. Each test runs in a transaction that `dama/doctrine-test-bundle` rolls back.

```powershell
docker compose exec -T app bin/console doctrine:database:create --if-not-exists --env=test
docker compose exec -T app bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
docker compose exec -T app vendor/bin/phpunit
```

Expected: `Database "app_test" ... already exists. Skipped.` or `Created`, then migrations at the latest version, then PHPUnit `OK`.

## Exercise

Swagger UI first, then the same calls from the shell, then Mailpit.

The docs routes are unauthenticated in every environment so you can try them immediately. Behind a real gateway they would be restricted.

```powershell
curl.exe -fsS -o NUL -w "%{http_code}" http://localhost:18080/api/doc
curl.exe -fsS -o NUL -w "%{http_code}" http://localhost:18080/api/doc.json
```

Both print `200`. Open http://localhost:18080/api/doc . Every operation has a summary. **Try it out** on `GET /health` returns `{"status":"ok"}`. `POST /notifications` has an example body. Each status on an operation has a response schema (202 and 200 for accept, 400 and 422 for a bad body, 404 for an unknown id, 400 for a bad `since`).

A generated copy of the spec is `docs/openapi.json`. After changing an `#[OA\...]` attribute, regenerate it. The redirect runs inside the container: a PowerShell `>` writes UTF-16 and breaks `OpenApiSnapshotTest`.

```powershell
docker compose exec -T app sh -c "bin/console nelmio:apidoc:dump --format=json --no-ansi > docs/openapi.json"
```

Send one email. The worker delivers it. `user-1` is seeded with `user1@example.test`.

```powershell
$key = "readme-$([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())"
$json = '{"userId":"user-1","idempotencyKey":"' + $key + '","channels":["email"],"subject":"Hello","body":"Your order is confirmed."}'
$created = Invoke-RestMethod -Method Post -Uri http://localhost:18080/notifications -ContentType 'application/json' -Body $json
$created | ConvertTo-Json -Depth 6
1..20 | ForEach-Object {
  $status = Invoke-RestMethod "http://localhost:18080/notifications/$($created.id)"
  if ($status.status -eq 'sent') { $status | ConvertTo-Json -Depth 8; break }
  Start-Sleep -Seconds 1
}
Invoke-RestMethod "http://localhost:18080/users/user-1/notifications"
Invoke-RestMethod "http://localhost:18025/api/v1/search?query=Hello"
```

Expected: the POST prints `"status": "pending"` and HTTP is 202 the first time that `idempotencyKey` is seen (a replay is 200 and the same id). The poll prints `"status": "sent"` and an attempt whose provider is `smtp`. The user list includes that notification. The Mailpit search returns a message whose Subject is `Hello`. The same inbox is the UI at http://localhost:18025 .

`curl.exe -d "{...}"` in PowerShell strips quotes and treats `[` as a character range, which is why the POST above is `Invoke-RestMethod`.

## Assumptions

- Recipient addresses come from an identity service. Here they are seeded in `config/packages/notifications.yaml` (`user-1` has email and phone, `user-email-only` has email only). A request may override them with `recipient.email` and `recipient.phone`. An unknown user, or a known user with no contact for a requested channel, is HTTP 422 and nothing is stored.
- The caller sends the final `subject` and `body`. This service does not render templates.
- Delivery is at-least-once. Exactly-once is not achievable with SMTP or Twilio. The same `idempotencyKey` returns the existing notification. A Messenger redelivery does not send again once the delivery is final. SMTP `Message-ID` is derived from the delivery id.
- Notifications with `requiresUserAction: true` are limited to 300 per user per hour (sliding window, shared by the app and the worker). Past the limit the delivery stays `throttled` and is delayed; it does not use up a failure retry.
- `.cursor/rules` and `.cursor/skills` are part of how the assistant was used. The log is `docs/AI_NOTES.md`. Choices are `docs/DECISIONS.md`.
- What was left out, and why, is `docs/DECISIONS.md` §5: push, templates, provider cooldown / circuit breaker, delivery-receipt webhooks, API authentication, multi-tenancy, FrankenPHP worker mode.

## Extending

On Windows, the console commands in the recipe below are:

```powershell
docker compose exec -T app bin/console debug:container --tag=notification.provider
docker compose exec -T app bin/console cache:clear
docker compose exec -T app vendor/bin/phpunit --filter TwilioSmsProviderTest
```

`debug:container` lists `smtp`, `fake_email`, `twilio` and `fake_sms`. `cache:clear` succeeds on the current config. The filter is one provider test, as a stand-in for the new adapter's test. Do not put a typo in the config while following this README; that check is for when you add a provider.

The recipe for a new provider, and for a push channel, is the `add-notification-provider` skill:

# Add a provider or a channel

The domain (`Notification`, `Delivery`, `FailoverDeliveryStrategy`) never changes for a new provider. A provider is
an Infrastructure adapter of the `NotificationProvider` port plus one line of configuration.

## Add a provider to an existing channel

1. **Adapter** — `src/NotificationPublisher/Infrastructure/Provider/<Channel>/<Vendor><Channel>Provider.php`,
   `final readonly class`, implements `NotificationProvider`:
   - `name()` returns the config key (`'ses'`), `channel()` returns the enum case.
   - `send()` maps every failure into exactly one of: `TransientProviderFailure` (429, 5xx, connection refused),
     `PermanentProviderFailure(recipientLevel: true)` (invalid address/number), `PermanentProviderFailure(recipientLevel: false)`
     (auth/config), `UnknownProviderOutcome` (timeout after the request left, dropped connection).
   - Credentials via `#[Autowire('%env(VENDOR_API_KEY)%')]`; HTTP via injected `HttpClientInterface` with a timeout.
   - Where the vendor supports an idempotency key, pass the delivery id.
2. **Tag** — nothing to do if `_instanceof` tagging on the port is configured (`services.yaml`); the registry discovers it.
3. **Configuration** — add the name to the channel's provider list in `config/packages/notifications.yaml`
   (and the env default `NOTIFICATIONS_<CHANNEL>_PROVIDERS`). Add env vars to `.env` with empty/dummy defaults; real
   values only in `.env.local`.
4. **Tests** — `tests/Unit/.../<Vendor><Channel>ProviderTest.php` with `MockHttpClient`: success, recipient-level 4xx,
   auth 401/403, 429/5xx, transport timeout. Assert the outgoing request (URL, auth header, body) once.
5. **Docs** — CODE_MAP row, RECIPES fragment if the adapter shape is new, README provider table, DECISIONS one-liner
   for the classification choices, REQUIREMENTS_TRACE row "at least two providers per channel" if relevant.
6. **Verify** — `bin/console debug:container --tag=notification.provider` lists it; put a typo in the config and
   `cache:clear` must fail; run the unit test.

## Add a new channel (e.g. push)

1. Add the case to the `Channel` enum (Domain) and the recipient contact kind it needs (`Recipient` VO / resolver).
2. Add the channel block to `notifications.yaml` with `enabled`, `strategy`, `providers`.
3. Add at least two providers (one may be a `Fake<Channel>Provider` under `Provider/Fake/` with the standard modes).
4. Extend the request DTO `channels` choice list (it derives from the enum — usually no change).
5. Tests: enum expansion in `NotificationTest`, a fake-driven end-to-end test, provider unit tests.
6. Docs as above; REQUIREMENTS_TRACE row for "multiple channels".

## Fake provider modes (reuse, do not reinvent)
`FakeMode::Success | Transient | PermanentRecipient | PermanentProvider | Timeout`, selected by env
`FAKE_<CHANNEL>_MODE`; the fake records sent messages in memory for assertions.

## Database

No database port is published. A non-interactive shell:

```powershell
docker compose exec -T database mariadb -uapp -papp app -e "SHOW TABLES;"
```

To attach a GUI client, uncomment the `ports` block on the `database` service in `compose.yaml`.

## Debugging with Xdebug

Xdebug 3 is in the dev image and off unless `.env.local` sets `XDEBUG_MODE=debug`. It listens for the IDE at `host.docker.internal:9003` and starts only when the request carries `XDEBUG_TRIGGER`.

```powershell
Copy-Item .env.local.dist .env.local
docker compose up -d --wait
curl.exe -fsS "http://localhost:18080/health?XDEBUG_TRIGGER=1"
```

In Cursor: Run and Debug, "Listen for Xdebug (Docker app container)", breakpoint on the `return` in `src/Controller/HealthController.php`, then the curl above. Path mapping `/app -> ${workspaceFolder}` is in `.vscode/launch.json`. CLI: `docker compose exec -T -e XDEBUG_TRIGGER=1 app vendor/bin/phpunit --filter HealthEndpointTest`.

Do not put `MAILER_DSN` in `.env.local`. Compose passes that file into the container as real environment variables, which override `.env.test`, and PHPUnit would send mail to Mailpit instead of `null://null`.

## Stop

```powershell
docker compose down
```

Volumes are kept. `docker compose down -v` also deletes the database.
