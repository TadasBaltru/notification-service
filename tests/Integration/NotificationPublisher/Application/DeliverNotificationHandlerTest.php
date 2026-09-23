<?php

declare(strict_types=1);

namespace App\Tests\Integration\NotificationPublisher\Application;

use App\NotificationPublisher\Application\Command\DeliverNotification;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Model\DeliveryStatus;
use App\NotificationPublisher\Domain\Port\DeliveryRepository;
use App\NotificationPublisher\Domain\Port\NotificationRepository;
use App\Tests\Support\NotificationBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class DeliverNotificationHandlerTest extends KernelTestCase
{
    public function test_it_does_not_add_an_attempt_when_the_delivery_is_already_sent(): void
    {
        self::bootKernel();

        $notifications = self::getContainer()->get(NotificationRepository::class);
        self::assertInstanceOf(NotificationRepository::class, $notifications);
        $notification = NotificationBuilder::aNotification()
            ->withChannels(Channel::Email)
            ->withIdempotencyKey('already-sent')
            ->build();
        $delivery = $notification->deliveries()[0];
        $delivery->markSent('fake_email', 'msg-1', new \DateTimeImmutable(NotificationBuilder::NOW));
        $deliveryId = $delivery->id()->value;
        $notifications->save($notification);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $bus = self::getContainer()->get('delivery.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $envelope = $bus->dispatch(new DeliverNotification($deliveryId), [new ReceivedStamp('async')]);

        self::assertInstanceOf(HandledStamp::class, $envelope->last(HandledStamp::class));
        $deliveries = self::getContainer()->get(DeliveryRepository::class);
        self::assertInstanceOf(DeliveryRepository::class, $deliveries);
        $reloaded = $deliveries->get(DeliveryId::fromString($deliveryId));
        self::assertSame(DeliveryStatus::Sent, $reloaded->status());
        self::assertSame([], $reloaded->attempts());
    }

    public function test_it_sends_a_pending_delivery(): void
    {
        self::bootKernel();

        $notifications = self::getContainer()->get(NotificationRepository::class);
        self::assertInstanceOf(NotificationRepository::class, $notifications);
        $notification = NotificationBuilder::aNotification()
            ->withChannels(Channel::Email)
            ->withIdempotencyKey('pending-send')
            ->build();
        $deliveryId = $notification->deliveries()[0]->id()->value;
        $notifications->save($notification);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $bus = self::getContainer()->get('delivery.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new DeliverNotification($deliveryId), [new ReceivedStamp('async')]);

        $entityManager->clear();
        $deliveries = self::getContainer()->get(DeliveryRepository::class);
        self::assertInstanceOf(DeliveryRepository::class, $deliveries);
        $reloaded = $deliveries->get(DeliveryId::fromString($deliveryId));
        self::assertSame(DeliveryStatus::Sent, $reloaded->status());
        self::assertSame('smtp', $reloaded->sentViaProvider());
        self::assertCount(1, $reloaded->attempts());
    }
}
