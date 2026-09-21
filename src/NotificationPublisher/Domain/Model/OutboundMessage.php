<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

final readonly class OutboundMessage
{
    public function __construct(
        public DeliveryId $deliveryId,
        public Channel $channel,
        public Recipient $recipient,
        public NotificationContent $content,
    ) {}
}
