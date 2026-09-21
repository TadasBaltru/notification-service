---
name: add-notification-provider
description: Step-by-step recipe for adding a new notification provider (e.g. AWS SES, Vonage) or a new channel (e.g. push) to the notification service without touching the domain or the failover logic. Use when the user asks to add, wire, or configure a provider or channel.
disable-model-invocation: true
---

# Add a provider or a channel

The domain (`Notification`, `Delivery`, `FailoverDeliveryStrategy`) never changes for a new provider. A provider is
an Infrastructure adapter of the `NotificationProvider` port plus one line of configuration.

## Add a provider to an existing channel

1. **Adapter** — `src/NotificationPublisher/Infrastructure/Provider/<Channel>/<Vendor><Channel>Provider.php`,
   `final readonly class`, implements `NotificationProvider`:
   - `name()` returns the config key (`'ses'`), `channel()` returns the enum case.
   - `send()` maps every failure into exactly one of: `TransientProviderFailure` (429, 5xx, connection refused),
     `PermanentProviderFailure(recipientLevel: true)` (invalid address/number), `PermanentProviderFailure(recipientLevel: false)`
     (auth/config), `UnknownProviderOutcome` (timeout after the request left, dropped connection).
   - Credentials via `#[Autowire('%env(VENDOR_API_KEY)%')]`; HTTP via injected `HttpClientInterface` with a timeout.
   - Where the vendor supports an idempotency key, pass the delivery id.
2. **Tag** — nothing to do if `_instanceof` tagging on the port is configured (`services.yaml`); the registry discovers it.
3. **Configuration** — add the name to the channel's provider list in `config/packages/notifications.yaml`
   (and the env default `NOTIFICATIONS_<CHANNEL>_PROVIDERS`). Add env vars to `.env` with empty/dummy defaults; real
   values only in `.env.local`.
4. **Tests** — `tests/Unit/.../<Vendor><Channel>ProviderTest.php` with `MockHttpClient`: success, recipient-level 4xx,
   auth 401/403, 429/5xx, transport timeout. Assert the outgoing request (URL, auth header, body) once.
5. **Docs** — CODE_MAP row, RECIPES fragment if the adapter shape is new, README provider table, DECISIONS one-liner
   for the classification choices, REQUIREMENTS_TRACE row "at least two providers per channel" if relevant.
6. **Verify** — `bin/console debug:container --tag=notification.provider` lists it; put a typo in the config and
   `cache:clear` must fail; run the unit test.

## Add a new channel (e.g. push)

1. Add the case to the `Channel` enum (Domain) and the recipient contact kind it needs (`Recipient` VO / resolver).
2. Add the channel block to `notifications.yaml` with `enabled`, `strategy`, `providers`.
3. Add at least two providers (one may be a `Fake<Channel>Provider` under `Provider/Fake/` with the standard modes).
4. Extend the request DTO `channels` choice list (it derives from the enum — usually no change).
5. Tests: enum expansion in `NotificationTest`, a fake-driven end-to-end test, provider unit tests.
6. Docs as above; REQUIREMENTS_TRACE row for "multiple channels".

## Fake provider modes (reuse, do not reinvent)
`FakeMode::Success | Transient | PermanentRecipient | PermanentProvider | Timeout`, selected by env
`FAKE_<CHANNEL>_MODE`; the fake records sent messages in memory for assertions.
