<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Ordering;

use App\NotificationPublisher\Domain\Model\DeliveryId;

final readonly class PriorityOrdering implements ProviderOrdering
{
    public function order(array $providers, DeliveryId $deliveryId): array
    {
        return array_values($providers);
    }
}
