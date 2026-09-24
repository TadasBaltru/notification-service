<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http\OpenApi;

use OpenApi\Attributes as OA;

final readonly class UserNotificationSchema
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'user-1')]
        public string $userId,
        #[OA\Property(example: 'pending')]
        public string $status,
        #[OA\Property(format: 'date-time')]
        public string $createdAt,
        #[OA\Property(example: 'Hello')]
        public string $subject,
    ) {}
}
