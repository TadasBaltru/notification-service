<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Query;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class GetNotificationStatus
{
    public function __construct(public string $id) {}
}
