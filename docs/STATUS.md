# Project status (agent handoff)

Read this first at the start of every phase. Keep it under 20 lines. Plan lives in `.notes/PLAN.md` (gitignored).

## Done
- 0.1–0.7 tooling, rules, skills, quality gate, debugger, test DB, OpenAPI/Swagger UI.
- **Day 1 (1.1–1.4):** `Notification` aggregate + Doctrine attribute mapping + `POST`/`GET /notifications`.
- **2.1–2.4:** channel config, failover, SMTP, Twilio. SMS list is `twilio,fake_sms`.
- **3.1 Messenger:** `command.bus` + `delivery.bus`, `DeliverNotification` on `async`, `messenger_messages` migration.
- **3.2 Worker + Mailpit:** `worker` consumes `async`; Mailpit UI `:18025`; dev `MAILER_DSN=smtp://mailpit:1025`; app `RUN_MIGRATIONS=1`.
  Gate: phpunit OK (93 tests, 684 assertions), php-cs-fixer 0/122, phpstan OK, check-docs 88 classes.

## Next
- **3.3** end-to-end tests (success / failover / permanent / unknown / all-fail).

## Environment notes
- Windows host, no `make`: `docker compose exec -T app ...` (`docs/agent/COMMANDS.md`). Docker Desktop must be up. Agent never runs git write.
- `.env.local` is `XDEBUG_MODE=debug` only. Do not set `MAILER_DSN` there (a real env var beats `.env.test`'s `null://null`).
- `docker/entrypoint.sh` is copied into the image: rebuild after editing it (`docker compose up -d --build --wait`).
