<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http;

use App\NotificationPublisher\Domain\Model\Delivery;
use App\NotificationPublisher\Domain\Model\DeliveryStatus;
use App\NotificationPublisher\Domain\Model\Notification;

final readonly class NotificationPresenter
{
    /**
     * @return array{id: string, status: string, deliveries: list<array{id: string, channel: string, status: string, recipient: string}>}
     */
    public function present(Notification $notification): array
    {
        $deliveries = [];
        foreach ($notification->deliveries() as $delivery) {
            $deliveries[] = $this->presentDelivery($delivery);
        }

        return [
            'id' => $notification->id()->value,
            'status' => $this->status($notification),
            'deliveries' => $deliveries,
        ];
    }

    /**
     * @return array{id: string, channel: string, status: string, recipient: string}
     */
    private function presentDelivery(Delivery $delivery): array
    {
        return [
            'id' => $delivery->id()->value,
            'channel' => $delivery->channel()->value,
            'status' => $delivery->status()->value,
            'recipient' => $delivery->recipient()->address(),
        ];
    }

    private function status(Notification $notification): string
    {
        $statuses = [];
        foreach ($notification->deliveries() as $delivery) {
            $statuses[] = $delivery->status();
        }
        if ([] === $statuses) {
            return DeliveryStatus::Pending->value;
        }

        $unique = array_values(array_unique($statuses, \SORT_REGULAR));
        if (1 === \count($unique)) {
            return $unique[0]->value;
        }

        foreach ($statuses as $status) {
            if (!$status->isFinal()) {
                return DeliveryStatus::Pending->value;
            }
        }

        return 'partial';
    }
}
