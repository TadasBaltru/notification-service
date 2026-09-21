<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Persistence\Doctrine;

use App\NotificationPublisher\Domain\Exception\NotificationNotFound;
use App\NotificationPublisher\Domain\Model\IdempotencyKey;
use App\NotificationPublisher\Domain\Model\Notification;
use App\NotificationPublisher\Domain\Model\NotificationId;
use App\NotificationPublisher\Domain\Port\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(NotificationRepository::class, public: true)]
final readonly class DoctrineNotificationRepository implements NotificationRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function save(Notification $notification): void
    {
        $this->entityManager->persist($notification);
        $this->entityManager->flush();
    }

    public function get(NotificationId $id): Notification
    {
        $notification = $this->entityManager->find(Notification::class, $id);
        if (!$notification instanceof Notification) {
            throw NotificationNotFound::withId($id);
        }

        return $notification;
    }

    public function findByIdempotencyKey(IdempotencyKey $key): ?Notification
    {
        return $this->entityManager->getRepository(Notification::class)->findOneBy([
            'idempotencyKey' => $key,
        ]);
    }
}
