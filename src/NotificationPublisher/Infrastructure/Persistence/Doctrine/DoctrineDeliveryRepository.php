<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Persistence\Doctrine;

use App\NotificationPublisher\Domain\Exception\DeliveryNotFound;
use App\NotificationPublisher\Domain\Model\Delivery;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Port\DeliveryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DeliveryRepository::class, public: true)]
final readonly class DoctrineDeliveryRepository implements DeliveryRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function save(Delivery $delivery): void
    {
        $this->entityManager->persist($delivery);
        $this->entityManager->flush();
    }

    public function get(DeliveryId $id): Delivery
    {
        $delivery = $this->entityManager->find(Delivery::class, $id);
        if (!$delivery instanceof Delivery) {
            throw DeliveryNotFound::withId($id);
        }

        return $delivery;
    }
}
