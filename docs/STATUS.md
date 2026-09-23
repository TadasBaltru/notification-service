# Project status (agent handoff)

Read this first at the start of every phase. Keep it under 20 lines. Plan lives in `.notes/PLAN.md` (gitignored).

## Done
- 0.1–0.7 tooling, rules, skills, quality gate, debugger, test DB, OpenAPI/Swagger UI.
- **Day 1 (1.1–1.4):** `Notification` aggregate + Doctrine attribute mapping + `POST`/`GET /notifications`.
- **2.1–2.4:** channel config, failover, SMTP, Twilio. SMS list is `twilio,fake_sms`.
- **3.1 Messenger:** `command.bus` + `delivery.bus`, `DeliverNotification` on `async`, `messenger_messages` migration.
  Gate: phpunit OK (93 tests, 684 assertions), php-cs-fixer 0/122, phpstan OK, check-docs 88 classes.

## Next
- **3.2 Worker + Mailpit** containers.

## Environment notes
- Windows host, no `make`: `docker compose exec -T app ...` (`docs/agent/COMMANDS.md`). Docker Desktop must be up. Agent never runs git write.
- `.env.local` has `XDEBUG_MODE=debug` (gitignored). `MAILER_DSN=null://null` and `MAILER_FROM=noreply@notifications.local` until Mailpit (3.2).
