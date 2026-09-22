<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Infrastructure\Provider\Email;

use App\NotificationPublisher\Domain\Exception\TransientProviderFailure;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Model\NotificationContent;
use App\NotificationPublisher\Domain\Model\OutboundMessage;
use App\NotificationPublisher\Domain\Model\Recipient;
use App\NotificationPublisher\Infrastructure\Provider\Email\SmtpFailureClassifier;
use App\NotificationPublisher\Infrastructure\Provider\Email\SmtpMailerProvider;
use App\Tests\Support\DeliveryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final class SmtpMailerProviderTest extends TestCase
{
    public function test_it_classifies_a_transport_exception_from_the_smtp_dialogue(): void
    {
        $exception = new TransportException('connection refused');
        $exception->appendDebug(<<<'LOG'
            [2026-01-01T10:00:00.000000+00:00] > EHLO [127.0.0.1]
            [2026-01-01T10:00:00.000001+00:00] < 421 Service not available
            LOG);
        $provider = new SmtpMailerProvider(new ThrowingTransport($exception), new SmtpFailureClassifier(), 'noreply@notifications.local');

        $this->expectException(TransientProviderFailure::class);
        $this->expectExceptionMessage('before DATA');

        $provider->send($this->email());
    }

    private function email(): OutboundMessage
    {
        return new OutboundMessage(
            DeliveryId::fromString(DeliveryBuilder::DELIVERY_ID),
            Channel::Email,
            Recipient::fromString('user1@example.test'),
            NotificationContent::fromStrings('Hi', 'Hello'),
        );
    }
}

final readonly class ThrowingTransport implements TransportInterface
{
    public function __construct(private TransportException $exception) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw $this->exception;
    }

    public function __toString(): string
    {
        return 'throwing://';
    }
}
