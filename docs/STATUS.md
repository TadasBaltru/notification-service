# Project status (agent handoff)

Read this first at the start of every phase. Keep it under 20 lines. Plan lives in `.notes/PLAN.md` (gitignored).

## Done
- 0.1–0.7 tooling, rules, skills, quality gate, debugger, test DB, OpenAPI/Swagger UI.
- **Day 1 (1.1–1.4):** `Notification` aggregate + Doctrine attribute mapping + `POST`/`GET /notifications`.
- **2.1–2.4:** channel config, failover, SMTP, Twilio. SMS list is `twilio,fake_sms`.
- **3.1–3.4:** `command.bus` + `delivery.bus`, worker, Mailpit, `DeliveryPipeline`, `DeliveryRequiresRetry` so `max_retries` applies.
- **4.1:** per-user sliding window on `cache_items`; only `requiresUserAction`; `DelayStamp` redelivery (does not consume a failure retry).

## Next
- **4.2** tracking endpoint polish.

## Environment notes
- Windows host, no `make`: `docker compose exec -T app ...` (`docs/agent/COMMANDS.md`). Docker Desktop must be up. Agent never runs git write.
- `.env.local` is `XDEBUG_MODE=debug` only. Do not set `MAILER_DSN` there (a real env var beats `.env.test`'s `null://null`).
- `docker/entrypoint.sh` is copied into the image: rebuild after editing it (`docker compose up -d --build --wait`).
- Throttle defaults: `NOTIFICATIONS_THROTTLE_LIMIT=300`, `NOTIFICATIONS_THROTTLE_INTERVAL=1 hour`. `.env.test` sets the limit to 3.
