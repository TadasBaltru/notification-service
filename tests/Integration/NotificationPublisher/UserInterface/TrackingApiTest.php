<?php

declare(strict_types=1);

namespace App\Tests\Integration\NotificationPublisher\UserInterface;

use App\NotificationPublisher\Domain\Port\NotificationRepository;
use App\Tests\Support\DeliveryPipeline;
use App\Tests\Support\OpenApiAssertions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TrackingApiTest extends WebTestCase
{
    use OpenApiAssertions;

    public function test_it_lists_nothing_when_the_user_has_no_notifications(): void
    {
        $client = self::createClient();

        $client->request('GET', '/users/user-empty/notifications');

        self::assertResponseStatusCodeSame(200);
        $this->assertResponseIsDocumented($client);
        self::assertSame(['notifications' => []], $this->json($client));
    }

    public function test_it_lists_one_notification_for_the_user(): void
    {
        $client = self::createClient();
        $id = $this->post($client, 'track-one', 'user-1');

        $client->request('GET', '/users/user-1/notifications');

        self::assertResponseStatusCodeSame(200);
        $this->assertResponseIsDocumented($client);
        $body = $this->json($client);
        self::assertCount(1, $body['notifications']);
        self::assertIsArray($body['notifications'][0]);
        self::assertSame($id, $body['notifications'][0]['id']);
        self::assertSame('user-1', $body['notifications'][0]['userId']);
        self::assertSame('pending', $body['notifications'][0]['status']);
        self::assertSame('Hello', $body['notifications'][0]['subject']);
        self::assertIsString($body['notifications'][0]['createdAt']);
    }

    public function test_it_filters_the_list_to_notifications_created_since(): void
    {
        $client = self::createClient();
        $this->post($client, 'track-since', 'user-1');

        $client->request('GET', '/users/user-1/notifications?since=2099-01-01T00:00:00Z');

        self::assertResponseStatusCodeSame(200);
        $this->assertResponseIsDocumented($client);
        self::assertSame(['notifications' => []], $this->json($client));

        $client->request('GET', '/users/user-1/notifications?since=2000-01-01T00:00:00Z');

        self::assertResponseStatusCodeSame(200);
        $this->assertResponseIsDocumented($client);
        self::assertCount(1, $this->json($client)['notifications']);
    }

    public function test_it_returns_400_when_since_is_not_a_date_time(): void
    {
        $client = self::createClient();

        $client->request('GET', '/users/user-1/notifications?since=not-a-date');

        self::assertResponseStatusCodeSame(400);
        $this->assertResponseIsDocumented($client);
        $body = $this->json($client);
        self::assertSame(400, $body['status']);
        self::assertSame('Bad Request', $body['title']);
    }

    public function test_it_returns_a_notification_with_no_attempts_yet(): void
    {
        $client = self::createClient();
        $id = $this->post($client, 'track-empty-attempts', 'user-1');

        $client->request('GET', '/notifications/' . $id);

        self::assertResponseStatusCodeSame(200);
        $this->assertResponseIsDocumented($client);
        $body = $this->json($client);
        self::assertSame('user-1', $body['userId']);
        self::assertSame('Hello', $body['subject']);
        self::assertSame('Track me.', $body['body']);
        self::assertIsArray($body['deliveries'][0]);
        self::assertSame('email', $body['deliveries'][0]['channel']);
        self::assertNull($body['deliveries'][0]['provider']);
        self::assertSame([], $body['deliveries'][0]['attempts']);
    }

    public function test_it_includes_what_when_channel_provider_and_user_after_delivery(): void
    {
        $client = self::createClient();
        $pipeline = new DeliveryPipeline($client, static function (string $id): object {
            $service = match ($id) {
                'messenger.transport.async' => self::getContainer()->get('messenger.transport.async'),
                'delivery.bus' => self::getContainer()->get('delivery.bus'),
                EntityManagerInterface::class => self::getContainer()->get(EntityManagerInterface::class),
                NotificationRepository::class => self::getContainer()->get(NotificationRepository::class),
                default => self::fail(\sprintf('Unknown service "%s".', $id)),
            };
            self::assertIsObject($service);

            return $service;
        });

        $id = $pipeline->accept([
            'userId' => 'user-1',
            'idempotencyKey' => 'track-sent',
            'channels' => ['email'],
            'subject' => 'Hello',
            'body' => 'Track me.',
        ]);
        $this->assertResponseIsDocumented($client);
        self::assertNull($pipeline->drain());

        $client->request('GET', '/notifications/' . $id);

        self::assertResponseStatusCodeSame(200);
        $this->assertResponseIsDocumented($client);
        $body = $this->json($client);
        self::assertSame('user-1', $body['userId']);
        self::assertSame('Hello', $body['subject']);
        self::assertSame('sent', $body['status']);
        self::assertIsArray($body['deliveries'][0]);
        $delivery = $body['deliveries'][0];
        self::assertSame('email', $delivery['channel']);
        self::assertSame('smtp', $delivery['provider']);
        self::assertIsString($delivery['sentAt']);
        self::assertIsArray($delivery['attempts'][0]);
        $attempt = $delivery['attempts'][0];
        self::assertSame('smtp', $attempt['provider']);
        self::assertSame('succeeded', $attempt['outcome']);
        self::assertIsString($attempt['startedAt']);
        self::assertIsString($attempt['finishedAt']);
        self::assertNull($attempt['errorCode']);
    }

    private function post(KernelBrowser $client, string $idempotencyKey, string $userId): string
    {
        $client->request(
            'POST',
            '/notifications',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'userId' => $userId,
                'idempotencyKey' => $idempotencyKey,
                'channels' => ['email'],
                'subject' => 'Hello',
                'body' => 'Track me.',
            ], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(202);
        $this->assertResponseIsDocumented($client);
        $id = $this->json($client)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
