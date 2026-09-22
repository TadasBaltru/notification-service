# Commands and expected output

Every `make` target with its `docker compose` equivalent (Windows hosts without `make`). Run from the project root
with the stack up (`make run` / `docker compose up -d --wait`).

| Purpose | `make` | Equivalent | Expect |
|---|---|---|---|
| Start stack | `make run` | `docker compose up -d --wait` | app + database healthy; `curl localhost:18080/health` -> `{"status":"ok"}` |
| Stop stack | `make stop` | `docker compose down` | containers removed, volumes kept |
| Rebuild image | `make build` | `docker compose build --pull` | — |
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
| Symfony console | — | `docker compose exec -T app bin/console <cmd>` | e.g. `about`, `debug:container --tag=notification.provider`, `debug:messenger` |
| DB shell | — | `docker compose exec database mariadb -uapp -papp app` | MariaDB prompt |
| Composer | — | `docker compose exec -T app composer require --no-interaction <pkg>` | lock file updated; Flex recipes applied |
| Dump OpenAPI spec | — | `docker compose exec -T app bin/console nelmio:apidoc:dump --format=json > docs/openapi.json` | overwrites `docs/openapi.json`; `OpenApiSnapshotTest` goes green |
| Swagger UI | — | open `http://localhost:18080/api/doc` (or `curl.exe -s -o NUL -w "%{http_code}" http://localhost:18080/api/doc`) | HTML 200; "Try it out" on `GET /health` returns `{"status":"ok"}` |
| OpenAPI JSON | — | `curl.exe -s http://localhost:18080/api/doc.json` | JSON with `"paths": { "/health": { "get": ... } }` |

Notes
- `-T` disables TTY allocation; required when the command is run by the agent or in CI.
- PHPStan needs the dev container dump (`var/cache/dev/App_KernelDevDebugContainer.xml`); `make phpstan` warms it up.
- `bin/console debug:container --tag=notification.provider` lists `FakeEmailProvider` and `FakeSmsProvider`.
- `bin/console cache:clear` (with warmup) fails if a provider name is unknown:
  `Unknown provider "fake_smss" configured for channel "sms".` (`--no-warmup` skips compilation and does not check).
- `check-docs` treats RECIPES sections whose heading contains "template" as unverified and skips them.
- Compose reads `.env` for interpolation and passes `.env.local` (if present) into the app container via `env_file`;
  the MariaDB init script only runs when the `database_data` volume is created.
