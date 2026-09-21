<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Model\IdempotencyKey;
use App\NotificationPublisher\Domain\Model\Notification;
use App\NotificationPublisher\Domain\Model\NotificationContent;
use App\NotificationPublisher\Domain\Model\NotificationId;
use App\NotificationPublisher\Domain\Model\Recipient;
use App\NotificationPublisher\Domain\Model\UserId;

final class NotificationBuilder
{
    public const NOW = DeliveryBuilder::NOW;

    private string $id = '01990a2f-0000-7000-8000-000000000101';
    private string $userId = 'user-1';
    private string $idempotencyKey = 'key-roundtrip';
    /** @var list<Channel> */
    private array $channels = [Channel::Email, Channel::Sms];

    public static function aNotification(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function withIdempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    public function withChannels(Channel ...$channels): self
    {
        $this->channels = array_values($channels);

        return $this;
    }

    public function build(): Notification
    {
        $now = new \DateTimeImmutable(self::NOW);
        $notification = Notification::request(
            NotificationId::fromString($this->id),
            UserId::fromString($this->userId),
            IdempotencyKey::fromString($this->idempotencyKey),
            NotificationContent::fromStrings('Hi', 'Body'),
            false,
            $now,
        );

        $suffix = hexdec(substr($this->id, -12));
        foreach ($this->channels as $offset => $channel) {
            $notification->addDelivery(
                DeliveryId::fromString(substr($this->id, 0, 24) . \sprintf('%012x', $suffix + $offset + 1)),
                $channel,
                Recipient::fromString(Channel::Email === $channel ? 'user@example.test' : '+37060000001'),
                $now,
            );
        }

        return $notification;
    }
}
