# Backend Engineering Assignment

A minimal Symfony application that already runs in Docker, so you can spend your time on the
assignment rather than on environment setup.

Symfony 7.4 LTS · PHP 8.4 · MariaDB 11.4

## Prerequisites

[Docker](https://docs.docker.com/get-docker/) with Compose v2. Nothing else — PHP, Composer and
the database all run in containers.

## Start

```sh
make run
```

The first start builds the image and installs Composer dependencies into `vendor/`, so it takes a
few minutes. Later starts are quick. `make` on its own lists every available command.

## Verify it works

```sh
curl http://localhost:18080/health
# {"status":"ok"}
```

## Run the tests

```sh
make test
```

Tests run against a separate `app_test` database. It is created (and granted to the `app` user) by
`docker/mariadb/init.sql` the first time the database volume starts, and `make test` brings its schema up to
date before PHPUnit (`make test-db` on its own does just that). Every test runs inside a transaction that
`dama/doctrine-test-bundle` rolls back, so tests never leave data behind.

Without `make` (Windows): `docker compose exec -T app vendor/bin/phpunit`, see `docs/agent/COMMANDS.md`.

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
make                                         # list every make command
make build                                   # rebuild the images
make stop                                    # stop
make test ARGS="--filter SomeTest"           # run part of the suite

docker compose exec app bin/console          # Symfony console
docker compose exec app composer require ... # add a dependency
docker compose logs -f app                   # tail application logs
docker compose down -v                       # stop and wipe the database
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
