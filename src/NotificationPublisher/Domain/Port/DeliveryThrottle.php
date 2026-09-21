<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Port;

use App\NotificationPublisher\Domain\Model\Delivery;
use App\NotificationPublisher\Domain\Model\ThrottleDecision;

interface DeliveryThrottle
{
    public function decide(Delivery $delivery): ThrottleDecision;
}
