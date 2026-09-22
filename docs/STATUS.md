# Project status (agent handoff)

Read this first at the start of every phase. Keep it under 20 lines. Plan lives in `.notes/PLAN.md` (gitignored).

## Done
- 0.1–0.7 tooling, rules, skills, quality gate, debugger, test DB, OpenAPI/Swagger UI.
- **Day 1 (1.1–1.4):** `Notification` aggregate + Doctrine attribute mapping + `POST`/`GET /notifications`.
- **2.1 Channel configuration:** fakes, tagged `ProviderRegistry`, `priority` / `round_robin`, compiler-pass validation. Disabled channel is stored `skipped`.
- **2.2 Failover:** `FailoverDeliveryStrategy` returns `DeliveryResult`.
- **2.3 SMTP:** `SmtpMailerProvider` (`TransportInterface`, deterministic `Message-ID`) + `SmtpFailureClassifier`.
- **2.4 Twilio:** `TwilioSmsProvider` + `TwilioFailureClassifier`. SMS list is `twilio,fake_sms`.
  Gate: phpunit OK (88 tests, 592 assertions), php-cs-fixer 0/117, phpstan OK, check-docs 86 classes. R03 done (four tagged providers). R12 and R29 still in progress.

## Next
- **3.1 Messenger wiring** (`.notes/phases/3.1-messenger.md`).

## Environment notes
- Windows host, no `make`: `docker compose exec -T app ...` (`docs/agent/COMMANDS.md`). Docker Desktop must be up. Agent never runs git write.
- `.env.local` has `XDEBUG_MODE=debug` (gitignored). `MAILER_DSN=null://null` and `MAILER_FROM=noreply@notifications.local` until Mailpit (3.2).
