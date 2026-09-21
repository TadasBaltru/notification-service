<?php

declare(strict_types=1);

namespace App\Tests\Integration\NotificationPublisher\Infrastructure\Persistence;

use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\Delivery;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Model\Notification;
use App\NotificationPublisher\Domain\Model\Recipient;
use App\NotificationPublisher\Domain\Port\DeliveryRepository;
use App\NotificationPublisher\Domain\Port\NotificationRepository;
use App\Tests\Support\DeliveryBuilder;
use App\Tests\Support\NotificationBuilder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineNotificationRepositoryTest extends KernelTestCase
{
    public function test_it_persists_and_reloads_a_notification_with_two_deliveries(): void
    {
        $notifications = $this->notifications();
        $deliveries = $this->deliveries();
        $entityManager = $this->entityManager();

        $notification = NotificationBuilder::aNotification()
            ->withChannels(Channel::Email, Channel::Sms)
            ->build();
        $emailId = self::deliveryId($notification, Channel::Email);
        $smsId = self::deliveryId($notification, Channel::Sms);

        $notifications->save($notification);
        $entityManager->clear();

        $reloaded = $notifications->get($notification->id());
        self::assertTrue($reloaded->id()->equals($notification->id()));
        self::assertTrue($reloaded->userId()->equals($notification->userId()));
        self::assertTrue($reloaded->idempotencyKey()->equals($notification->idempotencyKey()));
        self::assertTrue($reloaded->content()->equals($notification->content()));
        self::assertCount(2, $reloaded->deliveries());
        $byKey = $notifications->findByIdempotencyKey($notification->idempotencyKey());
        self::assertNotNull($byKey);
        self::assertTrue($byKey->id()->equals($notification->id()));
        self::assertTrue(self::deliveryId($reloaded, Channel::Email)->equals($emailId));
        self::assertTrue(self::deliveryId($reloaded, Channel::Sms)->equals($smsId));
        self::assertTrue($deliveries->get($emailId)->id()->equals($emailId));
    }

    public function test_it_enforces_unique_idempotency_key(): void
    {
        $notifications = $this->notifications();
        $notifications->save(
            NotificationBuilder::aNotification()
                ->withId('01990a2f-0000-7000-8000-000000000201')
                ->withIdempotencyKey('same-key')
                ->withChannels(Channel::Email)
                ->build(),
        );

        $this->expectException(UniqueConstraintViolationException::class);

        $notifications->save(
            NotificationBuilder::aNotification()
                ->withId('01990a2f-0000-7000-8000-000000000211')
                ->withIdempotencyKey('same-key')
                ->withChannels(Channel::Sms)
                ->build(),
        );
    }

    public function test_it_enforces_unique_notification_and_channel(): void
    {
        $notifications = $this->notifications();
        $deliveries = $this->deliveries();
        $entityManager = $this->entityManager();

        $notification = NotificationBuilder::aNotification()
            ->withId('01990a2f-0000-7000-8000-000000000301')
            ->withIdempotencyKey('channel-unique')
            ->withChannels(Channel::Sms)
            ->build();
        $notifications->save($notification);
        $entityManager->clear();

        $duplicate = Delivery::pending(
            DeliveryId::fromString('01990a2f-0000-7000-8000-000000000399'),
            $notifications->get($notification->id()),
            Channel::Sms,
            Recipient::fromString('+37060000099'),
            new \DateTimeImmutable(DeliveryBuilder::NOW),
        );

        $this->expectException(UniqueConstraintViolationException::class);

        $deliveries->save($duplicate);
    }

    private function notifications(): NotificationRepository
    {
        /** @var NotificationRepository $repository */
        $repository = self::getContainer()->get(NotificationRepository::class);

        return $repository;
    }

    private function deliveries(): DeliveryRepository
    {
        /** @var DeliveryRepository $repository */
        $repository = self::getContainer()->get(DeliveryRepository::class);

        return $repository;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    private static function deliveryId(Notification $notification, Channel $channel): DeliveryId
    {
        foreach ($notification->deliveries() as $delivery) {
            if ($channel === $delivery->channel()) {
                return $delivery->id();
            }
        }

        self::fail(\sprintf('Notification has no %s delivery', $channel->value));
    }
}
