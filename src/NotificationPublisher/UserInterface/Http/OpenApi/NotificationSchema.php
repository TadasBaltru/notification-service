<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http\OpenApi;

use OpenApi\Attributes as OA;

final readonly class NotificationSchema
{
    /**
     * @param list<DeliverySchema> $deliveries
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'pending')]
        public string $status,
        public array $deliveries,
    ) {}
}
