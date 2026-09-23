# Commands and expected output

Every `make` target with its `docker compose` equivalent (Windows hosts without `make`). Run from the project root
with the stack up (`make run` / `docker compose up -d --wait`).

| Purpose | `make` | Equivalent | Expect |
|---|---|---|---|
| Start stack | `make run` | `docker compose up -d --wait` | app + database healthy; worker running; Mailpit UI on `http://localhost:18025`; `curl localhost:18080/health` -> `{"status":"ok"}` |
| Stop stack | `make stop` | `docker compose down` | containers removed, volumes kept |
| Rebuild image | `make build` | `docker compose build --pull` | required after `docker/entrypoint.sh` changes (the script is copied into the image) |
| Prepare test DB | `make test-db` | `docker compose exec -T app bin/console doctrine:database:create --if-not-exists --env=test` then `docker compose exec -T app bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test` | `Database app_test ... already exists. Skipped.` (or `Created`); migrate prints executed versions or the "no registered migrations" warning |
| Tests | `make test ARGS="--filter Name"` (runs `test-db` first) | `docker compose exec -T app vendor/bin/phpunit --filter Name` | `OK (n tests, m assertions)`; runs against `app_test`, each test rolled back by dama |
| Cold start | — | `docker compose down -v; docker compose up -d --wait` | fresh volume; `docker/mariadb/init.sql` creates `app_test` + grants (check: `docker compose exec -T database mariadb -uroot -papp -e "SHOW DATABASES LIKE 'app%'"`) |
| Tests + Xdebug | `make test-debug` | `docker compose exec -T -e XDEBUG_TRIGGER=1 app vendor/bin/phpunit` | breakpoints hit when `.env.local` has `XDEBUG_MODE=debug` and the IDE listener runs |
| Enable Xdebug | — | `Copy-Item .env.local.dist .env.local; docker compose up -d` then `docker compose exec -T app printenv XDEBUG_MODE` | `debug`; HTTP trigger: `curl "localhost:18080/health?XDEBUG_TRIGGER=1"` |
| Code style check | `make cs` | `docker compose exec -T app vendor/bin/php-cs-fixer check --diff` | `Found 0 of N files that can be fixed` |
| Code style fix | `make cs-fix` | `docker compose exec -T app vendor/bin/php-cs-fixer fix` | files rewritten in place |
| Static analysis | `make phpstan` | `docker compose exec -T app bin/console cache:warmup -q && docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G` | `[OK] No errors` |
| Docs / layers gate | `make check-docs` | `docker compose exec -T app php tools/check-docs.php && docker compose exec -T app php tools/check-layers.php` | `check-docs: OK (n classes mapped)` and `check-layers: OK` |
| Everything | `make lint` | run the three above | all OK |
| Worker logs | `make worker-logs` | `docker compose logs -f worker` | lines `Received message` / `was handled successfully` |
| Failed messages | `make failed` | `docker compose exec -T app bin/console messenger:failed:show` | `[OK] No failed messages were found.` when the transport is empty |
| Retry failed | `make retry-failed` | `docker compose exec -T app bin/console messenger:failed:retry --force -vv` | each id `was handled successfully` (or the handler error) |
| Send email | `make send` | see below | HTTP 202 JSON with `"status":"pending"`; within a few seconds Mailpit shows the message |
| Send failover | `make send-failover` | see below | Mailpit stopped, then started again; GET JSON `"status":"sent"` after SMTP fails over to `fake_email` |
| Symfony console | — | `docker compose exec -T app bin/console <cmd>` | e.g. `about`, `debug:container --tag=notification.provider`, `debug:messenger` |
| Messenger buses | — | `docker compose exec -T app bin/console debug:messenger` | `delivery.bus` handles `DeliverNotification`; `command.bus` handles `SendNotification` |
| Messenger routing | — | `docker compose exec -T app bin/console debug:config framework messenger` | `failure_transport: failed`; `DeliverNotification` sender is `async`; retry `max_retries: 5`, `delay: 2000`, `multiplier: 3`, `max_delay: 300000` |
| Consume one delivery | — | `docker compose exec -T app bin/console messenger:consume async --limit=1 -vv` | `was handled successfully (acknowledging to transport)`; that delivery is `sent` |
| Migration diff | — | `docker compose exec -T app bin/console doctrine:migrations:diff --no-interaction` | writes `migrations/Version*.php`, or "No changes detected" |
| Apply migrations | — | `docker compose exec -T app bin/console doctrine:migrations:migrate --no-interaction` | `Successfully migrated` (add `--env=test` for `app_test`) |
| Schema in sync | — | `docker compose exec -T app bin/console doctrine:schema:validate --no-interaction` | mapping `[OK]` and database `[OK]` |
| DB shell | — | `docker compose exec database mariadb -uapp -papp app` | MariaDB prompt |
| Composer | — | `docker compose exec -T app composer require --no-interaction <pkg>` | lock file updated; Flex recipes applied |
| Dump OpenAPI spec | — | `docker compose exec -T app bin/console nelmio:apidoc:dump --format=json > docs/openapi.json` | overwrites `docs/openapi.json`; `OpenApiSnapshotTest` goes green |
| Swagger UI | — | open `http://localhost:18080/api/doc` (or `curl.exe -s -o NUL -w "%{http_code}" http://localhost:18080/api/doc`) | HTML 200; "Try it out" on `GET /health` returns `{"status":"ok"}` |
| OpenAPI JSON | — | `curl.exe -s http://localhost:18080/api/doc.json` | JSON with `"paths": { "/health": { "get": ... } }` |

`make send` equivalent (PowerShell). `curl.exe` mishandles the JSON quotes, so use `Invoke-RestMethod`:

```powershell
$key = "send-$([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())"
$json = '{"userId":"user-1","idempotencyKey":"' + $key + '","channels":["email"],"subject":"Hello","body":"Your order is confirmed."}'
Invoke-RestMethod -Method Post -Uri http://localhost:18080/notifications -ContentType 'application/json' -Body $json
```

Then open `http://localhost:18025` (or `Invoke-RestMethod "http://localhost:18025/api/v1/search?query=Hello"`). The message To is `user1@example.test`, subject `Hello`.

`make send-failover` equivalent (PowerShell). SMTP to a stopped Mailpit is a transient failure, so the worker uses `fake_email` and the delivery becomes `sent`. Mailpit is started again at the end.

```powershell
docker compose stop mailpit
try {
  $key = "failover-$([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())"
  $json = '{"userId":"user-1","idempotencyKey":"' + $key + '","channels":["email"],"subject":"Failover","body":"SMTP is down."}'
  $created = Invoke-RestMethod -Method Post -Uri http://localhost:18080/notifications -ContentType 'application/json' -Body $json
  1..30 | ForEach-Object {
    $status = Invoke-RestMethod "http://localhost:18080/notifications/$($created.id)"
    if ($status.status -eq 'sent') { $status | ConvertTo-Json -Compress; break }
    Start-Sleep -Seconds 1
  }
} finally {
  docker compose start mailpit
}
```

Attempt rows (GET does not list them until phase 4.2):

```powershell
docker compose exec -T database mariadb -uapp -papp app -e "SELECT provider, outcome FROM notification_delivery_attempts ORDER BY started_at DESC LIMIT 5;"
```

Notes
- `-T` disables TTY allocation; required when the command is run by the agent or in CI.
- PHPStan needs the dev container dump (`var/cache/dev/App_KernelDevDebugContainer.xml`); `make phpstan` warms it up.
- `bin/console debug:container --tag=notification.provider` lists `SmtpMailerProvider`, `FakeEmailProvider`, `FakeSmsProvider` and `TwilioSmsProvider`.
- `bin/console cache:clear` (with warmup) fails if a provider name is unknown:
  `Unknown provider "fake_smss" configured for channel "sms".` (`--no-warmup` skips compilation and does not check).
- `check-docs` treats RECIPES sections whose heading contains "template" as unverified and skips them.
- Compose reads `.env` for interpolation and passes `.env.local` (if present) into the app and worker via `env_file`.
  `environment: RUN_MIGRATIONS=1` is set on `app` only and overrides `env_file` for that one key. Do not put `MAILER_DSN`
  in `.env.local`: a real variable wins over `.env.test`, so PHPUnit would send to Mailpit instead of `null://null`.
  The MariaDB init script only runs when the `database_data` volume is created.
- Dev `MAILER_DSN` is `smtp://mailpit:1025` (Compose network). Mailpit UI is host port 18025, SMTP host port 1025.
- The `worker` healthcheck looks for `messenger:consume`. The image's own check curls Caddy on `:2019`, and
  `docker compose up --wait` fails a service that has no healthcheck.
