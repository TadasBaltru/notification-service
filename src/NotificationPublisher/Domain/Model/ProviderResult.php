<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

final readonly class ProviderResult
{
    private function __construct(public string $providerMessageId) {}

    public static function accepted(string $providerMessageId): self
    {
        return new self($providerMessageId);
    }
}
