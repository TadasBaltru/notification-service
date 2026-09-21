---
name: symfony-ddd-patterns
description: Proven Symfony 7.4 / Doctrine ORM 3 / Messenger code fragments for the DDD layering used in this project - aggregates, value objects, ports and adapters, XML entity mapping, tagged-service registries, Messenger messages and handlers, MapRequestPayload DTOs, JSON error responses, rate limiter usage. Use when writing or reviewing code under src/NotificationPublisher instead of reading vendor/.
disable-model-invocation: true
---

# Symfony DDD patterns for NotificationPublisher

Read the fragment you need from [reference.md](reference.md); do not read `vendor/` for these APIs.

## Layer cheat-sheet

| Concern | Layer | Pattern | Fragment |
|---|---|---|---|
| Notification, Delivery, DeliveryAttempt | Domain/Model | aggregate root + child entities, named state transitions | A1 |
| Recipient, IdempotencyKey, NotificationContent | Domain/Model | `final readonly` value objects, validate in constructor | A2 |
| Channel, DeliveryStatus, AttemptOutcome | Domain/Model | string-backed enums with small behaviour | A3 |
| NotificationProvider, RecipientResolver, repositories | Domain/Port | interfaces; adapters in Infrastructure | A4 |
| Provider failures | Domain/Exception | `TransientProviderFailure`, `PermanentProviderFailure`, `UnknownProviderOutcome` | A5 |
| SendNotification / DeliverNotification | Application | `final readonly` message + `#[AsMessageHandler]` handler | B1, B2 |
| Failover policy | Application | strategy over an ordered provider list, records attempts | B3 |
| Doctrine persistence | Infrastructure/Persistence | XML mapping, embeddables, enum fields, uuid ids, repository adapter | C1, C2 |
| Provider discovery | Infrastructure | `#[AutoconfigureTag]` on the port + `#[AutowireIterator(defaultIndexMethod: 'name')]` | C3 |
| Channel/provider config | Application + config | parameters -> validated `ChannelConfiguration` VO at boot | C4 |
| Throttle | Infrastructure | `RateLimiterFactory` sliding window with DBAL cache pool | C5 |
| HTTP in | UserInterface/Http | `#[MapRequestPayload]` DTO with constraints, 202/200/404/422 JSON | D1, D2 |

## Rules of thumb
- Domain has zero framework imports; ids are `Uuid` **strings** inside value objects (`NotificationId::fromString`),
  conversion to `Symfony\Component\Uid\Uuid` happens in Infrastructure types.
- Every state change is a method on the aggregate that checks the current state and throws a domain exception
  otherwise; handlers never set fields.
- Messages carry ids only. Handlers are idempotent (`if ($delivery->isFinal()) return;`).
- One XML file per entity, named `<ClassName>.orm.xml`, under `Infrastructure/Persistence/Doctrine/Mapping/`.
- Exceptions crossing the Messenger boundary are mapped exactly once, in the handler (see messenger-reliability rule).
- When a fragment is copied into the project and adjusted, also copy the *final* version into `docs/agent/RECIPES.md`
  so future phases use real code, not this template.
