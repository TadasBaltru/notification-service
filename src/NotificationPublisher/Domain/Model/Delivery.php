<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\AttemptAlreadyInProgress;
use App\NotificationPublisher\Domain\Exception\DeliveryAlreadyFinal;
use App\NotificationPublisher\Domain\Exception\ProviderFailure;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

final class Delivery
{
    /** @var Collection<int, DeliveryAttempt> */
    private Collection $attempts;
    private DeliveryStatus $status;
    private int $attemptsCount = 0;
    private ?string $sentViaProvider = null;
    private ?string $providerMessageId = null;
    private ?\DateTimeImmutable $sentAt = null;
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        private readonly DeliveryId $id,
        private readonly Notification $notification,
        private readonly Channel $channel,
        private readonly Recipient $recipient,
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
