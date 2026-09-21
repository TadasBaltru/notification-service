<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Port;

use App\NotificationPublisher\Domain\Exception\NotificationNotFound;
use App\NotificationPublisher\Domain\Model\IdempotencyKey;
use App\NotificationPublisher\Domain\Model\Notification;
use App\NotificationPublisher\Domain\Model\NotificationId;

interface NotificationRepository
{
    public function save(Notification $notification): void;

    /** @throws NotificationNotFound */
    public function get(NotificationId $id): Notification;

    public function findByIdempotencyKey(IdempotencyKey $key): ?Notification;
}
