<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

final class PermanentProviderFailure extends ProviderFailure
{
    public function __construct(
        string $provider,
        ?string $errorCode,
        string $message,
        public readonly bool $recipientLevel,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($provider, $errorCode, $message, $previous);
    }

    public static function recipient(string $provider, string $code, string $message = ''): self
    {
        return new self(
            $provider,
            $code,
            '' !== $message ? $message : \sprintf('Recipient rejected by %s (%s)', $provider, $code),
            true,
        );
    }

    public static function provider(string $provider, int $status): self
    {
        return new self(
            $provider,
            (string) $status,
            \sprintf('Provider %s rejected the request with HTTP %d', $provider, $status),
            false,
        );
    }
}
