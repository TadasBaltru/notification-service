<?php

declare(strict_types=1);

namespace App\Tests\Integration\NotificationPublisher\UserInterface;

use App\NotificationPublisher\Application\Command\DeliverNotification;
use App\NotificationPublisher\Application\Configuration\ChannelConfiguration;
use App\NotificationPublisher\Domain\Model\DeliveryStatus;
use App\NotificationPublisher\Domain\Model\NotificationId;
use App\NotificationPublisher\Domain\Port\NotificationRepository;
use App\Tests\Support\OpenApiAssertions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class NotificationApiTest extends WebTestCase
{
    use OpenApiAssertions;

    public function test_it_accepts_a_notification_and_returns_202(): void
    {
        $client = self::createClient();

        $payload = $this->postNotification($client, [
            'userId' => 'user-1',
            'idempotencyKey' => 'order-202',
            'channels' => ['email', 'sms'],
            'subject' => 'Hello',
            'body' => 'Your order is confirmed.',
        ]);

        self::assertResponseStatusCodeSame(202);
        $this->assertResponseIsDocumented($client);
        self::assertSame('pending', $payload['status']);
        self::assertCount(2, $payload['deliveries']);

        self::assertIsString($payload['id']);
        $notifications = self::getContainer()->get(NotificationRepository::class);
        self::assertInstanceOf(NotificationRepository::class, $notifications);
        $notification = $notifications->get(NotificationId::fromString($payload['id']));
        self::assertCount(2, $notification->deliveries());
        self::assertTrue($notification->deliveries()[0]->isPending());
        self::assertTrue($notification->deliveries()[1]->isPending());
        self::assertSame(DeliveryStatus::Pending, $notification->deliveries()[0]->status());
    }

    public function test_it_replays_the_same_idempotency_key_with_200(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $body = [
            'userId' => 'user-1',
            'idempotencyKey' => 'order-replay',
            'channels' => ['email'],
            'subject' => 'Hello',
            'body' => 'Replay me.',
        ];

        $first = $this->postNotification($client, $body);
        self::assertResponseStatusCodeSame(202);
        $this->assertResponseIsDocumented($client);

        $second = $this->postNotification($client, $body);
        self::assertResponseStatusCodeSame(200);
        $this->assertResponseIsDocumented($client);
        self::assertSame($first['id'], $second['id']);
    }

    public function test_it_rejects_an_unknown_user_with_422_and_persists_nothing(): void
    {
        $client = self::createClient();

        $this->postNotification($client, [
            'userId' => 'ghost',
            'idempotencyKey' => 'order-unknown-user',
            'channels' => ['email'],
            'subject' => 'Hello',
            'body' => 'Nope.',
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertResponseIsDocumented($client);
        $problem = $this->json($client);
        self::assertSame('Unknown user', $problem['title']);
        self::assertSame(0, $this->notificationCount());
    }

    public function test_it_rejects_a_known_user_without_a_phone_when_sms_is_requested(): void
    {
        $client = self::createClient();

        $this->postNotification($client, [
            'userId' => 'user-email-only',
            'idempotencyKey' => 'order-no-phone',
            'channels' => ['sms'],
            'subject' => 'Hello',
            'body' => 'SMS please.',
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertResponseIsDocumented($client);
        $problem = $this->json($client);
        self::assertStringContainsString('sms', (string) $problem['detail']);
        self::assertSame(0, $this->notificationCount());
    }

    public function test_it_returns_400_for_malformed_json(): void
    {
        $client = self::createClient();
        $client->request('POST', '/notifications', server: ['CONTENT_TYPE' => 'application/json'], content: '{');

        self::assertResponseStatusCodeSame(400);
        $this->assertResponseIsDocumented($client);
    }

    public function test_it_returns_422_when_a_required_field_is_missing(): void
    {
        $client = self::createClient();
        $this->postNotification($client, [
            'idempotencyKey' => 'order-missing-user',
            'channels' => ['email'],
            'subject' => 'Hello',
            'body' => 'Missing userId.',
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertResponseIsDocumented($client);
    }

    public function test_it_returns_404_for_an_unknown_notification_id(): void
    {
        $client = self::createClient();
        $client->request('GET', '/notifications/01990a2f-0000-7000-8000-000000000099');

        self::assertResponseStatusCodeSame(404);
        $this->assertResponseIsDocumented($client);
    }

    public function test_it_returns_status_for_an_accepted_notification(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $created = $this->postNotification($client, [
            'userId' => 'user-1',
            'idempotencyKey' => 'order-status',
            'channels' => ['email'],
            'subject' => 'Hello',
            'body' => 'Status me.',
        ]);
        self::assertResponseStatusCodeSame(202);
        self::assertIsString($created['id']);

        $client->request('GET', '/notifications/' . $created['id']);

        self::assertResponseStatusCodeSame(200);
        $this->assertResponseIsDocumented($client);
        $body = $this->json($client);
        self::assertSame($created['id'], $body['id']);
        self::assertSame('pending', $body['status']);
        self::assertSame('email', $body['deliveries'][0]['channel']);
        self::assertSame('pending', $body['deliveries'][0]['status']);
    }

    public function test_it_queues_one_message_per_pending_delivery(): void
    {
        $client = self::createClient();

        $payload = $this->postNotification($client, [
            'userId' => 'user-1',
            'idempotencyKey' => 'order-queue-two',
            'channels' => ['email', 'sms'],
            'subject' => 'Hello',
            'body' => 'Queue both.',
        ]);

        self::assertResponseStatusCodeSame(202);
        $this->assertResponseIsDocumented($client);
        self::assertIsArray($payload['deliveries']);
        $responseIds = [];
        foreach ($payload['deliveries'] as $delivery) {
            self::assertIsArray($delivery);
            self::assertIsString($delivery['id']);
            $responseIds[] = $delivery['id'];
        }
        $queued = $this->queuedDeliveryIds();
        sort($responseIds);
        sort($queued);
        self::assertSame($responseIds, $queued);
    }

    public function test_it_does_not_queue_a_disabled_channel(): void
    {
        $client = self::createClient();
        self::getContainer()->set(ChannelConfiguration::class, ChannelConfiguration::fromArray([
            'email' => ['enabled' => true, 'strategy' => 'priority', 'providers' => ['smtp', 'fake_email']],
            'sms' => ['enabled' => false, 'strategy' => 'round_robin', 'providers' => ['twilio', 'fake_sms']],
        ]));

        $payload = $this->postNotification($client, [
            'userId' => 'user-1',
            'idempotencyKey' => 'order-queue-disabled',
            'channels' => ['email', 'sms'],
            'subject' => 'Hello',
            'body' => 'Skip sms.',
        ]);

        self::assertResponseStatusCodeSame(202);
        $this->assertResponseIsDocumented($client);
        self::assertIsArray($payload['deliveries']);
        $emailId = null;
        foreach ($payload['deliveries'] as $delivery) {
            self::assertIsArray($delivery);
            if ('email' === $delivery['channel']) {
                self::assertSame('pending', $delivery['status']);
                self::assertIsString($delivery['id']);
                $emailId = $delivery['id'];
            }
            if ('sms' === $delivery['channel']) {
                self::assertSame('skipped', $delivery['status']);
            }
        }

        self::assertSame([$emailId], $this->queuedDeliveryIds());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function postNotification(KernelBrowser $client, array $body): array
    {
        $client->request(
            'POST',
            '/notifications',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        return $this->json($client);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return list<string> */
    private function queuedDeliveryIds(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $ids = [];
        foreach ($transport->getSent() as $envelope) {
            self::assertInstanceOf(Envelope::class, $envelope);
            $message = $envelope->getMessage();
            self::assertInstanceOf(DeliverNotification::class, $message);
            $ids[] = $message->deliveryId;
        }

        return $ids;
    }

    private function notificationCount(): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM notifications');
    }
}
