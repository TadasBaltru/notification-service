<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

final class TransientProviderFailure extends ProviderFailure
{
    public static function fromStatus(string $provider, int $status): self
    {
        return new self(
            $provider,
            (string) $status,
            \sprintf('Transient failure from %s (HTTP %d)', $provider, $status),
        );
    }
}
