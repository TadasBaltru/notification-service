<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Exception;

use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\NotificationId;

final class ChannelAlreadyRequested extends DomainException
{
    public static function for(NotificationId $id, Channel $channel): self
    {
        return new self(\sprintf('Channel %s already requested for notification %s', $channel->value, $id->value));
    }
}
