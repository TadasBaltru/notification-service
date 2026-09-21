<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Command;

use App\NotificationPublisher\Domain\Exception\ChannelContactNotFound;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Model\IdempotencyKey;
use App\NotificationPublisher\Domain\Model\Notification;
use App\NotificationPublisher\Domain\Model\NotificationContent;
use App\NotificationPublisher\Domain\Model\NotificationId;
use App\NotificationPublisher\Domain\Model\Recipient;
use App\NotificationPublisher\Domain\Model\UserId;
use App\NotificationPublisher\Domain\Port\NotificationRepository;
use App\NotificationPublisher\Domain\Port\RecipientResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SendNotificationHandler
{
    public function __construct(
        private NotificationRepository $notifications,
        private RecipientResolver $recipients,
        private ClockInterface $clock,
    ) {}

    public function __invoke(SendNotification $command): SendNotificationResult
    {
        $key = IdempotencyKey::fromString($command->idempotencyKey);
        $existing = $this->notifications->findByIdempotencyKey($key);
        if (null !== $existing) {
            return new SendNotificationResult($existing, false);
        }

        $userId = UserId::fromString($command->userId);
        $now = $this->clock->now();
        /** @var list<array{0: Channel, 1: Recipient}> $resolved */
        $resolved = [];
        foreach ($command->channels as $channelValue) {
            $channel = Channel::from($channelValue);
            $resolved[] = [$channel, $this->recipientFor($command, $userId, $channel)];
        }

        $notification = Notification::request(
            NotificationId::fromString(Uuid::v7()->toRfc4122()),
            $userId,
            $key,
            NotificationContent::fromStrings($command->subject, $command->body),
            $command->requiresUserAction,
            $now,
        );
        foreach ($resolved as [$channel, $recipient]) {
            $notification->addDelivery(
                DeliveryId::fromString(Uuid::v7()->toRfc4122()),
                $channel,
                $recipient,
                $now,
            );
        }

        $this->notifications->save($notification);

        return new SendNotificationResult($notification, true);
    }

    private function recipientFor(SendNotification $command, UserId $userId, Channel $channel): Recipient
    {
        $override = match ($channel) {
            Channel::Email => $command->recipientEmail,
            Channel::Sms => $command->recipientPhone,
        };
        $override = null !== $override ? trim($override) : null;
        if ('' === $override) {
            $override = null;
        }

        try {
            $resolved = $this->recipients->resolve($userId, $channel);
        } catch (ChannelContactNotFound $e) {
            if (null === $override) {
                throw $e;
            }

            return Recipient::fromString($override);
        }

        return null !== $override ? Recipient::fromString($override) : $resolved;
    }
}
