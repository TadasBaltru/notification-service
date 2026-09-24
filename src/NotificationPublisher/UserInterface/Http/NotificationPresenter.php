<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http;

use App\NotificationPublisher\Domain\Model\Delivery;
use App\NotificationPublisher\Domain\Model\DeliveryAttempt;
use App\NotificationPublisher\Domain\Model\DeliveryStatus;
use App\NotificationPublisher\Domain\Model\Notification;

final readonly class NotificationPresenter
{
    /**
     * @return array{
     *     id: string,
     *     userId: string,
     *     status: string,
     *     createdAt: string,
     *     subject: string,
     *     body: string,
     *     deliveries: list<array{
     *         id: string,
     *         channel: string,
     *         status: string,
     *         recipient: string,
     *         provider: string|null,
     *         sentAt: string|null,
     *         attempts: list<array{
     *             id: string,
     *             provider: string,
     *             outcome: string,
     *             startedAt: string,
     *             finishedAt: string|null,
     *             errorCode: string|null,
     *             errorMessage: string|null,
     *             providerMessageId: string|null
     *         }>
     *     }>
     * }
     */
    public function present(Notification $notification): array
    {
        $deliveries = [];
        foreach ($notification->deliveries() as $delivery) {
            $deliveries[] = $this->presentDelivery($delivery);
        }

        return [
            'id' => $notification->id()->value,
            'userId' => $notification->userId()->value,
            'status' => $this->status($notification),
            'createdAt' => $this->format($notification->createdAt()),
            'subject' => $notification->content()->subject(),
            'body' => $notification->content()->body(),
            'deliveries' => $deliveries,
        ];
    }

    /**
     * @param list<Notification> $notifications
     *
     * @return array{notifications: list<array{id: string, userId: string, status: string, createdAt: string, subject: string}>}
     */
    public function presentList(array $notifications): array
    {
        $items = [];
        foreach ($notifications as $notification) {
            $items[] = [
                'id' => $notification->id()->value,
                'userId' => $notification->userId()->value,
                'status' => $this->status($notification),
                'createdAt' => $this->format($notification->createdAt()),
                'subject' => $notification->content()->subject(),
            ];
        }

        return ['notifications' => $items];
    }

    /**
     * @return array{
     *     id: string,
     *     channel: string,
     *     status: string,
     *     recipient: string,
     *     provider: string|null,
     *     sentAt: string|null,
     *     attempts: list<array{
     *         id: string,
     *         provider: string,
     *         outcome: string,
     *         startedAt: string,
     *         finishedAt: string|null,
     *         errorCode: string|null,
     *         errorMessage: string|null,
     *         providerMessageId: string|null
     *     }>
     * }
     */
    private function presentDelivery(Delivery $delivery): array
    {
        $attempts = [];
        foreach ($delivery->attempts() as $attempt) {
            $attempts[] = $this->presentAttempt($attempt);
        }

        return [
            'id' => $delivery->id()->value,
            'channel' => $delivery->channel()->value,
            'status' => $delivery->status()->value,
            'recipient' => $delivery->recipient()->address(),
            'provider' => $delivery->sentViaProvider(),
            'sentAt' => $this->formatOrNull($delivery->sentAt()),
            'attempts' => $attempts,
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     provider: string,
     *     outcome: string,
     *     startedAt: string,
     *     finishedAt: string|null,
     *     errorCode: string|null,
     *     errorMessage: string|null,
     *     providerMessageId: string|null
     * }
     */
    private function presentAttempt(DeliveryAttempt $attempt): array
    {
        return [
            'id' => $attempt->id()->value,
            'provider' => $attempt->provider(),
            'outcome' => $attempt->outcome()->value,
            'startedAt' => $this->format($attempt->startedAt()),
            'finishedAt' => $this->formatOrNull($attempt->finishedAt()),
            'errorCode' => $attempt->errorCode(),
            'errorMessage' => $attempt->errorMessage(),
            'providerMessageId' => $attempt->providerMessageId(),
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

    private function format(\DateTimeImmutable $instant): string
    {
        return $instant->format(\DateTimeInterface::ATOM);
    }

    private function formatOrNull(?\DateTimeImmutable $instant): ?string
    {
        return null === $instant ? null : $this->format($instant);
    }
}
