<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Application\Configuration;

use App\NotificationPublisher\Application\Configuration\ChannelConfiguration;
use App\NotificationPublisher\Application\Exception\InvalidChannelConfiguration;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use PHPUnit\Framework\TestCase;

final class ChannelConfigurationTest extends TestCase
{
    private const DELIVERY = '01990a2f-0000-7000-8000-000000000010';

    public function test_it_rejects_an_unknown_channel(): void
    {
        $this->expectException(InvalidChannelConfiguration::class);
        $this->expectExceptionMessage('push');

        ChannelConfiguration::fromArray([
            'push' => ['enabled' => true, 'strategy' => 'priority', 'providers' => ['fake_push']],
        ]);
    }

    public function test_it_rejects_an_unknown_strategy(): void
    {
        $this->expectException(InvalidChannelConfiguration::class);
        $this->expectExceptionMessage('random');

        ChannelConfiguration::fromArray([
            'sms' => ['enabled' => true, 'strategy' => 'random', 'providers' => ['fake_sms']],
        ]);
    }

    public function test_it_rejects_an_enabled_channel_with_no_providers(): void
    {
        $this->expectException(InvalidChannelConfiguration::class);
        $this->expectExceptionMessage('no providers');

        ChannelConfiguration::fromArray([
            'sms' => ['enabled' => true, 'strategy' => 'round_robin', 'providers' => []],
        ]);
    }

    public function test_it_rejects_unknown_provider_name(): void
    {
        $this->expectException(InvalidChannelConfiguration::class);
        $this->expectExceptionMessage('fake_smss');

        ChannelConfiguration::fromArray([
            'sms' => ['enabled' => true, 'strategy' => 'round_robin', 'providers' => ['fake_smss']],
        ], ['fake_sms']);
    }

    public function test_a_disabled_channel_may_have_an_empty_provider_list(): void
    {
        $config = ChannelConfiguration::fromArray([
            'sms' => ['enabled' => false, 'strategy' => 'round_robin', 'providers' => []],
            'email' => ['enabled' => true, 'strategy' => 'priority', 'providers' => ['fake_email']],
        ], ['fake_email']);

        self::assertFalse($config->isEnabled(Channel::Sms));
        self::assertTrue($config->isEnabled(Channel::Email));
    }

    public function test_priority_keeps_the_configured_order(): void
    {
        $config = ChannelConfiguration::fromArray([
            'email' => ['enabled' => true, 'strategy' => 'priority', 'providers' => ['fake_email', 'smtp']],
        ]);

        self::assertSame(
            ['fake_email', 'smtp'],
            $config->providersFor(Channel::Email, DeliveryId::fromString(self::DELIVERY)),
        );
    }

    public function test_round_robin_is_a_rotation_of_the_configured_list(): void
    {
        $config = ChannelConfiguration::fromArray([
            'sms' => ['enabled' => true, 'strategy' => 'round_robin', 'providers' => ['fake_sms', 'twilio']],
        ]);
        $id = DeliveryId::fromString(self::DELIVERY);

        $order = $config->providersFor(Channel::Sms, $id);

        self::assertSame($order, $config->providersFor(Channel::Sms, $id));
        self::assertEqualsCanonicalizing(['fake_sms', 'twilio'], $order);
    }
}
