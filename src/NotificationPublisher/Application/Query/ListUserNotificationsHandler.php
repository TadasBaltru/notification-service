<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Query;

use App\NotificationPublisher\Domain\Model\UserId;
use App\NotificationPublisher\Domain\Port\NotificationRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ListUserNotificationsHandler
{
    public function __construct(private NotificationRepository $notifications) {}

    public function __invoke(ListUserNotifications $query): UserNotificationList
    {
        return new UserNotificationList($this->notifications->findByUser(
            UserId::fromString($query->userId),
            $query->since,
        ));
    }
}
