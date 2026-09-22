<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Delivery;

final readonly class DeliveryResult
{
    private function __construct(
        private bool $succeeded,
        private bool $permanent,
        private ?string $provider,
        private string $reason,
    ) {}

    public static function sent(string $provider): self
    {
        return new self(true, false, $provider, '');
    }

    public static function retryLater(string $reason): self
    {
        return new self(false, false, null, $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(false, true, null, $reason);
    }

    public function succeeded(): bool
    {
        return $this->succeeded;
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
