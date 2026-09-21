<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Model\DeliveryStatus;

final class DeliveryAlreadyFinal extends DomainException
{
    public static function for(DeliveryId $id, DeliveryStatus $status): self
    {
        return new self(\sprintf('Delivery %s is already %s', $id->value, $status->value));
    }
}
