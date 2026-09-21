---
name: phase-runner
description: Runs one phase of the notification-service plan end to end with the project's teaching protocol - reads the handoff, explains what/why/how-to-test before coding, tests after every change, and updates STATUS, AI_NOTES, REQUIREMENTS_TRACE, CODE_MAP and the learning log. Use whenever the user says "phase X.Y", "start phase", "next phase", "continue the plan" or asks to implement a plan step.
---

# Phase runner

## Start
1. Read `docs/STATUS.md`, then the matching file in `.notes/phases/` (one phase per chat, copy-paste ready). If that
   file is missing, fall back to the phase entry in `.notes/PLAN.md` (section 4) and its verification line (section 4b).
2. Read only the `docs/agent/CODE_MAP.md` rows for classes in scope and the one `docs/agent/RECIPES.md` fragment needed.
3. Check the stack: `docker ps` (start Docker Desktop if needed), `curl http://localhost:18080/health`.
4. Post the **before-code block** (<= 15 lines), template:

```
Phase X.Y — before code:
**What:** <one sentence>
**DDD concept:** <term from the glossary> — lives in <layer> because <reason>
**Test kind:** <unit | integration | manual> using <fake | MockHttpClient | real DB> because <reason>
**Done when:** <verification line from plan 4b>
```

## During
- Implement in small steps. After every change run the narrowest test and paste the output:
  `docker compose exec -T app vendor/bin/phpunit --filter <Name>`.
- New class -> new CODE_MAP row in the same reply. Changed command -> COMMANDS.md.
- Never run git write commands. Never widen scope; park ideas for the end.

## Finish
1. Full gate inside the container: `vendor/bin/phpunit`, `vendor/bin/php-cs-fixer check --diff`, `vendor/bin/phpstan`,
   `php tools/check-docs.php` (skip tools that do not exist yet on Day 0 and say so).
2. Update `docs/STATUS.md` (move phase to Done, set Next, env notes), `docs/AI_NOTES.md` (proposed / accepted /
   rejected / verified, 2-4 bullets), `docs/REQUIREMENTS_TRACE.md` rows touched, `.notes/learning.md` entry.
3. Post the **walkthrough block**, template:

```
Phase X.Y complete.
**Changed:** <files, one line each>
**Verify:** <exact command> -> <exact expected output>
**Break it on purpose:** <one edit that makes a test fail, then revert>
**Interview sentence:** "<one sentence a candidate can say>"
**Proposed commit:** <type(scope): message>   (not executed)
**Next:** phase X.Z — <one line>
```

## Learning-log entry template (`.notes/learning.md`)
```
## X.Y <title>
**Concept.** <DDD/testing concept in 2-3 sentences, tied to this codebase>
**What we did.** <2-4 sentences>
**How to test it.** <commands + expected output; one break-it-on-purpose>
**Interview sentence.** "<...>"
**Vocabulary.** <term: definition; ...>
```

## AI_NOTES entry template (`docs/AI_NOTES.md`)
```
## Phase X.Y — <title>
- Proposed and accepted: ...
- Rejected: ... (reason)
- Verified / corrected: ... (what was checked against source or by running it)
```
