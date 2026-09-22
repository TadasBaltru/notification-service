<?php

declare(strict_types=1);

namespace App\Tests\Integration\NotificationPublisher\Infrastructure\Provider;

use App\NotificationPublisher\Infrastructure\Provider\Email\SmtpMailerProvider;
use App\NotificationPublisher\Infrastructure\Provider\Fake\FakeEmailProvider;
use App\NotificationPublisher\Infrastructure\Provider\Fake\FakeSmsProvider;
use App\NotificationPublisher\Infrastructure\Provider\ProviderRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProviderRegistryContainerTest extends KernelTestCase
{
    public function test_the_container_indexes_fake_providers_by_their_tag(): void
    {
        self::bootKernel();
        /** @var ProviderRegistry $registry */
        $registry = static::getContainer()->get(ProviderRegistry::class);

        self::assertInstanceOf(FakeSmsProvider::class, $registry->get('fake_sms'));
        self::assertInstanceOf(FakeEmailProvider::class, $registry->get('fake_email'));
        self::assertInstanceOf(SmtpMailerProvider::class, $registry->get('smtp'));
        self::assertEqualsCanonicalizing(['fake_email', 'fake_sms', 'smtp'], $registry->names());
    }
}
