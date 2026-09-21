# Backend Engineering Assignment

A minimal Symfony application that already runs in Docker, so you can spend your time on the
assignment rather than on environment setup.

Symfony 7.4 LTS · PHP 8.4 · MariaDB 11.4

## Prerequisites

- [Git](https://git-scm.com/downloads)
- [Docker](https://docs.docker.com/get-docker/) with Compose v2 (Docker Desktop on Windows/macOS)

Do not install PHP or Composer on the host. Both run inside the `app` container.

## Install

```sh
git clone https://github.com/TadasBaltru/notification-service.git
cd notification-service

docker compose build --pull
docker compose up -d --wait
```

That is the full install. The first `up`:

- builds the PHP 8.4 / FrankenPHP image
- runs `composer install` when `vendor/` is missing (entrypoint)
- starts MariaDB and creates databases `app` and `app_test`

`.env` is committed with local defaults (`HTTP_PORT=18080`, DB user/password `app`). No extra env file is required to boot.

Re-install PHP packages later (container must be running):

```sh
docker compose exec -T app composer install --prefer-dist --no-interaction
```

Apply Doctrine migrations when they exist (safe if the list is still empty):

```sh
docker compose exec -T app bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
```

If `make` is available: `make build` then `make run`. Full command table: `docs/agent/COMMANDS.md`.

## How to run

After install, from the project root:

```sh
docker compose up -d --wait
```

Health check:

```sh
curl http://localhost:18080/health
# {"status":"ok"}
```

## API documentation

Swagger UI (interactive, "Try it out"): [http://localhost:18080/api/doc](http://localhost:18080/api/doc)

Raw OpenAPI 3 spec: [http://localhost:18080/api/doc.json](http://localhost:18080/api/doc.json)

A generated copy lives at `docs/openapi.json` (import into Postman or Insomnia). Do not edit it by hand —
change the `#[OA\...]` attributes on the controller and dump:

```sh
docker compose exec -T app bin/console nelmio:apidoc:dump --format=json > docs/openapi.json
```

The docs routes are unauthenticated in every environment so evaluators can use them out of the box. Behind a
real gateway they would be restricted.

On Windows PowerShell use `curl.exe` (plain `curl` is an alias for `Invoke-WebRequest`), or open
http://localhost:18080/health in a browser.

Stop (volumes kept):

```sh
docker compose down
```

If `make` is available: `make run` / `make stop`.

## Run the tests

Stack must be up. Tests use a separate `app_test` database (created by `docker/mariadb/init.sql` on
the first volume init). Every test runs in a transaction that `dama/doctrine-test-bundle` rolls back.

```sh
docker compose exec -T app bin/console doctrine:database:create --if-not-exists --env=test
docker compose exec -T app bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
docker compose exec -T app vendor/bin/phpunit
```

If `make` is available: `make test` (prepares `app_test` then runs PHPUnit). Filter: `make test ARGS="--filter SomeTest"`.

## Where to write your solution

Your code goes in `src/`. The repository ships with this structure:

```
src/NotificationPublisher/
├── Application/
├── Domain/
├── Infrastructure/
└── UserInterface/
```

Keep it, reshape it or replace it — it is a starting point, not a requirement. You are equally free
to add any libraries or infrastructure your solution needs.

## Everyday commands

```sh
docker compose build --pull                  # rebuild images
docker compose exec -T app bin/console       # Symfony console
docker compose exec -T app composer require ...  # add a dependency
docker compose logs -f app                   # tail application logs
docker compose down -v                       # stop and wipe the database

make                                         # same shortcuts, if make is installed
```

## Database

Doctrine ORM and Doctrine Migrations are installed and pointed at the `database` service
(database `app`, user `app`, password `app`).

No database port is published to your host, so the stack cannot collide with a MySQL you already
run. For a shell:

```sh
docker compose exec database mariadb -uapp -papp app
```

To attach a GUI client instead, uncomment the `ports` block on the `database` service in
`compose.yaml`.

After adding entities:

```sh
docker compose exec app bin/console doctrine:migrations:diff
docker compose exec app bin/console doctrine:migrations:migrate
```

## Debugging with Xdebug (Cursor / VS Code)

Xdebug 3 is built into the dev image and off by default. It reaches the IDE at `host.docker.internal:9003`
and starts only for requests that carry a trigger, so leaving it enabled costs nothing on normal requests.

1. Install the recommended extension `xdebug.php-debug` (Cursor suggests it from `.vscode/extensions.json`).
2. Enable the mode: `cp .env.local.dist .env.local` (PowerShell: `Copy-Item .env.local.dist .env.local`),
   then `docker compose up -d`. `.env.local` is gitignored and is passed to the container by Compose, so the
   file is the single switch — set `XDEBUG_MODE=off` there to disable again.
3. Run and Debug (Ctrl+Shift+D) → "Listen for Xdebug (Docker app container)" → Start (F5).
4. Set a breakpoint, e.g. on the `return` line of `src/Controller/HealthController.php`.
5. Trigger it: `curl "http://localhost:18080/health?XDEBUG_TRIGGER=1"` — the IDE stops on the line.
   For CLI / tests use `make test-debug` (`docker compose exec -T -e XDEBUG_TRIGGER=1 app vendor/bin/phpunit`).

Path mapping `/app -> ${workspaceFolder}` lives in `.vscode/launch.json` (committed); personal variants go in
`.vscode/*.local.json` (gitignored).

## Notes

- The only host port used is `18080`. If that is taken, change `HTTP_PORT` in `.env` and run
  `docker compose up -d` again.
