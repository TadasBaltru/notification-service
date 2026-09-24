<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\ChannelAlreadyRequested;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notifications_user_created', columns: ['user_id', 'created_at'])]
final class Notification
{
    /** @var Collection<int, Delivery> */
    #[ORM\OneToMany(targetEntity: Delivery::class, mappedBy: 'notification', cascade: ['persist'], orphanRemoval: true)]
    private Collection $deliveries;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

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
