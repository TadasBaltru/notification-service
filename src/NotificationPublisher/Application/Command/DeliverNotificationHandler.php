<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Command;

use App\NotificationPublisher\Application\Delivery\FailoverDeliveryStrategy;
use App\NotificationPublisher\Application\Exception\DeliveryRequiresRetry;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Port\DeliveryRepository;
use App\NotificationPublisher\Domain\Port\DeliveryThrottle;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler(bus: 'delivery.bus')]
final readonly class DeliverNotificationHandler
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private FailoverDeliveryStrategy $strategy,
        private DeliveryThrottle $throttle,
        private ClockInterface $clock,
        #[Target('deliveryBus')]
        private MessageBusInterface $deliveryBus,
    ) {}

    public function __invoke(DeliverNotification $message): void
    {
        $delivery = $this->deliveries->get(DeliveryId::fromString($message->deliveryId));
        if ($delivery->isFinal()) {
            return;
        }

        if ($delivery->notification()->requiresUserAction()) {
            $decision = $this->throttle->decide($delivery);
            if ($decision->isThrottled()) {
                $delivery->markThrottled($this->clock->now());
                $this->deliveries->save($delivery);
                $this->deliveryBus->dispatch($message, [new DelayStamp($decision->retryAfterMs())]);

                return;
            }
        }

        $result = $this->strategy->deliver($delivery, fn() => $this->deliveries->save($delivery));
        $this->deliveries->save($delivery);

        if ($result->succeeded()) {
            return;
        }

        if ($result->isPermanent()) {
            throw new UnrecoverableMessageHandlingException($result->reason());
        }

        throw new DeliveryRequiresRetry($result->reason());
    }
}
