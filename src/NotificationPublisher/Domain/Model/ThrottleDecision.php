<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

final readonly class ThrottleDecision
{
    private function __construct(
        private bool $throttled,
        private int $retryAfterMs,
    ) {}

    public static function allow(): self
    {
        return new self(false, 0);
    }

    public static function delay(int $retryAfterMs): self
    {
        return new self(true, $retryAfterMs);
    }

    public function isThrottled(): bool
    {
        return $this->throttled;
    }

    public function retryAfterMs(): int
    {
        return $this->retryAfterMs;
    }
}
