<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\NotificationPublisher\Domain\Exception\NotificationNotFound;
use App\NotificationPublisher\Domain\Model\IdempotencyKey;
use App\NotificationPublisher\Domain\Model\Notification;
use App\NotificationPublisher\Domain\Model\NotificationId;
use App\NotificationPublisher\Domain\Port\NotificationRepository;

final class InMemoryNotificationRepository implements NotificationRepository
{
    /** @var array<string, Notification> */
    private array $byId = [];

    /** @var array<string, Notification> */
    private array $byKey = [];

    public function save(Notification $notification): void
    {
        $this->byId[$notification->id()->value] = $notification;
        $this->byKey[$notification->idempotencyKey()->value] = $notification;
    }

    public function get(NotificationId $id): Notification
    {
        return $this->byId[$id->value] ?? throw NotificationNotFound::withId($id);
    }

    public function findByIdempotencyKey(IdempotencyKey $key): ?Notification
    {
        return $this->byKey[$key->value] ?? null;
    }
}
