<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Port;

use App\NotificationPublisher\Domain\Exception\DeliveryNotFound;
use App\NotificationPublisher\Domain\Model\Delivery;
use App\NotificationPublisher\Domain\Model\DeliveryId;

interface DeliveryRepository
{
    public function save(Delivery $delivery): void;

    /** @throws DeliveryNotFound */
    public function get(DeliveryId $id): Delivery;
}
