# Reference fragments (Symfony 7.4, Doctrine ORM 3, PHP 8.4)

Namespaces below assume `App\NotificationPublisher\...`. Adjust names to the real classes; keep the shape.

## A1 — Aggregate root with child collection and named transitions
```php
final class Notification
{
    /** @var list<Delivery> */
    private array $deliveries = [];

    private function __construct(
        private readonly NotificationId $id,
        private readonly UserId $userId,
        private readonly IdempotencyKey $idempotencyKey,
        private readonly NotificationContent $content,
        private readonly bool $requiresUserAction,
        private readonly \DateTimeImmutable $createdAt,
    ) {}

    public static function request(NotificationId $id, UserId $userId, IdempotencyKey $key,
        NotificationContent $content, bool $requiresUserAction, \DateTimeImmutable $now): self
    {
        return new self($id, $userId, $key, $content, $requiresUserAction, $now);
    }

    public function addDelivery(DeliveryId $id, Channel $channel, Recipient $recipient, \DateTimeImmutable $now): Delivery
    {
        foreach ($this->deliveries as $existing) {
            if ($existing->channel() === $channel) {
                throw ChannelAlreadyRequested::for($this->id, $channel);
            }
        }
        return $this->deliveries[] = Delivery::pending($id, $this, $channel, $recipient, $now);
    }

    /** @return list<Delivery> */
    public function deliveries(): array { return $this->deliveries; }
}
```
Doctrine needs a collection type for one-to-many: store `Collection<int, Delivery>` (`ArrayCollection`); the ORM
hydrates a `PersistentCollection` behind the same interface, an `array` property would raise a TypeError.
`Doctrine\Common\Collections` is the one allowed non-PHP import in Domain (whitelisted in `check-layers`,
DECISIONS §1.5) — decided, because the "one channel per notification" invariant must live in the aggregate.

## A2 — Value object
```php
final readonly class IdempotencyKey
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 128) {
            throw InvalidIdempotencyKey::because('must be 1-128 characters');
        }
        return new self($value);
    }

    public function equals(self $other): bool { return $this->value === $other->value; }
}
```

## A3 — Enum with behaviour
```php
enum AttemptOutcome: string
{
    case InProgress = 'in_progress';
    case Succeeded = 'succeeded';
    case TransientFailure = 'transient_failure';
    case PermanentFailure = 'permanent_failure';
    case Unknown = 'unknown';

    public function allowsFailover(): bool
    {
        return $this === self::TransientFailure;
    }
}
```

## A4 — Port + result object
```php
interface NotificationProvider
{
    public function name(): string;          // matches config key, e.g. 'twilio'
    public function channel(): Channel;

    /** @throws TransientProviderFailure|PermanentProviderFailure|UnknownProviderOutcome */
    public function send(OutboundMessage $message): ProviderReceipt;
}

final readonly class ProviderReceipt
{
    public function __construct(public string $providerMessageId) {}
}
```

## A5 — Failure exceptions (Domain)
```php
abstract class ProviderFailure extends DomainException
{
    public function __construct(public readonly string $provider, public readonly ?string $code, string $message,
        ?\Throwable $previous = null) { parent::__construct($message, 0, $previous); }
}
final class TransientProviderFailure extends ProviderFailure {}
final class PermanentProviderFailure extends ProviderFailure
{
    public function __construct(string $provider, ?string $code, string $message, public readonly bool $recipientLevel, ?\Throwable $previous = null)
    { parent::__construct($provider, $code, $message, $previous); }
}
final class UnknownProviderOutcome extends ProviderFailure {}
```

## B1 — Message + handler (Application)
```php
final readonly class DeliverNotification
{
    public function __construct(public string $deliveryId) {}
}

#[AsMessageHandler]
final readonly class DeliverNotificationHandler
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private FailoverDeliveryStrategy $strategy,
        private DeliveryThrottle $throttle,
        private MessageBusInterface $bus,
    ) {}

    public function __invoke(DeliverNotification $message): void
    {
        $delivery = $this->deliveries->get(DeliveryId::fromString($message->deliveryId));
        if ($delivery->isFinal()) {
            return;
        }
        $decision = $this->throttle->decide($delivery);
        if ($decision->isThrottled()) {
            $delivery->markThrottled();
            $this->deliveries->save($delivery);
            $this->bus->dispatch($message, [new DelayStamp($decision->retryAfterMs())]);
            return;
        }
        $result = $this->strategy->deliver($delivery);      // records attempts on the aggregate
        $this->deliveries->save($delivery);
        match (true) {
            $result->succeeded() => null,
            $result->isPermanent() => throw new UnrecoverableMessageHandlingException($result->reason()),
            default => throw new RecoverableMessageHandlingException($result->reason()),
        };
    }
}
```
`RecoverableMessageHandlingException` accepts `retryDelay: int` (ms) as 4th constructor argument in 7.2+ if a custom
delay is needed; otherwise the transport retry strategy applies.

## B2 — Dispatch inside the same transaction (Application)
```php
// messenger.yaml: buses.command.bus.middleware: [doctrine_transaction]   (default bus, SendNotification)
//                 buses.delivery.bus.middleware: [doctrine_ping_connection]  (DeliverNotification; NO transaction)
// SendNotificationHandler runs synchronously on command.bus, so its DB writes and the DeliverNotification rows
// written by the doctrine transport (same connection) commit together (outbox semantics).
public function __construct(private NotificationRepository $notifications, #[Target('deliveryBus')] private MessageBusInterface $deliveryBus) {}

$this->notifications->save($notification);
foreach ($notification->deliveries() as $delivery) {
    if ($delivery->isPending()) {
        $this->deliveryBus->dispatch(new DeliverNotification($delivery->id()->value));
    }
}
```
Why no `doctrine_transaction` on `delivery.bus`: the middleware rolls back on any exception, and the delivery
handler *throws* Recoverable/Unrecoverable to drive retries — the attempt rows would vanish. It saves, then throws
(DECISIONS §3.6). Tests: `async: 'in-memory://'` in `when@test`, never `sync://` (it would run the delivery inside
the command transaction and turn a provider failure into a rolled-back notification + HTTP 500).

## B3 — Failover strategy skeleton
```php
final readonly class FailoverDeliveryStrategy
{
    public function __construct(private ChannelConfiguration $config, private ProviderRegistry $providers, private ClockInterface $clock) {}

    public function deliver(Delivery $delivery): DeliveryResult
    {
        $ordered = $this->config->providersFor($delivery->channel(), $delivery->id());   // priority | round_robin
        foreach ($ordered as $name) {
            $provider = $this->providers->get($name);
            $attempt = $delivery->startAttempt($name, $this->clock->now());
            try {
                $receipt = $provider->send($delivery->toOutboundMessage());
                $delivery->completeAttempt($attempt, $receipt, $this->clock->now());
                return DeliveryResult::sent($name);
            } catch (TransientProviderFailure $e) {
                $delivery->failAttempt($attempt, AttemptOutcome::TransientFailure, $e, $this->clock->now());
                continue;                                   // next provider
            } catch (UnknownProviderOutcome $e) {
                $delivery->failAttempt($attempt, AttemptOutcome::Unknown, $e, $this->clock->now());
                return DeliveryResult::retryLater($e);      // no same-run failover
            } catch (PermanentProviderFailure $e) {
                $delivery->failAttempt($attempt, AttemptOutcome::PermanentFailure, $e, $this->clock->now());
                if ($e->recipientLevel) { return DeliveryResult::failed($e); }
                continue;                                   // provider-level (auth) -> try next once
            }
        }
        return DeliveryResult::retryLater(...);              // all transient/provider-level
    }
}
```

## C1 — Doctrine XML mapping (ORM 3)
`config/packages/doctrine.yaml`:
```yaml
doctrine:
    orm:
        mappings:
            NotificationPublisher:
                type: xml
                is_bundle: false
                dir: '%kernel.project_dir%/src/NotificationPublisher/Infrastructure/Persistence/Doctrine/Mapping'
                prefix: 'App\NotificationPublisher\Domain\Model'
                alias: NotificationPublisher
```
`Mapping/Notification.orm.xml` (file name = class short name; nested namespaces use `.` instead of `\`):
```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                                      https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
    <entity name="App\NotificationPublisher\Domain\Model\Notification" table="notifications">
        <id name="id" type="notification_id" column="id"/>
        <field name="userId" type="string" length="64" column="user_id"/>
        <field name="idempotencyKey" type="string" length="128" column="idempotency_key" unique="true"/>
        <field name="requiresUserAction" type="boolean" column="requires_user_action"/>
        <field name="createdAt" type="datetime_immutable" column="created_at"/>
        <embedded name="content" class="App\NotificationPublisher\Domain\Model\NotificationContent" use-column-prefix="false"/>
        <indexes><index name="idx_notifications_user" columns="user_id"/></indexes>
    </entity>
</doctrine-mapping>
```
Embeddable: `<embeddable name="...NotificationContent"><field name="subject" .../><field name="body" type="text"/></embeddable>`.
Relations: `<many-to-one field="notification" target-entity="...Notification"><join-column name="notification_id" nullable="false" on-delete="CASCADE"/></many-to-one>`.
Enum column: `<field name="status" type="string" length="20" enum-type="...DeliveryStatus"/>` (string-backed enum).
Remove the starter's `App` attribute mapping from `doctrine.yaml` when adding this one: `MappingDriverChain` returns
the first driver whose prefix matches, so `App` (attribute) listed before `App\NotificationPublisher\Domain\Model`
(xml) would claim the class and fail with "not a valid entity or mapped superclass".

## C2 — Custom DBAL type wrapping a value object (Infrastructure)
```php
final class NotificationIdType extends Type
{
    public const NAME = 'notification_id';
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    { return $platform->getGuidTypeDeclarationSQL($column); }       // CHAR(36); use BINARY(16) via UuidType if preferred
    public function convertToPHPValue($value, AbstractPlatform $platform): ?NotificationId
    { return $value === null ? null : NotificationId::fromString((string) $value); }
    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    { return $value instanceof NotificationId ? $value->value : $value; }
}
```
Register in `doctrine.yaml`: `doctrine.dbal.types: { notification_id: App\...\NotificationIdType }`.
Simpler alternative: type `uuid` (Symfony bridge, auto-registered) and keep `Uuid` objects in Infrastructure only.

## C3 — Provider discovery via tags
```php
// services.yaml (Domain stays attribute-free; check-layers forbids Symfony\ in Domain):
//   _instanceof: { App\NotificationPublisher\Domain\Port\NotificationProvider: { tags: ['notification.provider'] } }

// Infrastructure adapter: the index comes from the class attribute, honoured for tags added by _instanceof too
#[AsTaggedItem(index: 'twilio')]
final readonly class TwilioSmsProvider implements NotificationProvider { ... }

// Infrastructure registry
final class ProviderRegistry
{
    /** @var array<string, NotificationProvider> */
    private array $byName;
    /** @param iterable<string, NotificationProvider> $providers keyed by the AsTaggedItem index */
    public function __construct(#[AutowireIterator('notification.provider')] iterable $providers)
    { $this->byName = iterator_to_array($providers); }
    /** @param list<NotificationProvider> $providers for unit tests, keyed by name() */
    public static function fromList(array $providers): self { /* new self(keyed by ->name()) */ }
    public function get(string $name): NotificationProvider
    { return $this->byName[$name] ?? throw UnknownProvider::named($name); }
    /** @return list<string> */
    public function names(): array { return array_keys($this->byName); }
}
```
Pitfall: `defaultIndexMethod: 'name'` only works with a **static** method; our `name()` is an instance method, so
use `#[AsTaggedItem(index: ...)]`. Compile-time validation of `%notifications.channels%` against the tag indexes
belongs in a compiler pass (`Kernel::build()`), not in a service constructor (that would fire at runtime only).

## C4 — Configuration parameters -> validated VO at boot
```yaml
# config/packages/notifications.yaml
parameters:
    notifications.channels:
        email: { enabled: true, strategy: priority, providers: ['smtp', 'fake_email'] }
        sms:   { enabled: true, strategy: round_robin, providers: ['twilio', 'fake_sms'] }
```
```php
final readonly class ChannelConfiguration
{
    /** @param array<string, array{enabled: bool, strategy: string, providers: list<string>}> $raw */
    public function __construct(#[Autowire('%notifications.channels%')] array $raw, ProviderRegistry $registry)
    {
        foreach ($raw as $channel => $cfg) {
            Channel::from($channel);
            if ($cfg['enabled'] && $cfg['providers'] === []) { throw InvalidChannelConfiguration::noProviders($channel); }
            foreach ($cfg['providers'] as $name) { $registry->get($name); }     // fails container build on typo
        }
        // ...
    }
}
```
Env override: `providers: '%env(csv:NOTIFICATIONS_SMS_PROVIDERS)%'` with `.env` default `NOTIFICATIONS_SMS_PROVIDERS=twilio,fake_sms`.

## C5 — Rate limiter (sliding window, DB-backed)
```yaml
framework:
    cache:
        pools:
            cache.rate_limiter: { adapter: cache.adapter.doctrine_dbal, provider: 'doctrine.dbal.default_connection' }
    rate_limiter:
        per_user_notifications:
            policy: sliding_window
            limit: '%env(int:NOTIFICATIONS_THROTTLE_LIMIT)%'
            interval: '%env(NOTIFICATIONS_THROTTLE_INTERVAL)%'
            cache_pool: cache.rate_limiter
            lock_factory: null
```
```php
public function __construct(#[Target('perUserNotificationsLimiter')] private RateLimiterFactoryInterface $factory) {}
$limit = $this->factory->create($userId)->consume(1);
if (!$limit->isAccepted()) { $retryAfterMs = max(0, $limit->getRetryAfter()->getTimestamp() - time()) * 1000; }
```
DBAL cache adapter creates its table (default name `cache_items`) on first miss; add it to a migration in the same
phase (4.1) so worker and app agree — `doctrine:migrations:diff` should include it via the Doctrine bridge schema
listener once the pool exists; otherwise write the DDL by hand.

## D1 — Request DTO + controller
```php
final readonly class SendNotificationRequest
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 64)] public string $userId,
        #[Assert\NotBlank, Assert\Length(max: 128)] public string $idempotencyKey,
        #[Assert\NotBlank, Assert\Length(max: 64)] public string $source,
        /** @var list<string> */
        #[Assert\Count(min: 1), Assert\All([new Assert\Choice(callback: [Channel::class, 'values'])])] public array $channels,
        #[Assert\Valid] public ContentPayload $content,
        public bool $requiresUserAction = false,
    ) {}
}

#[Route('/notifications', methods: ['POST'])]
public function send(#[MapRequestPayload] SendNotificationRequest $request): JsonResponse
{
    $result = $this->handle(new SendNotification(...));   // returns id + created flag
    return new JsonResponse($this->presenter->present($result->notification), $result->created ? 202 : 200);
}
```
Validation failures raise a 422 `HttpException` automatically; malformed JSON -> 400.

## D2 — JSON problem responses
```php
#[AsEventListener]
final readonly class JsonExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        [$status, $title] = match (true) {
            $e instanceof NotificationNotFound => [404, 'Notification not found'],
            $e instanceof UnknownUser => [422, 'Unknown user'],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getMessage()],
            default => [500, 'Internal error'],
        };
        $event->setResponse(new JsonResponse(['type' => 'about:blank', 'title' => $title, 'status' => $status], $status));
    }
}
```

## D3 — OpenAPI attributes (Nelmio 5 / swagger-php 6)

```php
use App\NotificationPublisher\UserInterface\Http\OpenApi\ProblemSchema;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Notifications')]
#[OA\Post(summary: 'Accept a notification')]
#[OA\RequestBody(required: true, content: new OA\JsonContent(example: ['userId' => 'user-1']))]
#[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(
    response: 202,
    description: 'Accepted',
    content: new OA\JsonContent(
        required: ['id'],
        properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')],
    ),
)]
#[OA\Response(
    response: 422,
    description: 'Unprocessable',
    content: new OA\JsonContent(ref: new Model(type: ProblemSchema::class)),
)]
```

Schema classes live in `UserInterface/Http/OpenApi/` (`ProblemSchema` is the RFC 7807 error body). After edits:
`nelmio:apidoc:dump --format=json > docs/openapi.json`. JSON route controller id is
`nelmio_api_doc.controller.swagger` (not `swagger_json`). Generator service:
`nelmio_api_doc.generator` (alias of `nelmio_api_doc.generator.default`; `generator_locator->get('default')` is a
phpstan-symfony false positive). The document is `OpenApi\Annotations\OpenApi` with `toJson()`, not `toArray()`.
