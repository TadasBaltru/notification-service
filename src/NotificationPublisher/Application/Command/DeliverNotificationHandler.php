<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Command;

use App\NotificationPublisher\Application\Delivery\FailoverDeliveryStrategy;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Port\DeliveryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler(bus: 'delivery.bus')]
final readonly class DeliverNotificationHandler
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private FailoverDeliveryStrategy $strategy,
    ) {}

    public function __invoke(DeliverNotification $message): void
    {
        $delivery = $this->deliveries->get(DeliveryId::fromString($message->deliveryId));
        if ($delivery->isFinal()) {
            return;
        }

        $result = $this->strategy->deliver($delivery, fn() => $this->deliveries->save($delivery));
        $this->deliveries->save($delivery);

        if ($result->succeeded()) {
            return;
        }

        if ($result->isPermanent()) {
            throw new UnrecoverableMessageHandlingException($result->reason());
        }

        throw new RecoverableMessageHandlingException($result->reason());
    }
}
