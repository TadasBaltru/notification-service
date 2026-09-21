<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

final class UnknownProviderOutcome extends ProviderFailure
{
    public function __construct(string $provider, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($provider, null, $message, $previous);
    }
}
