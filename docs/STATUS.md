# Project status (agent handoff)

Read this first at the start of every phase. Keep it under 20 lines. Plan lives in `.notes/PLAN.md` (gitignored).

## Done — Day 0 complete, plan reviewed
- 0.1–0.6 tooling, rules, skills, quality gate, debugger, test DB + docs skeleton (details in `docs/AI_NOTES.md`).
  Gate green at the end of Day 0: phpunit 1 test, cs 0/8, phpstan OK, check-docs OK, check-layers OK.
- Plan review before 1.1 (AI_NOTES "Plan review"): `Doctrine\Common\Collections` allowed in Domain; XML mapping
  *replaces* the `App` attribute mapping; no `notifications.status`; fakes in 2.1; compiler-pass config validation;
  two buses (`command.bus` + `doctrine_transaction`, `delivery.bus` without); tests `in-memory://` not `sync://`;
  migrations per phase (1.2 domain, 3.1 `messenger_messages`, 4.1 cache). DECISIONS §1.5/2.3/3.3/3.6/4, briefs, RECIPES, skills, rules aligned.

## Next
- 1.1 Domain model: entities `Notification`, `Delivery`, `DeliveryAttempt` (children as `Collection`); VOs `UserId`,
  `IdempotencyKey`, `Recipient`, `NotificationContent`; enums `Channel`, `DeliveryStatus`, `AttemptOutcome`; exceptions;
  ports. Pure unit tests (`tests/Unit/`), check-layers green. Replace RECIPES R6, add CODE_MAP rows, TRACE R01/R02.
  Copy-paste prompt: `.notes/phases/1.1-domain-model.md`.

## Environment notes
- **No `.git` directory exists** (`git status` -> "not a git repository"). PLAN 0.1 assumed `git init` on Day 0 and
  TRACE R28 grades the history. The user must `git init` and make the Day 0 commit(s) before 1.1; the agent never runs
  git write commands.
- Windows host, no `make`: use `docker compose exec -T app ...` (see `docs/agent/COMMANDS.md`). Docker Desktop must be up.
- `.env.local` currently exists locally with `XDEBUG_MODE=debug` (gitignored); set `off` + `docker compose up -d` to disable.
- CRLF drift: three PHP files had been saved with CRLF (cs check flags a whole-file diff); `php-cs-fixer fix` normalised
  them. `.editorconfig`/`.gitattributes` already say `lf` — if cs shows full-file diffs again, it is line endings.
- Planning source of truth is `.notes/PLAN.md`; per-phase briefs to paste into a new chat live in `.notes/phases/`.
