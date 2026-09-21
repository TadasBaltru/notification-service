<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Command;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class SendNotification
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        public string $userId,
        public string $idempotencyKey,
        public array $channels,
        public string $subject,
        public string $body,
        public bool $requiresUserAction = false,
        public ?string $recipientEmail = null,
        public ?string $recipientPhone = null,
    ) {}
}
