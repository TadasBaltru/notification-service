<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Infrastructure\Provider\Fake;

use App\NotificationPublisher\Domain\Exception\PermanentProviderFailure;
use App\NotificationPublisher\Domain\Exception\TransientProviderFailure;
use App\NotificationPublisher\Domain\Exception\UnknownProviderOutcome;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Model\NotificationContent;
use App\NotificationPublisher\Domain\Model\OutboundMessage;
use App\NotificationPublisher\Domain\Model\Recipient;
use App\NotificationPublisher\Infrastructure\Provider\Fake\FakeEmailProvider;
use App\NotificationPublisher\Infrastructure\Provider\Fake\FakeMode;
use App\NotificationPublisher\Infrastructure\Provider\Fake\FakeSmsProvider;
use App\Tests\Support\DeliveryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FakeProviderTest extends TestCase
{
    #[DataProvider('modes')]
    public function test_each_mode_throws_the_matching_failure(FakeMode $mode, string $exception, bool $recipientLevel): void
    {
        $provider = FakeSmsProvider::withMode($mode);

        try {
            $provider->send($this->sms());
            self::fail('Expected ' . $exception);
        } catch (PermanentProviderFailure $e) {
            self::assertSame($exception, $e::class);
            self::assertSame($recipientLevel, $e->recipientLevel);
            self::assertSame('fake_sms', $e->provider);
        } catch (TransientProviderFailure|UnknownProviderOutcome $e) {
            self::assertSame($exception, $e::class);
            self::assertSame('fake_sms', $e->provider);
        }

        self::assertSame(0, $provider->sentCount());
    }

    public function test_success_records_the_outbound_message(): void
    {
        $provider = FakeSmsProvider::withMode(FakeMode::Success);
        $message = $this->sms();

        $result = $provider->send($message);

        self::assertSame('fake_sms:' . $message->deliveryId->value, $result->providerMessageId);
        self::assertSame(1, $provider->sentCount());
        self::assertSame([$message], $provider->sentMessages());
    }

    public function test_email_success_and_recipient_failure_use_the_email_provider_name(): void
    {
        $sent = FakeEmailProvider::withMode(FakeMode::Success)->send($this->email());
        self::assertStringStartsWith('fake_email:', $sent->providerMessageId);

        try {
            FakeEmailProvider::withMode(FakeMode::PermanentRecipient)->send($this->email());
            self::fail('Expected PermanentProviderFailure');
        } catch (PermanentProviderFailure $e) {
            self::assertTrue($e->recipientLevel);
            self::assertSame('invalid_address', $e->errorCode);
            self::assertSame('fake_email', $e->provider);
        }
    }

    /** @return iterable<string, array{FakeMode, class-string, bool}> */
    public static function modes(): iterable
    {
        yield 'transient' => [FakeMode::Transient, TransientProviderFailure::class, false];
        yield 'permanent recipient' => [FakeMode::PermanentRecipient, PermanentProviderFailure::class, true];
        yield 'permanent provider' => [FakeMode::PermanentProvider, PermanentProviderFailure::class, false];
        yield 'timeout' => [FakeMode::Timeout, UnknownProviderOutcome::class, false];
    }

    private function sms(): OutboundMessage
    {
        return new OutboundMessage(
            DeliveryId::fromString(DeliveryBuilder::DELIVERY_ID),
            Channel::Sms,
            Recipient::fromString('+37060000001'),
            NotificationContent::fromStrings('Hi', 'Body'),
        );
    }

    private function email(): OutboundMessage
    {
        return new OutboundMessage(
            DeliveryId::fromString(DeliveryBuilder::DELIVERY_ID),
            Channel::Email,
            Recipient::fromString('user@example.test'),
            NotificationContent::fromStrings('Hi', 'Body'),
        );
    }
}
