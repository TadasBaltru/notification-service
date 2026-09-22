<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Ordering;

use App\NotificationPublisher\Domain\Model\DeliveryId;

interface ProviderOrdering
{
    /**
     * @param list<string> $providers
     *
     * @return list<string>
     */
    public function order(array $providers, DeliveryId $deliveryId): array;
}
