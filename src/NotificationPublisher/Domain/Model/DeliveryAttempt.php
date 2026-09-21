<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\AttemptNotInProgress;
use App\NotificationPublisher\Domain\Exception\InvalidAttemptOutcome;
use App\NotificationPublisher\Domain\Exception\ProviderFailure;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'notification_delivery_attempts')]
final class DeliveryAttempt
{
    #[ORM\Column(length: 32, enumType: AttemptOutcome::class)]
    private AttemptOutcome $outcome;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerMessageId = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'delivery_attempt_id')]
        private readonly DeliveryAttemptId $id,
        #[ORM\ManyToOne(targetEntity: Delivery::class, inversedBy: 'attempts')]
        #[ORM\JoinColumn(name: 'delivery_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private readonly Delivery $delivery,
        #[ORM\Column(length: 64)]
        private readonly string $provider,
        #[ORM\Column(type: 'datetime_immutable')]
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
