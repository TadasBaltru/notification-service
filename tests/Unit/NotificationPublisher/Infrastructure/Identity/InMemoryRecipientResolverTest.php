<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Infrastructure\Identity;

use App\NotificationPublisher\Domain\Exception\ChannelContactNotFound;
use App\NotificationPublisher\Domain\Exception\UnknownUser;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\UserId;
use App\NotificationPublisher\Infrastructure\Identity\InMemoryRecipientResolver;
use PHPUnit\Framework\TestCase;

final class InMemoryRecipientResolverTest extends TestCase
{
    public function test_it_resolves_email_and_phone_for_a_known_user(): void
    {
        $resolver = $this->resolver();

        self::assertSame(
            'user1@example.test',
            $resolver->resolve(UserId::fromString('user-1'), Channel::Email)->address(),
        );
        self::assertSame(
            '+37060000001',
            $resolver->resolve(UserId::fromString('user-1'), Channel::Sms)->address(),
        );
    }

    public function test_it_rejects_an_unknown_user(): void
    {
        $this->expectException(UnknownUser::class);

        $this->resolver()->resolve(UserId::fromString('ghost'), Channel::Email);
    }

    public function test_it_rejects_a_known_user_without_a_phone(): void
    {
        $resolver = $this->resolver();

        try {
            $resolver->resolve(UserId::fromString('user-email-only'), Channel::Sms);
            self::fail('Expected ChannelContactNotFound');
        } catch (ChannelContactNotFound $e) {
            self::assertSame(Channel::Sms, $e->channel);
            self::assertStringContainsString('sms', $e->getMessage());
        }
    }

    private function resolver(): InMemoryRecipientResolver
    {
        return new InMemoryRecipientResolver([
            'user-1' => ['email' => 'user1@example.test', 'phone' => '+37060000001'],
            'user-email-only' => ['email' => 'email-only@example.test'],
        ]);
    }
}
