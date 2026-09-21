<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

use App\NotificationPublisher\Domain\Model\DeliveryAttemptId;

final class AttemptNotInProgress extends DomainException
{
    public static function for(DeliveryAttemptId $id): self
    {
        return new self(\sprintf('Attempt %s is not in progress', $id->value));
    }
}
