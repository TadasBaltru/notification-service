# Project status (agent handoff)

Read this first at the start of every phase. Keep it under 20 lines. Plan lives in `.notes/PLAN.md` (gitignored).

## Done
- 0.1–0.7 tooling, rules, skills, quality gate, debugger, test DB, OpenAPI/Swagger UI.
- **Day 1 (1.1–1.4):** `Notification` aggregate + Doctrine attribute mapping + `POST`/`GET /notifications` (idempotency,
  `InMemoryRecipientResolver`, RFC 7807 errors). Gate green: phpunit OK (37 tests, 409 assertions),
  php-cs-fixer 0/86, phpstan OK, check-docs 67 classes, check-layers OK. TRACE R22 done.

## Next
- **2.1 Channel configuration + provider registry** (`.notes/phases/2.1-channel-config.md`): fakes,
  tagged registry, compiler-pass validation.

## Environment notes
- Windows host, no `make`: `docker compose exec -T app ...` (`docs/agent/COMMANDS.md`). Docker Desktop must be up.
- `.env.local` has `XDEBUG_MODE=debug` (gitignored). CRLF: if cs shows full-file diffs, it is line endings.
- Agent never runs git write; commit when the user asks.
