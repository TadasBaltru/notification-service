<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http\OpenApi;

use OpenApi\Attributes as OA;

final readonly class DeliverySchema
{
    /**
     * @param list<AttemptSchema> $attempts
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(enum: ['email', 'sms'])]
        public string $channel,
        #[OA\Property(example: 'pending')]
        public string $status,
        #[OA\Property(example: 'user1@example.test')]
        public string $recipient,
        #[OA\Property(nullable: true, example: 'smtp')]
        public ?string $provider,
        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $sentAt,
        public array $attempts,
    ) {}
}
