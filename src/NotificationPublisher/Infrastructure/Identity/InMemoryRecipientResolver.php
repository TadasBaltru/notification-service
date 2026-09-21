<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Identity;

use App\NotificationPublisher\Domain\Exception\ChannelContactNotFound;
use App\NotificationPublisher\Domain\Exception\UnknownUser;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\Recipient;
use App\NotificationPublisher\Domain\Model\UserId;
use App\NotificationPublisher\Domain\Port\RecipientResolver;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(RecipientResolver::class)]
final readonly class InMemoryRecipientResolver implements RecipientResolver
{
    /**
     * @param array<string, array<string, mixed>> $recipients
     */
    public function __construct(
        #[Autowire('%notifications.recipients%')]
        private array $recipients,
    ) {}

    public function resolve(UserId $userId, Channel $channel): Recipient
    {
        if (!\array_key_exists($userId->value, $this->recipients)) {
            throw UnknownUser::withId($userId);
        }

        $contacts = $this->recipients[$userId->value];
        $field = $channel->recipientField();
        $address = $contacts[$field] ?? null;
        if (!\is_string($address) || '' === trim($address)) {
            throw ChannelContactNotFound::for($userId, $channel);
        }

        return Recipient::fromString($address);
    }
}
