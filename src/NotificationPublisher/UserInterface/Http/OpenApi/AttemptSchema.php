<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http\OpenApi;

use OpenApi\Attributes as OA;

final readonly class AttemptSchema
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'smtp')]
        public string $provider,
        #[OA\Property(example: 'succeeded')]
        public string $outcome,
        #[OA\Property(format: 'date-time')]
        public string $startedAt,
        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $finishedAt,
        #[OA\Property(nullable: true, example: 'invalid_address')]
        public ?string $errorCode,
        #[OA\Property(nullable: true)]
        public ?string $errorMessage,
        #[OA\Property(nullable: true)]
        public ?string $providerMessageId,
    ) {}
}
