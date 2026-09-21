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

## R4 — Doctrine XML mapping + migration (template, replaced in 1.2; skill fragment C1/C2)
```xml
<!-- src/NotificationPublisher/Infrastructure/Persistence/Doctrine/Mapping/Notification.orm.xml -->
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping">
    <entity name="App\NotificationPublisher\Domain\Model\Notification" table="notifications">
        <id name="id" type="notification_id" column="id"/>
        <field name="userId" type="user_id" column="user_id"/>
        <field name="idempotencyKey" type="idempotency_key" column="idempotency_key" unique="true"/>
        <field name="requiresUserAction" type="boolean" column="requires_user_action"/>
        <one-to-many field="deliveries" target-entity="App\NotificationPublisher\Domain\Model\Delivery"
                     mapped-by="notification" orphan-removal="true"><cascade><cascade-persist/></cascade></one-to-many>
        <indexes><index columns="user_id"/></indexes>
    </entity>
</doctrine-mapping>
```
Then: `doctrine.yaml` — **replace** the starter's `App` attribute mapping with `type: xml`, `dir: .../Mapping`,
`prefix: App\NotificationPublisher\Domain\Model` (the driver chain returns the first prefix match, so a leading
`App` attribute driver would shadow the XML one); `bin/console doctrine:migrations:diff` -> review ->
`doctrine:migrations:migrate -n`; `doctrine:schema:validate` must print `[OK]` for both mapping and database.
No `status` on `notifications` — derived from deliveries (DECISIONS §4).

## R5 — API endpoint with DTO (template, replaced in 1.3; skill fragment D1/D2)
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
    ) {}
}

final readonly class NotificationController
{
    #[Route('/notifications', methods: ['POST'])]
    public function send(#[MapRequestPayload] SendNotificationRequest $request): JsonResponse
    {
        $result = $this->handler->handle($request->toCommand());

        return new JsonResponse($this->presenter->present($result), $result->created ? 202 : 200);
    }
}
```
Validation failures become 422 and domain exceptions become 400/404/409 through a `#[AsEventListener]` on
`kernel.exception` that returns `application/problem+json`.

## R6 — Unit test skeleton (template, replaced in 1.1; skill fragment T1)
```php
final class DeliveryTest extends TestCase
{
    public function test_it_cannot_be_sent_twice(): void
    {
        $delivery = DeliveryBuilder::aDelivery()->sent()->build();

        $this->expectException(DeliveryAlreadySent::class);

        $delivery->markSent('smtp', 'msg-1', new \DateTimeImmutable('2026-01-01 10:00:00'));
    }
}
```
No kernel, no DB; builders live in `tests/Support/`.

## R7 — Integration test skeleton (template, replaced in 1.2/1.3; skill fragment T5/T6)
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
        self::assertJson((string) $client->getResponse()->getContent());
    }
}
```
Runs against `app_test`; `dama/doctrine-test-bundle` rolls the transaction back after each test, so no cleanup.
Messenger `async` is `in-memory://` in the test env: the POST only queues `DeliverNotification`; end-to-end tests
run the queued envelopes on `delivery.bus` through `tests/Support/DeliveryPipeline` (3.3), never `sync://`.

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
