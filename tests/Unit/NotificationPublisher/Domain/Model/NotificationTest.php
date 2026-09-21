<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\ChannelAlreadyRequested;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Model\IdempotencyKey;
use App\NotificationPublisher\Domain\Model\Notification;
use App\NotificationPublisher\Domain\Model\NotificationContent;
use App\NotificationPublisher\Domain\Model\NotificationId;
use App\NotificationPublisher\Domain\Model\Recipient;
use App\NotificationPublisher\Domain\Model\UserId;
use App\Tests\Support\DeliveryBuilder;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    public function test_it_expands_one_delivery_per_requested_channel(): void
    {
        $notification = $this->aNotification();
        $now = new \DateTimeImmutable(DeliveryBuilder::NOW);

        $notification->addDelivery(
            DeliveryId::fromString('01990a2f-0000-7000-8000-000000000010'),
            Channel::Email,
            Recipient::fromString('user@example.test'),
            $now,
        );
        $notification->addDelivery(
            DeliveryId::fromString('01990a2f-0000-7000-8000-000000000011'),
            Channel::Sms,
            Recipient::fromString('+37060000001'),
            $now,
        );

        self::assertCount(2, $notification->deliveries());
        self::assertSame(Channel::Email, $notification->deliveries()[0]->channel());
        self::assertSame(Channel::Sms, $notification->deliveries()[1]->channel());
        self::assertTrue($notification->deliveries()[0]->isPending());
        self::assertTrue($notification->deliveries()[1]->isPending());
    }

    public function test_it_rejects_a_duplicate_channel(): void
    {
        $notification = $this->aNotification();
        $now = new \DateTimeImmutable(DeliveryBuilder::NOW);
        $notification->addDelivery(
            DeliveryId::fromString('01990a2f-0000-7000-8000-000000000010'),
            Channel::Email,
            Recipient::fromString('user@example.test'),
            $now,
        );

        $this->expectException(ChannelAlreadyRequested::class);

        $notification->addDelivery(
            DeliveryId::fromString('01990a2f-0000-7000-8000-000000000011'),
            Channel::Email,
            Recipient::fromString('other@example.test'),
            $now,
        );
    }

    private function aNotification(): Notification
    {
        return Notification::request(
            NotificationId::fromString(DeliveryBuilder::NOTIFICATION_ID),
            UserId::fromString('user-1'),
            IdempotencyKey::fromString('key-1'),
            NotificationContent::fromStrings('Hi', 'Body'),
            false,
            new \DateTimeImmutable(DeliveryBuilder::NOW),
        );
    }
}
