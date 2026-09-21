<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

final class InvalidUserId extends DomainException
{
    public static function because(string $reason): self
    {
        return new self(\sprintf('Invalid user id: %s', $reason));
    }
}
