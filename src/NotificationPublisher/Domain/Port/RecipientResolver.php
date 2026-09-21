<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Port;

use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\Recipient;
use App\NotificationPublisher\Domain\Model\UserId;

interface RecipientResolver
{
    public function resolve(UserId $userId, Channel $channel): Recipient;
}
