# Testing reference fragments (PHPUnit 11.5, Symfony 7.4)

## T1 — Domain unit test
```php
final class DeliveryTest extends TestCase
{
    public function test_it_cannot_be_marked_sent_twice(): void
    {
        $delivery = DeliveryBuilder::aPendingSmsDelivery()->build();
        $delivery->markSent('twilio', 'SM123', new \DateTimeImmutable('2026-01-01 10:00:00'));

        $this->expectException(DeliveryAlreadyFinal::class);

        $delivery->markSent('fake_sms', 'F1', new \DateTimeImmutable('2026-01-01 10:00:01'));
    }
}
```

## T2 — Strategy with in-repo fakes
```php
$primary = new FakeSmsProvider('fake_a', FakeMode::Transient);
$secondary = new FakeSmsProvider('fake_b', FakeMode::Success);
$strategy = new FailoverDeliveryStrategy(
    ChannelConfiguration::fromArray(['sms' => ['enabled' => true, 'strategy' => 'priority', 'providers' => ['fake_a', 'fake_b']]]),
    ProviderRegistry::fromList([$primary, $secondary]),
    new MockClock('2026-01-01 10:00:00'),
);

$result = $strategy->deliver($delivery);

self::assertTrue($result->succeeded());
self::assertSame(['fake_a', 'fake_b'], array_map(fn ($a) => $a->provider(), $delivery->attempts()));
self::assertSame(AttemptOutcome::TransientFailure, $delivery->attempts()[0]->outcome());
```
Fake modes: `Success`, `Transient`, `PermanentRecipient`, `PermanentProvider`, `Timeout` (throws `UnknownProviderOutcome`).
Give `ProviderRegistry` a plain-array constructor path so unit tests never need the container.

## T3 — MockHttpClient for a REST provider
```php
$responses = [
    new MockResponse(json_encode(['sid' => 'SM1', 'status' => 'queued']), ['http_code' => 201]),
    new MockResponse(json_encode(['code' => 21211, 'message' => 'Invalid To number']), ['http_code' => 400]),
    new MockResponse('', ['http_code' => 503]),
    new MockResponse('', ['error' => 'Idle timeout reached']),   // -> TransportException on access -> UnknownProviderOutcome
];
$client = new MockHttpClient($responses);
$provider = new TwilioSmsProvider($client, 'ACxxx', 'token', '+15005550006');

$receipt = $provider->send($message);                         // 1st response
self::assertSame('SM1', $receipt->providerMessageId);

// assert the outgoing request
$client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
    self::assertSame('POST', $method);
    self::assertStringEndsWith('/Accounts/ACxxx/Messages.json', $url);
    self::assertStringContainsString('To=%2B370', $options['body']);
    return new MockResponse('{"sid":"SM2"}', ['http_code' => 201]);
});
```
Timeouts: HttpClient throws `TransportExceptionInterface`; 4xx/5xx surface via `$response->getStatusCode()` (do not
call `toArray()` without `false` unless you want `ClientException`/`ServerException`). Map in the adapter:
`TransportExceptionInterface -> UnknownProviderOutcome`, `429|5xx -> Transient`, `400 with recipient codes -> Permanent(recipientLevel: true)`,
`401|403 -> Permanent(recipientLevel: false)`.

## T4 — Mailer assertions (KernelTestCase)
```php
final class SmtpMailerProviderTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    public function test_it_sends_an_email_with_deterministic_message_id(): void
    {
        self::bootKernel();
        $provider = self::getContainer()->get(SmtpMailerProvider::class);

        $provider->send($outboundEmail);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailHeaderSame($email, 'Message-ID', '<019...@notifications.local>');
        self::assertEmailAddressContains($email, 'To', 'user1@example.test');
    }
}
```
Requires `MAILER_DSN=null://null` in `.env.test` (the recipe default) — messages are captured, not sent.

## T5 — Repository round-trip (real DB, dama rollback)
```php
final class DoctrineNotificationRepositoryTest extends KernelTestCase
{
    public function test_it_persists_and_reloads_a_notification(): void
    {
        self::bootKernel();
        $repo = self::getContainer()->get(NotificationRepository::class);
        $notification = NotificationBuilder::aNotification()->withChannels(Channel::Sms)->build();

        $repo->save($notification);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $reloaded = $repo->get($notification->id());
        self::assertTrue($reloaded->id()->equals($notification->id()));
    }
}
```

## T6 — WebTestCase JSON API (+ end-to-end through the in-memory transport)
```php
final class SendNotificationEndpointTest extends WebTestCase
{
    public function test_it_accepts_a_notification_and_delivers_through_fakes(): void
    {
        $client = self::createClient();

        $client->request('POST', '/notifications', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'userId' => 'user-1', 'idempotencyKey' => 'order-1',
            'channels' => ['sms'], 'subject' => 'Hi', 'body' => 'Hello',
        ]));
        self::assertResponseStatusCodeSame(202);

        // the POST only queued the work; run it the way the worker would (tests/Support/DeliveryPipeline wraps this)
        /** @var InMemoryTransport $async */
        $async = self::getContainer()->get('messenger.transport.async');
        $bus = self::getContainer()->get('delivery.bus');
        $failure = null;
        foreach ($async->getSent() as $envelope) {
            try { $bus->dispatch($envelope->with(new ReceivedStamp('async'))); }
            catch (HandlerFailedException $e) { $failure = $e->getWrappedExceptions()[0] ?? $e; }
        }

        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $client->request('GET', '/notifications/'.$payload['id']);
        self::assertResponseIsSuccessful();
        // assert decoded array: deliveries[0].status === 'sent'; or assertInstanceOf(DeliveryRequiresRetry::class, $failure)
    }
}
```
`async` is `in-memory://` in `test` (never `sync://`: that would run the delivery inside the command transaction,
so a failing provider rolls back the notification and the POST becomes a 500 — DECISIONS §3.6). `ReceivedStamp`
makes the bus handle the envelope instead of re-sending it. Use `$client->disableReboot()` if several requests
must share one kernel; not needed with dama.

## T7 — MockClock
```php
$clock = new MockClock('2026-01-01 10:00:00');
$throttle = new RateLimiterDeliveryThrottle($factory, $clock);
// ... consume 300 ...
$clock->modify('+1 hour');
self::assertFalse($throttle->decide($delivery)->isThrottled());
```
Register in test env: `services: Psr\Clock\ClockInterface: '@Symfony\Component\Clock\MockClock'` in `when@test`, or
inject `MockClock` directly in unit tests. Rate limiter storage in unit tests: `new InMemoryStorage()` +
`new RateLimiterFactory(['id' => 't', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 hour'], $storage)`.

## T8 — Assert a message was dispatched (in-memory transport)
```php
/** @var InMemoryTransport $transport */
$transport = self::getContainer()->get('messenger.transport.async');
self::assertCount(1, $transport->getSent());
self::assertInstanceOf(DeliverNotification::class, $transport->getSent()[0]->getMessage());
```
"Was it queued" assertions; for behaviour, dispatch the queued envelopes on `delivery.bus` as in T6.

## T9 — Test environment wiring
```php
// config/bundles.php
DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true],
```
```yaml
# config/packages/dama_doctrine_test_bundle.yaml
when@test:
    dama_doctrine_test:
        enable_static_connection: true
        enable_static_meta_data_cache: true
        enable_static_query_cache: true
```
```xml
<!-- phpunit.xml.dist -->
<extensions>
    <bootstrap class="Symfony\Bridge\PhpUnit\SymfonyExtension"/>
    <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
</extensions>
```
```yaml
# config/packages/messenger.yaml
when@test:
    framework:
        messenger:
            transports:
                async: 'in-memory://'
                failed: 'in-memory://'
```
Schema for tests (run inside container): `bin/console --env=test doctrine:database:create --if-not-exists`,
`bin/console --env=test doctrine:migrations:migrate -n`.
