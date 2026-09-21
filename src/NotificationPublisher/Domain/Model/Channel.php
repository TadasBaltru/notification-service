<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function recipientField(): string
    {
        return match ($this) {
            self::Email => 'email',
            self::Sms => 'phone',
        };
    }
}
