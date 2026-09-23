<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Application\Command;

use App\NotificationPublisher\Application\Command\DeliverNotification;
use App\NotificationPublisher\Application\Command\SendNotification;
use App\NotificationPublisher\Application\Command\SendNotificationHandler;
use App\NotificationPublisher\Application\Configuration\ChannelConfiguration;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\DeliveryStatus;
use App\NotificationPublisher\Infrastructure\Identity\InMemoryRecipientResolver;
use App\Tests\Support\InMemoryNotificationRepository;
use App\Tests\Support\RecordingMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SendNotificationHandlerTest extends TestCase
{
    public function test_a_disabled_channel_is_stored_as_skipped(): void
    {
        $bus = new RecordingMessageBus();
        $handler = new SendNotificationHandler(
            new InMemoryNotificationRepository(),
            new InMemoryRecipientResolver([
                'user-1' => ['email' => 'user1@example.test', 'phone' => '+37060000001'],
            ]),
            ChannelConfiguration::fromArray([
                'email' => ['enabled' => true, 'strategy' => 'priority', 'providers' => ['fake_email']],
                'sms' => ['enabled' => false, 'strategy' => 'round_robin', 'providers' => ['fake_sms']],
            ]),
            new MockClock(new \DateTimeImmutable('2026-01-01 10:00:00 UTC')),
            $bus,
        );

        $result = $handler(new SendNotification('user-1', 'key-skip', ['email', 'sms'], 'Hi', 'Body'));

        $email = null;
        $sms = null;
        foreach ($result->notification->deliveries() as $delivery) {
            if (Channel::Email === $delivery->channel()) {
                $email = $delivery->status();
            }
            if (Channel::Sms === $delivery->channel()) {
                $sms = $delivery->status();
            }
        }

        self::assertTrue($result->created);
        self::assertSame(DeliveryStatus::Pending, $email);
        self::assertSame(DeliveryStatus::Skipped, $sms);
        self::assertCount(1, $bus->messages);
        $queued = $bus->messages[0];
        self::assertInstanceOf(DeliverNotification::class, $queued);
        $pending = null;
        foreach ($result->notification->deliveries() as $delivery) {
            if ($delivery->isPending()) {
                $pending = $delivery->id()->value;
            }
        }
        self::assertSame($pending, $queued->deliveryId);
    }
}
