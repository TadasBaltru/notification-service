<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

use App\NotificationPublisher\Domain\Model\DeliveryId;

final class AttemptAlreadyInProgress extends DomainException
{
    public static function for(DeliveryId $id): self
    {
        return new self(\sprintf('Delivery %s already has an in-progress attempt', $id->value));
    }
}
