<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http\Exception;

final class MissingHandledResult extends \RuntimeException
{
    public static function for(string $type): self
    {
        return new self(\sprintf('Message bus did not return a %s result', $type));
    }
}
