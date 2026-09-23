<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Command;

final readonly class DeliverNotification
{
    public function __construct(public string $deliveryId) {}
}
