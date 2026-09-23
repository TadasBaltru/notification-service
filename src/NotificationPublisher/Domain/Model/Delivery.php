<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\AttemptAlreadyInProgress;
use App\NotificationPublisher\Domain\Exception\DeliveryAlreadyFinal;
use App\NotificationPublisher\Domain\Exception\ProviderFailure;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'notification_deliveries')]
#[ORM\UniqueConstraint(name: 'uniq_delivery_notification_channel', columns: ['notification_id', 'channel'])]
final class Delivery
{
    /** @var Collection<int, DeliveryAttempt> */
    #[ORM\OneToMany(targetEntity: DeliveryAttempt::class, mappedBy: 'delivery', cascade: ['persist'], orphanRemoval: true)]
    private Collection $attempts;

    #[ORM\Column(length: 20, enumType: DeliveryStatus::class)]
    private DeliveryStatus $status;

    #[ORM\Column]
    private int $attemptsCount = 0;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sentViaProvider = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerMessageId = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'delivery_id')]
        private readonly DeliveryId $id,
        // EAGER: a lazy Notification is created with its id already set, and hydrating it
        // again tries to overwrite that readonly property (native lazy objects).
        #[ORM\ManyToOne(targetEntity: Notification::class, inversedBy: 'deliveries', fetch: 'EAGER')]
        #[ORM\JoinColumn(name: 'notification_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private readonly Notification $notification,
        #[ORM\Column(length: 16, enumType: Channel::class)]
        private readonly Channel $channel,
        #[ORM\Column(type: 'recipient', length: 255)]
        private readonly Recipient $recipient,
        #[ORM\Column(type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->status = DeliveryStatus::Pending;
        $this->updatedAt = $createdAt;
        $this->attempts = new ArrayCollection();
    }

    public static function pending(
        DeliveryId $id,
        Notification $notification,
        Channel $channel,
        Recipient $recipient,
        \DateTimeImmutable $now,
    ): self {
        return new self($id, $notification, $channel, $recipient, $now);
    }

    public function id(): DeliveryId
    {
        return $this->id;
    }

    public function notification(): Notification
    {
        return $this->notification;
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function recipient(): Recipient
    {
        return $this->recipient;
    }

    public function status(): DeliveryStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function sentViaProvider(): ?string
    {
        return $this->sentViaProvider;
    }

    public function providerMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function sentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function attemptsCount(): int
    {
        return $this->attemptsCount;
    }

    /** @return list<DeliveryAttempt> */
    public function attempts(): array
    {
        /** @var list<DeliveryAttempt> $attempts */
        $attempts = array_values($this->attempts->toArray());

        return $attempts;
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function isPending(): bool
    {
        return DeliveryStatus::Pending === $this->status;
    }

    public function toOutboundMessage(): OutboundMessage
    {
        return new OutboundMessage($this->id, $this->channel, $this->recipient, $this->notification->content());
    }

    public function markSent(string $provider, string $providerMessageId, \DateTimeImmutable $sentAt): void
    {
        $this->assertMutable();
        $this->status = DeliveryStatus::Sent;
        $this->sentViaProvider = $provider;
        $this->providerMessageId = $providerMessageId;
        $this->sentAt = $sentAt;
        $this->updatedAt = $sentAt;
    }

    public function markFailed(\DateTimeImmutable $now): void
    {
        $this->assertMutable();
        $this->status = DeliveryStatus::Failed;
        $this->updatedAt = $now;
    }

    public function markSkipped(\DateTimeImmutable $now): void
    {
        $this->assertMutable();
        $this->status = DeliveryStatus::Skipped;
        $this->updatedAt = $now;
    }

    public function markThrottled(\DateTimeImmutable $now): void
    {
        $this->assertMutable();
        $this->status = DeliveryStatus::Throttled;
        $this->updatedAt = $now;
    }

    public function startAttempt(DeliveryAttemptId $id, string $provider, \DateTimeImmutable $now): DeliveryAttempt
    {
        $this->assertMutable();
        if (null !== $this->inProgressAttempt()) {
            throw AttemptAlreadyInProgress::for($this->id);
        }

        $attempt = DeliveryAttempt::start($id, $this, $provider, $now);
        $this->attempts->add($attempt);
        ++$this->attemptsCount;
        $this->updatedAt = $now;

        return $attempt;
    }

    public function completeAttempt(DeliveryAttempt $attempt, ProviderResult $result, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        $attempt->succeed($result, $now);
        $this->markSent($attempt->provider(), $result->providerMessageId, $now);
    }

    public function failAttempt(
        DeliveryAttempt $attempt,
        AttemptOutcome $outcome,
        ProviderFailure $failure,
        \DateTimeImmutable $now,
    ): void {
        $this->assertMutable();
        $attempt->fail($outcome, $failure, $now);
        $this->updatedAt = $now;
    }

    private function assertMutable(): void
    {
        if ($this->status->isFinal()) {
            throw DeliveryAlreadyFinal::for($this->id, $this->status);
        }
    }

    private function inProgressAttempt(): ?DeliveryAttempt
    {
        foreach ($this->attempts as $attempt) {
            if (AttemptOutcome::InProgress === $attempt->outcome()) {
                return $attempt;
            }
        }

        return null;
    }
}
