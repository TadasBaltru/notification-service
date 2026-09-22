<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Infrastructure\Provider;

use App\NotificationPublisher\Application\Exception\UnknownProvider;
use App\NotificationPublisher\Infrastructure\Provider\Fake\FakeEmailProvider;
use App\NotificationPublisher\Infrastructure\Provider\Fake\FakeMode;
use App\NotificationPublisher\Infrastructure\Provider\Fake\FakeSmsProvider;
use App\NotificationPublisher\Infrastructure\Provider\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    public function test_it_returns_a_provider_by_name(): void
    {
        $sms = FakeSmsProvider::withMode(FakeMode::Success);
        $email = FakeEmailProvider::withMode(FakeMode::Success);
        $registry = ProviderRegistry::fromList([$sms, $email]);

        self::assertSame($sms, $registry->get('fake_sms'));
        self::assertSame($email, $registry->get('fake_email'));
        self::assertSame(['fake_sms', 'fake_email'], $registry->names());
    }

    public function test_it_rejects_an_unknown_name(): void
    {
        $registry = ProviderRegistry::fromList([FakeSmsProvider::withMode(FakeMode::Success)]);

        $this->expectException(UnknownProvider::class);
        $this->expectExceptionMessage('twilio');

        $registry->get('twilio');
    }
}
