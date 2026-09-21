<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http\OpenApi;

use OpenApi\Attributes as OA;

/**
 * RFC 7807 problem details. The JSON exception listener (phase 1.3) must emit this shape.
 */
#[OA\Schema(
    schema: 'Problem',
    required: ['type', 'title', 'status'],
    properties: [
        new OA\Property(property: 'type', type: 'string', format: 'uri', example: 'about:blank'),
        new OA\Property(property: 'title', type: 'string', example: 'Unknown user'),
        new OA\Property(property: 'status', type: 'integer', example: 422),
        new OA\Property(property: 'detail', type: 'string', example: 'No contact for channel sms', nullable: true),
        new OA\Property(
            property: 'violations',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                required: ['propertyPath', 'message'],
                properties: [
                    new OA\Property(property: 'propertyPath', type: 'string'),
                    new OA\Property(property: 'message', type: 'string'),
                ],
            ),
        ),
    ],
)]
final readonly class ProblemSchema {}
