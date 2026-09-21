<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

use App\NotificationPublisher\Domain\Model\NotificationId;

final class NotificationNotFound extends DomainException
{
    public static function withId(NotificationId $id): self
    {
        return new self(\sprintf('Notification %s was not found', $id->value));
    }
}
