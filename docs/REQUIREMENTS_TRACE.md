# Requirements trace

Every requirement from the assignment text as one row: where it is implemented, which test or command proves it,
and its status. The plan is not done until every row is `done`. Checked at the end of every phase;
`tools/check-docs.php` verifies that non-pending rows name real classes and tests.

Status values: `pending` (not started), `in progress` (phase running), `done` (implemented + proof green),
`out of scope` (documented in `docs/DECISIONS.md` §5).

| ID | Requirement (task wording) | Implemented in (planned) | Proof (test / command) | Phase | Status |
|---|---|---|---|---|---|
| R01 | Send notifications through multiple channels (at least SMS and Email) | `Channel` enum; `Notification` expands one `Delivery` per requested channel | `NotificationTest`, `NotificationApiTest` | 1.1, 1.3 | pending |
| R02 | Channel / provider abstraction so new providers can be added | `NotificationProvider` port (Domain); adapters in Infrastructure | `ProviderRegistryTest`; `add-notification-provider` skill | 1.1, 2.1 | pending |
| R03 | At least two providers per channel | SMS: `TwilioSmsProvider`, `FakeSmsProvider`; Email: `SmtpMailerProvider`, `FakeEmailProvider` | `debug:container --tag=notification.provider` lists four | 2.1, 2.3, 2.4 | pending |
| R04 | Failover: if one provider fails, use another | `FailoverDeliveryStrategy` | `FailoverDeliveryStrategyTest::test_it_fails_over_to_next_provider_on_transient_failure` | 2.2 | pending |
| R05 | Define how multiple providers are used (order / policy) | `ProviderOrdering` (`priority`, `round_robin`) chosen per channel in config | `ProviderOrderingTest` | 2.1 | pending |
| R06 | Notifications must not get lost; retry later when all providers fail | Messenger retry strategy + `failed` transport on `delivery.bus` (no transaction middleware, DECISIONS §3.6); `make retry-failed` | `DeliveryPipelineTest` all-fail scenario; manual `make failed` / `make retry-failed` | 3.1, 3.3, 3.4 | pending |
| R07 | Provider times out but may have accepted the message | `UnknownProviderOutcome` -> attempt `unknown`, no same-run failover, retry later | `FailoverDeliveryStrategyTest::test_it_does_not_fail_over_when_outcome_is_unknown` | 2.2 | pending |
| R08 | Document transient vs permanent failure handling | `docs/DECISIONS.md` §3.1-3.2 | `TwilioSmsProviderTest` classification cases | 2.2, 2.4 | pending |
| R09 | Document retries | `docs/DECISIONS.md` §3.1; `config/packages/messenger.yaml` | `debug:messenger`; `DeliveryPipelineTest` | 3.1 | pending |
| R10 | Document failover | `docs/DECISIONS.md` §3.1-3.2; `FailoverDeliveryStrategy` | `FailoverDeliveryStrategyTest` | 2.2 | pending |
| R11 | Document duplicate requests | Unique `idempotency_key`; replay returns existing (200) | `NotificationApiTest::test_it_returns_the_existing_notification_for_a_replayed_idempotency_key` | 1.3 | pending |
| R12 | Document duplicated delivery (at-least-once) | Handler no-op when delivery `sent`; deterministic SMTP `Message-ID` | `DeliverNotificationHandlerTest`; `SmtpMailerProviderTest` | 2.3, 3.1 | pending |
| R13 | Document unknown result handling | `docs/DECISIONS.md` §3.3 | see R07 | 2.2 | pending |
| R14 | Configuration: enable / disable channels | `notifications.channels.*.enabled`; disabled -> delivery `skipped` in `SendNotificationHandler` (2.1), nothing queued | `ChannelConfigurationTest`; `DeliveryPipelineTest` skipped scenario | 2.1, 3.3 | pending |
| R15 | Configuration: multiple providers per channel | `notifications.channels.*.providers` list validated at container build by a compiler pass | `ChannelConfigurationTest::test_it_rejects_unknown_provider_name`; `cache:clear` with a typo fails | 2.1 | pending |
| R16 | Change configuration without code changes | Env overrides `NOTIFICATIONS_*`, `FAKE_*_MODE`, `MAILER_DSN`, `TWILIO_*` | manual: change `.env.local`, `cache:clear`, `debug:container --parameters` | 2.1 | pending |
| R17 | Send one notification through several channels at once | `channels: [...]` in request -> one delivery per channel | `NotificationApiTest`, `DeliveryPipelineTest` | 1.3, 3.3 | pending |
| R18 | Optional: throttle to 300 notifications per user per hour for notifications requiring a user response | `RateLimiterDeliveryThrottle` (sliding window, DBAL cache), `requiresUserAction` flag, `DelayStamp` redelivery | `RateLimiterDeliveryThrottleTest` (`MockClock`); integration 301st request `throttled` | 4.1 | pending |
| R19 | Optional: track what was sent, when, through which channel / provider, to which user | Tables `notifications`, `notification_deliveries`, `notification_delivery_attempts` (notification status derived from deliveries, no column); `GET /notifications/{id}`; `GET /users/{userId}/notifications` | `NotificationStatusTest`; manual curl | 1.3, 4.2 | pending |
| R20 | Requests carry a user identifier | `userId` in `SendNotificationRequest`; `user_id` column indexed | `NotificationApiTest` | 1.3 | pending |
| R21 | Use existing solutions where sensible, evaluate fit, document choices | `docs/DECISIONS.md` §1 (Messenger, Mailer, HttpClient, RateLimiter, dama; Notifier rejected) | review | 0.6, 4.4 | pending (draft exists since 0.6) |
| R22 | DDD with sensible boundaries | `src/NotificationPublisher/{Domain,Application,Infrastructure,UserInterface}`; ports in Domain; XML mapping | `tools/check-layers.php` green | 1.1-1.3 | pending |
| R23 | Tests covering the important behaviour | Unit (domain, strategy, Twilio classification), integration (API, repositories, end-to-end) | `vendor/bin/phpunit` green | 1.1-3.3 | pending |
| R24 | Extend the Makefile; use Docker; runs out of the box | Targets `test-db`, `worker-logs`, `failed`, `retry-failed`, `send`; services `worker`, `mailpit`; auto-migrations | cold start `docker compose down -v && make test` | 0.6, 3.2, 5 | pending (test-db done in 0.6) |
| R25 | README: how to start, run tests, exercise the service, assumptions | `README.md` | follow README top to bottom in a fresh terminal | 4.4 | pending |
| R26 | State what was left out of scope and why | `docs/DECISIONS.md` §5 | review | 4.4 | pending |
| R27 | AI note: tools used, for what, accepted / rejected, verified / corrected | `docs/AI_NOTES.md` per phase | review | every phase | pending (entries exist since 0.1) |
| R28 | Meaningful git history showing the approach | One conventional commit per phase | `git log --oneline` reads as a story | every phase | pending |
| R29 | Real provider where practical, no accounts required, no secrets committed | `SmtpMailerProvider` via `MAILER_DSN` (Mailpit locally); `TwilioSmsProvider` activatable via `TWILIO_*` in gitignored `.env.local` | `make send` shows mail in Mailpit; `TwilioSmsProviderTest` with `MockHttpClient` | 2.3, 2.4, 3.2 | pending |
| R30 | Extensibility: adding another channel (e.g. push) | `add-notification-provider` skill; README "Extending" section | dry-run the skill steps | 4.4 | pending |
