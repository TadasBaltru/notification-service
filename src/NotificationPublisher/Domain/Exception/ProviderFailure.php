<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

abstract class ProviderFailure extends DomainException
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
