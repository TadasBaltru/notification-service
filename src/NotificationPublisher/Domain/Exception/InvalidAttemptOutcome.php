<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

use App\NotificationPublisher\Domain\Model\AttemptOutcome;

final class InvalidAttemptOutcome extends DomainException
{
    public static function for(AttemptOutcome $outcome): self
    {
        return new self(\sprintf('Outcome %s cannot fail an attempt', $outcome->value));
    }
}
