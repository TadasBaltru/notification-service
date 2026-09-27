# Project status (agent handoff)

Read this first at the start of every phase. Keep it under 20 lines. Plan lives in `.notes/PLAN.md` (gitignored).

## Done
- 0.1–0.7 tooling, rules, skills, quality gate, debugger, test DB, OpenAPI/Swagger UI.
- **Day 1 (1.1–1.4):** `Notification` aggregate + Doctrine attribute mapping + `POST`/`GET /notifications`.
- **2.1–2.4:** channel config, failover, SMTP, Twilio. SMS list is `twilio,fake_sms`.
- **3.1–3.4:** `command.bus` + `delivery.bus`, worker, Mailpit, `DeliveryPipeline`, `DeliveryRequiresRetry` so `max_retries` applies.
- **4.1–4.5:** throttle, tracking, PHPStan 8, README/DECISIONS/AI_NOTES. `git log --oneline` is 13 commits (not one blob). `.env.local` is gitignored; Twilio defaults are `ACtest` / `test-token`.
- **5.1:** cold start verified (`down -v`, fresh volume, `app` + `app_test`, PHPUnit `OK (111 tests, 2565 assertions)`). Interview sheet is `docs/INTERVIEW_NOTES.md`. Push and cooldown were not started. Assignment complete. Docs working tree is still uncommitted.

## Next
- Nothing in the plan. Commit the docs when you want them in the history (`docs: interview notes and cold-start verification`).

## Environment notes
- AI stack: Cursor; Fable 5.1 planned, Grok 4.7 implemented (`docs/AI_NOTES.md` opening).
- Windows host, no `make`: `docker compose exec -T app ...` (`docs/agent/COMMANDS.md`). Docker Desktop must be up. Agent never runs git write.
- `.env.local` is `XDEBUG_MODE=debug` only. Do not set `MAILER_DSN` there (a real env var beats `.env.test`'s `null://null`).
- `docker/entrypoint.sh` is copied into the image: rebuild after editing it (`docker compose up -d --build --wait`).
- Throttle defaults: `NOTIFICATIONS_THROTTLE_LIMIT=300`, `NOTIFICATIONS_THROTTLE_INTERVAL=1 hour`. `.env.test` sets the limit to 3. Migration `Version20260924132829` replaces the `user_id` index.
