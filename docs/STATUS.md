# Project status (agent handoff)

Read this first at the start of every phase. Keep it under 20 lines. Plan lives in `.notes/PLAN.md` (gitignored).

## Done
- 0.1–0.7 tooling, rules, skills, quality gate, debugger, test DB, OpenAPI/Swagger UI.
- **Day 1 (1.1–1.4):** `Notification` aggregate + Doctrine attribute mapping + `POST`/`GET /notifications`.
- **2.1 Channel configuration:** `fake_sms` / `fake_email`, tagged `ProviderRegistry`, `priority` / `round_robin`,
  compiler-pass validation. Disabled channel is stored `skipped`.
- **2.2 Failover:** `FailoverDeliveryStrategy` returns `DeliveryResult`. Gate: phpunit OK (70 tests, 522 assertions),
  php-cs-fixer 0/109, phpstan OK, check-docs 82 classes, check-layers OK. TRACE R04, R07, R08, R10, R13 done.

## Next
- **2.3 SMTP provider** (`.notes/phases/2.3-smtp-provider.md`).

## Environment notes
- Windows host, no `make`: `docker compose exec -T app ...` (`docs/agent/COMMANDS.md`). Docker Desktop must be up.
- `.env.local` has `XDEBUG_MODE=debug` (gitignored). CRLF: if cs shows full-file diffs, it is line endings.
- Agent never runs git write; commit when the user asks.
