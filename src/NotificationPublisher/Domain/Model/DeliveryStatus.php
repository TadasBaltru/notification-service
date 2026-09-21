<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Throttled = 'throttled';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Sent, self::Failed, self::Skipped => true,
            self::Pending, self::Throttled => false,
        };
    }
}
