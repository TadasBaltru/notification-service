<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

final class InvalidIdentity extends DomainException
{
    public static function for(string $type, string $value): self
    {
        return new self(\sprintf('Invalid %s "%s"', $type, $value));
    }
}
