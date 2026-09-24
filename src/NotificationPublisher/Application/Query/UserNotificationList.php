<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Query;

use App\NotificationPublisher\Domain\Model\Notification;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class UserNotificationList
{
    /** @param list<Notification> $notifications */
    public function __construct(public array $notifications) {}
}
