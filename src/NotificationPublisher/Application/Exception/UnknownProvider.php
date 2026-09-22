<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Exception;

final class UnknownProvider extends ApplicationException
{
    public static function named(string $name): self
    {
        return new self(\sprintf('Unknown notification provider "%s".', $name));
    }
}
