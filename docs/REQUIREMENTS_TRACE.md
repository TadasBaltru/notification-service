# Requirements trace

Every requirement from the assignment text as one row: where it is implemented, which test or command proves it,
and its status. The plan is not done until every row is `done`. Checked at the end of every phase;
`tools/check-docs.php` verifies that non-pending rows name real classes and tests.

Status values: `pending` (not started), `in progress` (phase running), `done` (implemented + proof green),
`out of scope` (documented in `docs/DECISIONS.md` §5).

| ID | Requirement (task wording) | Implemented in (planned) | Proof (test / command) | Phase | Status |
|---|---|---|---|---|---|
| R01 | Send notifications through multiple channels (at least SMS and Email) | `Channel` enum; `Notification` expands one `Delivery` per requested channel; `POST /notifications` accepts `channels` | `NotificationTest`; `NotificationApiTest` | 1.1, 1.3 | in progress |
| R02 | Channel / provider abstraction so new providers can be added | `NotificationProvider` port (Domain); `ProviderRegistry` plus adapters in Infrastructure | `ProviderRegistryTest`; `ProviderRegistryContainerTest` | 1.1, 2.1 | in progress |
| R03 | At least two providers per channel | SMS: `TwilioSmsProvider`, `FakeSmsProvider`; Email: `SmtpMailerProvider`, `FakeEmailProvider` | `debug:container --tag=notification.provider` lists smtp, fake_email, fake_sms, twilio; `ProviderRegistryContainerTest` | 2.1, 2.3, 2.4 | done |
| R04 | Failover: if one provider fails, use another | `FailoverDeliveryStrategy` | `FailoverDeliveryStrategyTest::test_it_fails_over_to_next_provider_on_transient_failure` | 2.2 | done |
| R05 | Define how multiple providers are used (order / policy) | `ProviderOrdering` (`priority`, `round_robin`) chosen per channel in config | `ProviderOrderingTest` | 2.1 | in progress |
| R06 | Notifications must not get lost; retry later when all providers fail | `DeliverNotificationHandler` maps `retryLater` to `RecoverableMessageHandlingException`; `config/packages/messenger.yaml` retry + `failed` transport; delivery stays `pending` on exhaustion; `make retry-failed` | `NotificationApiTest`; `DeliverNotificationHandlerTest`; manual `make failed` / `make retry-failed` | 3.1, 3.3, 3.4 | in progress |
| R07 | Provider times out but may have accepted the message | `UnknownProviderOutcome` -> attempt `unknown`, no same-run failover, retry later | `FailoverDeliveryStrategyTest::test_it_does_not_fail_over_when_outcome_is_unknown` | 2.2 | done |
| R08 | Document transient vs permanent failure handling | `docs/DECISIONS.md` §3.1-3.2; `FailoverDeliveryStrategy`; `TwilioFailureClassifier` | `FailoverDeliveryStrategyTest`; `TwilioSmsProviderTest` | 2.2, 2.4 | done |
| R09 | Document retries | `docs/DECISIONS.md` §3.1; `config/packages/messenger.yaml` | `debug:messenger`; `debug:config framework messenger`; `DeliverNotificationHandlerTest` | 3.1 | in progress |
| R10 | Document failover | `docs/DECISIONS.md` §3.1-3.2; `FailoverDeliveryStrategy` | `FailoverDeliveryStrategyTest` | 2.2 | done |
| R11 | Document duplicate requests | Unique `idempotency_key` (DB); replay returns existing (200) | `DoctrineNotificationRepositoryTest`; `NotificationApiTest::test_it_replays_the_same_idempotency_key_with_200` | 1.2, 1.3 | done |
| R12 | Document duplicated delivery (at-least-once) | Deterministic SMTP `Message-ID` on `SmtpMailerProvider`; `DeliverNotificationHandler` returns when the delivery is final | `SmtpMailerProviderTest`; `DeliverNotificationHandlerTest` | 2.3, 3.1 | in progress |
| R13 | Document unknown result handling | `docs/DECISIONS.md` §3.3 | see R07 | 2.2 | done |
| R14 | Configuration: enable / disable channels | `notifications.channels.*.enabled`; disabled -> delivery `skipped` in `SendNotificationHandler` and not dispatched | `ChannelConfigurationTest`; `SendNotificationHandlerTest`; `NotificationApiTest` | 2.1, 3.1 | in progress |
| R15 | Configuration: multiple providers per channel | `notifications.channels.*.providers` list validated at container build by `ValidateChannelConfigurationPass` | `ChannelConfigurationTest::test_it_rejects_unknown_provider_name`; `cache:clear` with a typo fails | 2.1 | in progress |
| R16 | Change configuration without code changes | Env overrides `NOTIFICATIONS_*`, `FAKE_*_MODE`, `MAILER_DSN`, `TWILIO_*` | manual: change `.env.local`, `cache:clear`, `debug:container --parameters` | 2.1 | in progress |
| R17 | Send one notification through several channels at once | `channels: [...]` in request -> one delivery per channel | `NotificationApiTest` | 1.3, 3.3 | in progress |
| R18 | Optional: throttle to 300 notifications per user per hour for notifications requiring a user response | `RateLimiterDeliveryThrottle` (sliding window, DBAL cache), `requiresUserAction` flag, `DelayStamp` redelivery | `RateLimiterDeliveryThrottleTest` (`MockClock`); integration 301st request `throttled` | 4.1 | pending |
| R19 | Optional: track what was sent, when, through which channel / provider, to which user | Tables `notifications`, `notification_deliveries`, `notification_delivery_attempts` (no notification `status` column); `GET /notifications/{id}` | `DoctrineNotificationRepositoryTest`; `NotificationApiTest` | 1.2, 1.3, 4.2 | in progress |
| R20 | Requests carry a user identifier | `userId` in `SendNotificationRequest`; `user_id` column indexed | `NotificationApiTest` | 1.3 | done |
| R21 | Use existing solutions where sensible, evaluate fit, document choices | `docs/DECISIONS.md` §1 (Messenger, Mailer, HttpClient, RateLimiter, dama; Notifier rejected) | review | 0.6, 4.4 | pending (draft exists since 0.6) |
| R22 | DDD with sensible boundaries | `src/NotificationPublisher/{Domain,Application,Infrastructure,UserInterface}`; ports in Domain; ORM attributes on aggregates; repositories in Infrastructure | `tools/check-layers.php` green; `DoctrineNotificationRepositoryTest` | 1.1-1.3 | done |
| R23 | Tests covering the important behaviour | Unit (domain, identity stub, exception unwrap), integration (API, repositories) | `vendor/bin/phpunit` green (`NotificationApiTest`, `DoctrineNotificationRepositoryTest`) | 1.1-3.3 | in progress |
| R24 | Extend the Makefile; use Docker; runs out of the box | Targets `test-db`, `worker-logs`, `failed`, `retry-failed`, `send`, `send-failover`; services `worker`, `mailpit`; app `RUN_MIGRATIONS=1` | cold start `docker compose down -v && make test` | 0.6, 3.2, 5 | in progress (targets and services in 3.2; cold start is day 5) |
| R25 | README: how to start, run tests, exercise the service, assumptions | `README.md` | follow README top to bottom in a fresh terminal | 4.4 | pending |
| R26 | State what was left out of scope and why | `docs/DECISIONS.md` §5 | review | 4.4 | pending |
| R27 | AI note: tools used, for what, accepted / rejected, verified / corrected | `docs/AI_NOTES.md` per phase | review | every phase | pending (entries exist since 0.1) |
| R28 | Meaningful git history showing the approach | One conventional commit per phase | `git log --oneline` reads as a story | every phase | pending |
| R29 | Real provider where practical, no accounts required, no secrets committed | `SmtpMailerProvider` via `MAILER_DSN=smtp://mailpit:1025` (Mailpit, no account); `.env.test` keeps `null://null`; `TwilioSmsProvider` via `TWILIO_*` (harmless defaults in `.env`, real values only in `.env.local`) | `SmtpMailerProviderTest`; `TwilioSmsProviderTest`; Mailpit UI after `make send` | 2.3, 2.4, 3.2 | in progress |
| R30 | Extensibility: adding another channel (e.g. push) | `add-notification-provider` skill; README "Extending" section | dry-run the skill steps | 4.4 | pending |
| R31 | Evaluators can discover and exercise every endpoint and see every response | Swagger UI `/api/doc`; generated `docs/openapi.json`; `ProblemSchema`; `#[OA\...]` on controllers | `OpenApiCoverageTest`, `OpenApiSnapshotTest`, `NotificationApiTest` (`assertResponseIsDocumented`) | 0.7, 1.3, 4.2 | in progress |
