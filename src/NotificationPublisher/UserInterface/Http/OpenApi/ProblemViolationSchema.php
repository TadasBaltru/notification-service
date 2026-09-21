<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http\OpenApi;

final readonly class ProblemViolationSchema
{
    public function __construct(
        public string $propertyPath,
        public string $message,
    ) {}
}
