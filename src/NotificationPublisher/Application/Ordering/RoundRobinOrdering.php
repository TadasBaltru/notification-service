<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Ordering;

use App\NotificationPublisher\Domain\Model\DeliveryId;

final readonly class RoundRobinOrdering implements ProviderOrdering
{
    public function order(array $providers, DeliveryId $deliveryId): array
    {
        $providers = array_values($providers);
        $count = \count($providers);
        if ($count < 2) {
            return $providers;
        }

        // crc32 is stable across processes; the mask keeps the start index non-negative.
        $start = (crc32($deliveryId->value) & 0x7FFFFFFF) % $count;

        return [
            ...\array_slice($providers, $start),
            ...\array_slice($providers, 0, $start),
        ];
    }
}
