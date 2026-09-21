<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Command;

use App\NotificationPublisher\Domain\Model\Notification;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class SendNotificationResult
{
    public function __construct(
        public Notification $notification,
        public bool $created,
    ) {}
}
