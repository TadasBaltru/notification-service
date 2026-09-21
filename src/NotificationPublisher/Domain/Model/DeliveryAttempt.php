<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\AttemptNotInProgress;
use App\NotificationPublisher\Domain\Exception\InvalidAttemptOutcome;
use App\NotificationPublisher\Domain\Exception\ProviderFailure;

final class DeliveryAttempt
{
    private AttemptOutcome $outcome;
    private ?\DateTimeImmutable $finishedAt = null;
    private ?string $errorCode = null;
    private ?string $errorMessage = null;
    private ?string $providerMessageId = null;

    private function __construct(
        private readonly DeliveryAttemptId $id,
        private readonly Delivery $delivery,
        private readonly string $provider,
        private readonly \DateTimeImmutable $startedAt,
    ) {
        $this->outcome = AttemptOutcome::InProgress;
    }

    public static function start(
        DeliveryAttemptId $id,
        Delivery $delivery,
        string $provider,
        \DateTimeImmutable $now,
    ): self {
        return new self($id, $delivery, $provider, $now);
    }

    public function id(): DeliveryAttemptId
    {
        return $this->id;
    }

    public function delivery(): Delivery
    {
        return $this->delivery;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function outcome(): AttemptOutcome
    {
        return $this->outcome;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function providerMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function succeed(ProviderResult $result, \DateTimeImmutable $now): void
    {
        $this->assertInProgress();
        $this->outcome = AttemptOutcome::Succeeded;
        $this->providerMessageId = $result->providerMessageId;
        $this->finishedAt = $now;
    }

    public function fail(AttemptOutcome $outcome, ProviderFailure $failure, \DateTimeImmutable $now): void
    {
        $this->assertInProgress();
        if (!$outcome->isFailure()) {
            throw InvalidAttemptOutcome::for($outcome);
        }
        $this->outcome = $outcome;
        $this->errorCode = $failure->errorCode;
        $this->errorMessage = $failure->getMessage();
        $this->finishedAt = $now;
    }

    private function assertInProgress(): void
    {
        if (AttemptOutcome::InProgress !== $this->outcome) {
            throw AttemptNotInProgress::for($this->id);
        }
    }
}
