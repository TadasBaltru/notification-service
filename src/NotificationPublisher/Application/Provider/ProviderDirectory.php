<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Provider;

use App\NotificationPublisher\Domain\Port\NotificationProvider;

interface ProviderDirectory
{
    public function get(string $name): NotificationProvider;
}
