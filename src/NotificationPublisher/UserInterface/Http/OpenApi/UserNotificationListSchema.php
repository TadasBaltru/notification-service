<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http\OpenApi;

final readonly class UserNotificationListSchema
{
    /**
     * @param list<UserNotificationSchema> $notifications
     */
    public function __construct(
        public array $notifications,
    ) {}
}
