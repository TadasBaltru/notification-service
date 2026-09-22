# Project status (agent handoff)

Read this first at the start of every phase. Keep it under 20 lines. Plan lives in `.notes/PLAN.md` (gitignored).

## Done
- 0.1–0.7 tooling, rules, skills, quality gate, debugger, test DB, OpenAPI/Swagger UI.
- **Day 1 (1.1–1.4):** `Notification` aggregate + Doctrine attribute mapping + `POST`/`GET /notifications`.
- **2.1 Channel configuration:** `fake_sms` / `fake_email`, tagged `ProviderRegistry`, `priority` / `round_robin`,
  compiler-pass validation. Disabled channel is stored `skipped`. Gate: phpunit OK (57 tests, 465 assertions),
  php-cs-fixer 0/105, phpstan OK, check-docs 79 classes, check-layers OK. TRACE R03, R05, R14–R16 in progress.

## Next
- **2.2 FailoverDeliveryStrategy** (`.notes/phases/2.2-failover-strategy.md`): test-first, using the fakes from 2.1.

## Environment notes
- Windows host, no `make`: `docker compose exec -T app ...` (`docs/agent/COMMANDS.md`). Docker Desktop must be up.
- `.env.local` has `XDEBUG_MODE=debug` (gitignored). CRLF: if cs shows full-file diffs, it is line endings.
- Agent never runs git write; commit when the user asks.
