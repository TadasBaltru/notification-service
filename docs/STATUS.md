# Project status (agent handoff)

Read this first at the start of every phase. Keep it under 20 lines. Plan lives in `.notes/PLAN.md` (gitignored).

## Done
- 0.1–0.7 tooling, rules, skills, quality gate, debugger, test DB, OpenAPI/Swagger UI.
- **1.1 Domain model:** `Notification` aggregate + `Delivery`/`DeliveryAttempt`; VOs, enums, ports, domain
  exceptions under `src/NotificationPublisher/Domain/`. Unit tests `DeliveryTest`, `NotificationTest`,
  `ValueObjectTest`. Only Domain framework import is `Doctrine\Common\Collections`. TRACE R01/R02/R22 in progress.

## Next
- **1.2 Persistence** (`.notes/phases/1.2-persistence.md`): Doctrine XML mapping, custom DBAL types, repositories,
  initial migration for the three domain tables. `doctrine:schema:validate` + repository round-trip test.

## Environment notes
- Windows host, no `make`: `docker compose exec -T app ...` (`docs/agent/COMMANDS.md`). Docker Desktop must be up.
- `.env.local` has `XDEBUG_MODE=debug` (gitignored). CRLF: if cs shows full-file diffs, it is line endings.
- Agent never runs git write; commit when the user asks (`feat(domain): notification, delivery and attempt model with provider port`).
