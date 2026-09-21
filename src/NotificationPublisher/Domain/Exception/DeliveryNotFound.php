<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

use App\NotificationPublisher\Domain\Model\DeliveryId;

final class DeliveryNotFound extends DomainException
{
    public static function withId(DeliveryId $id): self
    {
        return new self(\sprintf('Delivery %s was not found', $id->value));
    }
}
