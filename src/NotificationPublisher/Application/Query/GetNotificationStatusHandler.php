<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Query;

use App\NotificationPublisher\Domain\Model\Notification;
use App\NotificationPublisher\Domain\Model\NotificationId;
use App\NotificationPublisher\Domain\Port\NotificationRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class GetNotificationStatusHandler
{
    public function __construct(private NotificationRepository $notifications) {}

    public function __invoke(GetNotificationStatus $query): Notification
    {
        return $this->notifications->get(NotificationId::fromString($query->id));
    }
}
