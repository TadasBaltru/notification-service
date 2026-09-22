<?php

declare(strict_types=1);

namespace App\Tests\Integration\NotificationPublisher\Infrastructure\Provider\Email;

use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Model\NotificationContent;
use App\NotificationPublisher\Domain\Model\OutboundMessage;
use App\NotificationPublisher\Domain\Model\Recipient;
use App\NotificationPublisher\Infrastructure\Provider\Email\SmtpMailerProvider;
use App\Tests\Support\DeliveryBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

final class SmtpMailerProviderTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    public function test_it_sends_an_email_with_a_deterministic_message_id(): void
    {
        self::bootKernel();
        /** @var SmtpMailerProvider $provider */
        $provider = self::getContainer()->get(SmtpMailerProvider::class);
        $message = $this->email();
        $messageId = \sprintf('<%s@notifications.local>', DeliveryBuilder::DELIVERY_ID);

        $first = $provider->send($message);
        $second = $provider->send($message);

        self::assertSame($messageId, $first->providerMessageId);
        self::assertSame($messageId, $second->providerMessageId);
        self::assertEmailCount(2);
        $sent = self::getMailerMessage(0);
        self::assertNotNull($sent);
        self::assertEmailAddressContains($sent, 'To', 'user1@example.test');
        self::assertEmailHeaderSame($sent, 'Subject', 'Hi');
        self::assertEmailHeaderSame($sent, 'Message-ID', $messageId);
        $again = self::getMailerMessage(1);
        self::assertNotNull($again);
        self::assertEmailHeaderSame($again, 'Message-ID', $messageId);
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
