<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Persistence\Doctrine;

use App\NotificationPublisher\Domain\Exception\NotificationNotFound;
use App\NotificationPublisher\Domain\Model\IdempotencyKey;
use App\NotificationPublisher\Domain\Model\Notification;
use App\NotificationPublisher\Domain\Model\NotificationId;
use App\NotificationPublisher\Domain\Model\UserId;
use App\NotificationPublisher\Domain\Port\NotificationRepository;
use App\NotificationPublisher\Infrastructure\Persistence\Doctrine\Type\UserIdType;
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

    public function findByUser(UserId $userId, ?\DateTimeImmutable $since): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('notification')
            ->from(Notification::class, 'notification')
            ->where('notification.userId = :userId')
            ->setParameter('userId', $userId, UserIdType::NAME)
            ->orderBy('notification.createdAt', \SortDirection::Descending)
            ->addOrderBy('notification.id', \SortDirection::Descending);

        if (null !== $since) {
            $builder->andWhere('notification.createdAt >= :since')
                ->setParameter('since', $since);
        }

        /** @var list<Notification> $notifications */
        $notifications = $builder->getQuery()->getResult();

        return $notifications;
    }
}
