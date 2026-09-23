# Recipes (copy-paste fragments)

Rules: copy the fragment before writing boilerplate; keep each fragment <= 40 lines. A heading that contains
"template" marks a fragment that is not yet backed by project code; `tools/check-docs.php` skips class/path
checks inside such sections. When the real class exists (Day 1-2), replace the fragment with the real code and
drop "template" from the heading so the checker starts guarding it. Longer variants: `.cursor/skills/*/reference.md`.

## R1 — Provider adapter (from `src/NotificationPublisher/Infrastructure/Provider/Sms/TwilioSmsProvider.php`; skill fragment A5)
```php
#[AsTaggedItem(index: 'twilio')]
final readonly class TwilioSmsProvider implements NotificationProvider
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private TwilioFailureClassifier $failures,
        #[Autowire('%env(TWILIO_ACCOUNT_SID)%')]
        private string $accountSid,
        #[Autowire('%env(TWILIO_AUTH_TOKEN)%')]
        private string $authToken,
        #[Autowire('%env(TWILIO_FROM)%')]
        private string $from,
    ) {}

    public function send(OutboundMessage $message): ProviderResult
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                \sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode($this->accountSid)),
                [
                    'auth_basic' => [$this->accountSid, $this->authToken],
                    'timeout' => 5.0,
                    'body' => [
                        'To' => $message->recipient->address(),
                        'From' => $this->from,
                        'Body' => $message->content->body(),
                    ],
                ],
            );
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            throw new UnknownProviderOutcome($this->name(), \sprintf('Twilio outcome unknown (%s)', $this->name()), $exception);
        }

        return $this->failures->classify($this->name(), $status, $this->payload($response, $status));
    }
}
```
`name()` returns `twilio`; `channel()` returns `Channel::Sms`. `payload()` calls `toArray(false)` only for
201 and 400, so a 4xx/5xx never becomes `ClientException`/`ServerException`. Only
`TransportExceptionInterface` is unknown. `TwilioFailureClassifier`
(`src/NotificationPublisher/Infrastructure/Provider/Sms/TwilioFailureClassifier.php`) maps the status:
201 + `sid` success, 400 `21211`/`21614` recipient permanent, other 4xx provider permanent, 429/5xx transient.
The tag is collected by `#[AutowireIterator('notification.provider')]`. SMTP is the same port over
`TransportInterface` (`src/NotificationPublisher/Infrastructure/Provider/Email/SmtpMailerProvider.php`).

## R2 — New channel (from `config/packages/notifications.yaml`; skill fragment C4)
1. Add a case to `Channel` (`case Push = 'push';`) with its `recipientField()`.
2. Add the channel to `config/packages/notifications.yaml` and the `.env` defaults
   `NOTIFICATIONS_PUSH_ENABLED` / `NOTIFICATIONS_PUSH_PROVIDERS` (csv). Only list providers that exist.
3. Add an adapter (R8 or R1) with `#[AsTaggedItem(index: 'fake_push')]`. `_instanceof` in
   `config/services.yaml` adds the `notification.provider` tag; the Domain port stays attribute-free.
4. `ValidateChannelConfigurationPass` (registered in `Kernel::build()`) rejects an unknown channel,
   unknown strategy, unknown provider name, or an enabled channel with an empty list.
   `cache:clear` fails with `InvalidChannelConfiguration` before a request is served.
5. Tests: `ChannelConfigurationTest` for each rejection; tag check via
   `bin/console debug:container --tag=notification.provider`.

## R3 — Messenger message + handler (from `src/NotificationPublisher/Application/Command/DeliverNotificationHandler.php`)
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
            return;
        }
        $result = $this->strategy->deliver($delivery, fn() => $this->deliveries->save($delivery));
        $this->deliveries->save($delivery);
        if ($result->succeeded()) {
            return;
        }
        if ($result->isPermanent()) {
            throw new UnrecoverableMessageHandlingException($result->reason());
        }
        throw new RecoverableMessageHandlingException($result->reason());
    }
}
```
`SendNotificationHandler` dispatches one message per pending delivery on `delivery.bus` (`#[Target('deliveryBus')]`).
`command.bus` has `doctrine_transaction`, so those Doctrine-transport rows commit with the notification.
`delivery.bus` has only `doctrine_ping_connection` (DECISIONS §3.6). The closure flushes `in_progress` before `send()`.
Routing lives in `config/packages/messenger.yaml`. Tests use `in-memory://`.

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
Replay of the same `idempotencyKey` is 200 with the same `id`. In test, `async` is `in-memory://`: a POST
queues `DeliverNotification` and does not run the provider.

## R8 — Fake provider with a mode (from `src/NotificationPublisher/Infrastructure/Provider/Fake/FakeSmsProvider.php`; skill fragment A3/T2)
```php
#[AsTaggedItem(index: 'fake_sms')]
final class FakeSmsProvider implements NotificationProvider
{
    /** @var list<OutboundMessage> */
    private array $sent = [];

    private string $name = 'fake_sms';

    public function __construct(
        #[Autowire('%env(FAKE_SMS_MODE)%')]
        private string $mode,
    ) {}

    public static function withMode(FakeMode $mode, string $name = 'fake_sms'): self
    {
        $provider = new self($mode->value);
        $provider->name = $name;

        return $provider;
    }

    public function send(OutboundMessage $message): ProviderResult
    {
        $failure = FakeMode::from($this->mode)->failure($this->name(), 'invalid_number');
        if (null !== $failure) {
            throw $failure;
        }
        $this->sent[] = $message;

        return ProviderResult::accepted(sprintf('%s:%s', $this->name(), $message->deliveryId->value));
    }
}
```
`FakeMode` is `success|transient|permanent_recipient|permanent_provider|timeout`. `FakeEmailProvider` is the same
shape with `fake_email` / `FAKE_EMAIL_MODE` / `invalid_address`. Pass a second argument to `withMode()` when several
fakes must share one registry. Fakes are real tagged services. Unit tests call `withMode()`;
`ProviderRegistry::fromList()` avoids booting the container. `MockHttpClient` is only for Twilio.

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
