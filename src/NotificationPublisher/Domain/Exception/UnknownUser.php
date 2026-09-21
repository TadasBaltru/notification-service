<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

use App\NotificationPublisher\Domain\Model\UserId;

final class UnknownUser extends DomainException
{
    public static function withId(UserId $userId): self
    {
        return new self(\sprintf('Unknown user "%s"', $userId->value));
    }
}
