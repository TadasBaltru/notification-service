<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\ChannelAlreadyRequested;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

final class Notification
{
    /** @var Collection<int, Delivery> */
    private Collection $deliveries;
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        private readonly NotificationId $id,
        private readonly UserId $userId,
        private readonly IdempotencyKey $idempotencyKey,
        private readonly NotificationContent $content,
        private readonly bool $requiresUserAction,
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->deliveries = new ArrayCollection();
        $this->updatedAt = $createdAt;
    }

    public static function request(
        NotificationId $id,
        UserId $userId,
        IdempotencyKey $idempotencyKey,
        NotificationContent $content,
        bool $requiresUserAction,
        \DateTimeImmutable $now,
    ): self {
        return new self($id, $userId, $idempotencyKey, $content, $requiresUserAction, $now);
    }

    public function id(): NotificationId
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }

    public function content(): NotificationContent
    {
        return $this->content;
    }

    public function requiresUserAction(): bool
    {
        return $this->requiresUserAction;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<Delivery> */
    public function deliveries(): array
    {
        /** @var list<Delivery> $deliveries */
        $deliveries = array_values($this->deliveries->toArray());

        return $deliveries;
    }

    public function addDelivery(
        DeliveryId $id,
        Channel $channel,
        Recipient $recipient,
        \DateTimeImmutable $now,
    ): Delivery {
        foreach ($this->deliveries as $existing) {
            if ($existing->channel() === $channel) {
                throw ChannelAlreadyRequested::for($this->id, $channel);
            }
        }

        $delivery = Delivery::pending($id, $this, $channel, $recipient, $now);
        $this->deliveries->add($delivery);
        $this->updatedAt = $now;

        return $delivery;
    }
}
