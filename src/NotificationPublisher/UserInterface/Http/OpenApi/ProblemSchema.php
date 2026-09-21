<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http\OpenApi;

use OpenApi\Attributes as OA;

/**
 * RFC 7807 problem details. The JSON exception listener (phase 1.3) must emit this shape.
 */
final readonly class ProblemSchema
{
    /**
     * @param list<ProblemViolationSchema> $violations
     */
    public function __construct(
        #[OA\Property(format: 'uri', example: 'about:blank')]
        public string $type,
        #[OA\Property(example: 'Unknown user')]
        public string $title,
        #[OA\Property(example: 422)]
        public int $status,
        #[OA\Property(example: 'No contact for channel sms', nullable: true)]
        public ?string $detail = null,
        public array $violations = [],
    ) {}
}
