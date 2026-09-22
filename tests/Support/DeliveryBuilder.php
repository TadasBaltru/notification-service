<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\Delivery;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Model\IdempotencyKey;
use App\NotificationPublisher\Domain\Model\Notification;
use App\NotificationPublisher\Domain\Model\NotificationContent;
use App\NotificationPublisher\Domain\Model\NotificationId;
use App\NotificationPublisher\Domain\Model\Recipient;
use App\NotificationPublisher\Domain\Model\UserId;

final class DeliveryBuilder
{
    public const NOW = '2026-01-01 10:00:00 UTC';
    public const NOTIFICATION_ID = '01990a2f-0000-7000-8000-000000000001';
    public const DELIVERY_ID = '01990a2f-0000-7000-8000-000000000002';
    public const ATTEMPT_ID = '01990a2f-0000-7000-8000-000000000003';

    private Channel $channel = Channel::Sms;
    private string $deliveryId = self::DELIVERY_ID;
    private ?string $markSentVia = null;
    private bool $markFailed = false;
    private bool $markSkipped = false;
    private bool $markThrottled = false;

    public static function aDelivery(): self
    {
        return new self();
    }

    public static function aPendingSmsDelivery(): self
    {
        return new self();
    }

    public function sent(): self
    {
        $this->markSentVia = 'twilio';

        return $this;
    }

    public function failed(): self
    {
        $this->markFailed = true;

        return $this;
    }

    public function skipped(): self
    {
        $this->markSkipped = true;

        return $this;
    }

    public function throttled(): self
    {
        $this->markThrottled = true;

        return $this;
    }

    public function withId(string $deliveryId): self
    {
        $this->deliveryId = $deliveryId;

        return $this;
    }

    public function build(): Delivery
    {
        $now = new \DateTimeImmutable(self::NOW);
        $notification = Notification::request(
            NotificationId::fromString(self::NOTIFICATION_ID),
            UserId::fromString('user-1'),
            IdempotencyKey::fromString('key-1'),
            NotificationContent::fromStrings('Hi', 'Body'),
            false,
            $now,
        );
        $delivery = $notification->addDelivery(
            DeliveryId::fromString($this->deliveryId),
            $this->channel,
            Recipient::fromString('+37060000001'),
            $now,
        );

        if (null !== $this->markSentVia) {
            $delivery->markSent($this->markSentVia, 'SM123', $now);
        }
        if ($this->markFailed) {
            $delivery->markFailed($now);
        }
        if ($this->markSkipped) {
            $delivery->markSkipped($now);
        }
        if ($this->markThrottled) {
            $delivery->markThrottled($now);
        }

        return $delivery;
    }
}
