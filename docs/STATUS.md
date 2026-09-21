# Project status (agent handoff)

Read this first at the start of every phase. Keep it under 20 lines. Plan lives in `.notes/PLAN.md` (gitignored).

## Done
- 0.1–0.6 tooling, rules, skills, quality gate, debugger, test DB + docs skeleton.
- **0.7 API documentation:** NelmioApiDocBundle 5.12 + Twig/Asset; Swagger UI `/api/doc`; `/health` documented;
  `ProblemSchema` (RFC 7807 for 1.3); gates `OpenApiCoverageTest`, `OpenApiSnapshotTest`, `OpenApiAssertions`;
  rule `api-docs.mdc`; DECISIONS §1.9; TRACE R31 in progress. Flex ignored the Nelmio contrib recipe (registered
  by hand). JSON controller id is `nelmio_api_doc.controller.swagger`; document API is `toJson()` not `toArray()`.

## Next
- **1.1 Domain model** (`.notes/phases/1.1-domain-model.md`): entities, VOs, enums, exceptions, ports; pure unit
  tests; check-layers green; RECIPES R6, CODE_MAP rows, TRACE R01/R02. Every later HTTP endpoint is documented
  via `api-docs.mdc` in the same reply as the controller.

## Environment notes
- **No `.git` directory** — user must `git init` and make Day 0 + 0.7 commits; the agent never runs git write.
- Windows host, no `make`: `docker compose exec -T app ...` (`docs/agent/COMMANDS.md`). Dump OpenAPI from a
  container `sh -c` so the file is LF. Docker Desktop must be up.
- `.env.local` has `XDEBUG_MODE=debug` (gitignored). CRLF: if cs shows full-file diffs, it is line endings.
