<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http\Request;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RecipientOverrideRequest
{
    public function __construct(
        public ?string $email = null,
        public ?string $phone = null,
    ) {}
}
