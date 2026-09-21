<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\UserId;

final class ChannelContactNotFound extends DomainException
{
    private function __construct(
        public readonly Channel $channel,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(UserId $userId, Channel $channel): self
    {
        return new self(
            $channel,
            \sprintf('User "%s" has no contact for channel %s', $userId->value, $channel->value),
        );
    }
}
