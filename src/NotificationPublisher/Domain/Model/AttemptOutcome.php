<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

enum AttemptOutcome: string
{
    case InProgress = 'in_progress';
    case Succeeded = 'succeeded';
    case TransientFailure = 'transient_failure';
    case PermanentFailure = 'permanent_failure';
    case Unknown = 'unknown';

    public function isFailure(): bool
    {
        return match ($this) {
            self::TransientFailure, self::PermanentFailure, self::Unknown => true,
            self::InProgress, self::Succeeded => false,
        };
    }
}
