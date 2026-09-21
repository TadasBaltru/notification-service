# Recipes (copy-paste fragments)

Rules: copy the fragment before writing boilerplate; keep each fragment <= 40 lines. A heading that contains
"template" marks a fragment that is not yet backed by project code; `tools/check-docs.php` skips class/path
checks inside such sections. When the real class exists (Day 1-2), replace the fragment with the real code and
drop "template" from the heading so the checker starts guarding it. Longer variants: `.cursor/skills/*/reference.md`.

## R1 — Provider adapter (template, replaced in 2.3/2.4; skill fragment A4/A5)
```php
#[AsTaggedItem(index: 'twilio')]
final readonly class TwilioSmsProvider implements NotificationProvider
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(TWILIO_ACCOUNT_SID)%')] private string $accountSid,
        #[Autowire('%env(TWILIO_AUTH_TOKEN)%')] private string $authToken,
        #[Autowire('%env(TWILIO_FROM)%')] private string $from,
        private ClockInterface $clock,
    ) {}

    public function name(): string { return 'twilio'; }
    public function channel(): Channel { return Channel::Sms; }

    public function send(Delivery $delivery, NotificationContent $content): ProviderResult
    {
        try {
            $response = $this->httpClient->request('POST', $this->url(), [/* auth_basic, body, timeout 5 */]);
            $status = $response->getStatusCode(); // throws on transport error only when accessed
        } catch (TransportExceptionInterface $e) {
            throw new UnknownProviderOutcome($this->name(), $e->getMessage(), $e); // sent? unknown -> no failover
        }
        return match (true) {
            201 === $status => ProviderResult::accepted($response->toArray()['sid']),
            429 === $status, $status >= 500 => throw TransientProviderFailure::fromStatus($this->name(), $status),
            in_array($status, [401, 403], true) => throw PermanentProviderFailure::provider($this->name(), $status),
            default => throw PermanentProviderFailure::recipient($this->name(), $this->errorCode($response)),
        };
    }
}
```
Register nothing by hand: the tag is collected by `#[AutowireIterator('notification.provider')]` in the registry.

## R2 — New channel (template, replaced in 2.1; skill fragment C4)
1. Add a case to `Channel` enum (`case Push = 'push';`) with its `recipientField()` behaviour.
2. Add the channel block to `config/packages/notifications.yaml`:
   `push: { enabled: false, strategy: priority, providers: ['fake_push'] }`.
3. Add at least one adapter (R1) tagged with the channel; `ChannelConfiguration` validation fails
   `cache:clear` until every listed provider name exists.
4. Tests: enum unit test, configuration validation test, one end-to-end test through `tests/Support/DeliveryPipeline`.

## R3 — Messenger message + handler (template, replaced in 3.1; skill fragment B1/B2)
```php
final readonly class DeliverNotification
{
    public function __construct(public string $deliveryId) {}
}

#[AsMessageHandler(bus: 'delivery.bus')]
final readonly class DeliverNotificationHandler
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private FailoverDeliveryStrategy $strategy,
    ) {}

    public function __invoke(DeliverNotification $message): void
    {
        $delivery = $this->deliveries->get(DeliveryId::fromString($message->deliveryId));
        if ($delivery->isFinal()) {
            return; // redelivery after an unknown outcome: idempotent no-op
        }
        $result = $this->strategy->deliver($delivery);
        $this->deliveries->save($delivery);          // flush BEFORE throwing: delivery.bus has no transaction middleware

        match (true) {
            $result->isSent() => null,
            $result->isFailed() => throw new UnrecoverableMessageHandlingException($result->reason()),
            default => throw new RecoverableMessageHandlingException($result->reason()),
        };
    }
}
```
`SendNotification` runs on `command.bus` with `doctrine_transaction`, so the notification rows and the
`DeliverNotification` outbox rows (Doctrine transport, same connection) commit together. `delivery.bus` has no
transaction middleware on purpose: it would roll back the attempt evidence on the exceptions above (DECISIONS §3.6).

## R4 — Doctrine attribute mapping + migration (from `src/NotificationPublisher/Domain/Model/Notification.php`; skill fragment C1/C2)
```php
#[ORM\Entity]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notifications_user_id', columns: ['user_id'])]
final class Notification
{
    /** @var Collection<int, Delivery> */
    #[ORM\OneToMany(targetEntity: Delivery::class, mappedBy: 'notification', cascade: ['persist'], orphanRemoval: true)]
    private Collection $deliveries;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'notification_id')]
        private readonly NotificationId $id,
        #[ORM\Column(type: 'user_id', length: 64)]
        private readonly UserId $userId,
        #[ORM\Column(type: 'idempotency_key', length: 128, unique: true)]
        private readonly IdempotencyKey $idempotencyKey,
        #[ORM\Embedded(class: NotificationContent::class, columnPrefix: false)]
        private readonly NotificationContent $content,
        #[ORM\Column]
        private readonly bool $requiresUserAction,
        #[ORM\Column(type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->deliveries = new ArrayCollection();
        $this->updatedAt = $createdAt;
    }
}
```
`doctrine.yaml` mappings: `type: attribute`, `dir: .../Domain/Model`, `prefix: App\NotificationPublisher\Domain\Model`.
Enums use `enumType: Channel::class`. Custom DBAL types wrap VO ids; `Uuid` stays in the type. No `status` on
`notifications`. Migration `migrations/Version20260921115344.php` creates the three domain tables only.

## R5 — API endpoint with DTO (from `src/NotificationPublisher/UserInterface/Http/NotificationController.php`; skill fragment D1/D2)
```php
final readonly class SendNotificationRequest
{
    public function __construct(
        #[Assert\NotBlank] public string $userId,
        #[Assert\NotBlank, Assert\Length(max: 128)] public string $idempotencyKey,
        #[Assert\Count(min: 1), Assert\All([new Assert\Choice(callback: [Channel::class, 'values'])])] public array $channels,
        #[Assert\NotBlank] public string $subject,
        #[Assert\NotBlank] public string $body,
        public bool $requiresUserAction = false,
        #[Assert\Valid] public ?RecipientOverrideRequest $recipient = null,
    ) {}
}

final readonly class NotificationController
{
    #[Route('/notifications', methods: ['POST'])]
    public function send(#[MapRequestPayload] SendNotificationRequest $request): JsonResponse
    {
        $result = $this->handled($this->bus->dispatch($request->toCommand()), SendNotificationResult::class);

        return new JsonResponse($this->presenter->present($result->notification), $result->created ? 202 : 200);
    }
}
```
The controller reads `HandledStamp` so 3.1 can add `doctrine_transaction` without changing HTTP. `JsonExceptionListener` unwraps `HandlerFailedException` (`getWrappedExceptions()`) and emits the `Problem` shape (`type`, `title`, `status`, `detail`, `violations`). Replay of the same `idempotencyKey` is **200**; create is **202**.

## R6 — Unit test (from `tests/Unit/NotificationPublisher/Domain/Model/DeliveryTest.php`; skill fragment T1)
```php
final class DeliveryTest extends TestCase
{
    public function test_it_cannot_be_marked_sent_twice(): void
    {
        $delivery = DeliveryBuilder::aPendingSmsDelivery()->build();
        $delivery->markSent('twilio', 'SM123', new \DateTimeImmutable(DeliveryBuilder::NOW));

        $this->expectException(DeliveryAlreadyFinal::class);

        $delivery->markSent('fake_sms', 'F1', new \DateTimeImmutable('2026-01-01 10:00:01 UTC'));
    }
}
```
No kernel, no DB; builders live in `tests/Support/DeliveryBuilder.php`. A final delivery (`sent`/`failed`/`skipped`)
throws `DeliveryAlreadyFinal`; `throttled` is not final and can still `markSent`.

## R7 — Integration test skeleton (from `tests/Integration/NotificationPublisher/UserInterface/NotificationApiTest.php`; skill fragment T5/T6)
```php
final class NotificationApiTest extends WebTestCase
{
    public function test_it_accepts_a_notification_and_returns_202(): void
    {
        $client = self::createClient();

        $client->request('POST', '/notifications', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['userId' => 'user-1', 'idempotencyKey' => 'k-1', 'channels' => ['email'],
                'subject' => 'Hi', 'body' => 'Body'], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(202);
        $this->assertResponseIsDocumented($client);
    }
}
```
Runs against `app_test`; `dama/doctrine-test-bundle` rolls the transaction back after each test, so no cleanup.
Replay of the same `idempotencyKey` is 200 with the same `id`. Messenger `async` is still unused (3.1); do not
dispatch `DeliverNotification` yet.

## R8 — Fake provider with a mode (template, replaced in 2.1; skill fragment A3/T2)
```php
enum FakeMode: string { case Success = 'success'; case Transient = 'transient'; case PermanentRecipient = 'permanent_recipient'; case PermanentProvider = 'permanent_provider'; case Timeout = 'timeout'; }

#[AsTaggedItem(index: 'fake_sms')]
final class FakeSmsProvider implements NotificationProvider
{
    /** @var list<Delivery> */ private array $sent = [];

    public function __construct(#[Autowire('%env(default:fake_sms_default:FAKE_SMS_MODE)%')] private string $mode) {}

    public static function withMode(FakeMode $mode): self { return new self($mode->value); }

    public function send(Delivery $delivery, NotificationContent $content): ProviderResult
    {
        return match (FakeMode::from($this->mode)) {
            FakeMode::Success => $this->record($delivery),
            FakeMode::Transient => throw TransientProviderFailure::fromStatus($this->name(), 503),
            FakeMode::PermanentRecipient => throw PermanentProviderFailure::recipient($this->name(), 'invalid_number'),
            FakeMode::PermanentProvider => throw PermanentProviderFailure::provider($this->name(), 401),
            FakeMode::Timeout => throw new UnknownProviderOutcome($this->name(), 'simulated timeout'),
        };
    }

    public function sentCount(): int { return count($this->sent); }
}
```
Fakes are real services (demo providers) and the preferred double for our own port; `MockHttpClient` is used
only for the external Twilio API.

## R9 — Documented endpoint (from `src/Controller/HealthController.php`; skill fragment D3)
```php
use App\NotificationPublisher\UserInterface\Http\OpenApi\ProblemSchema;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/health', name: 'health', methods: ['GET'])]
#[OA\Tag(name: 'Operations')]
#[OA\Get(summary: 'Liveness check')]
#[OA\Response(
    response: 200,
    description: 'Service is up',
    content: new OA\JsonContent(
        required: ['status'],
        properties: [new OA\Property(property: 'status', type: 'string', example: 'ok')],
    ),
)]
#[OA\Response(
    response: 422,
    description: 'Request could not be processed',
    content: new OA\JsonContent(ref: new Model(type: ProblemSchema::class)),
)]
public function __invoke(): JsonResponse
{
    return new JsonResponse(['status' => 'ok']);
}
```
After changing attributes, dump `docs/openapi.json` and call `assertResponseIsDocumented($client)` in the
endpoint's `WebTestCase`. The 422 example is the 1.3 pattern; `/health` itself only returns 200.
