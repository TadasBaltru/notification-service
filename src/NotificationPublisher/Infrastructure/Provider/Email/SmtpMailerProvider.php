<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Provider\Email;

use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\OutboundMessage;
use App\NotificationPublisher\Domain\Model\ProviderResult;
use App\NotificationPublisher\Domain\Port\NotificationProvider;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

#[AsTaggedItem(index: 'smtp')]
final readonly class SmtpMailerProvider implements NotificationProvider
{
    public function __construct(
        private TransportInterface $transport,
        private SmtpFailureClassifier $failures,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $from,
    ) {}

    public function name(): string
    {
        return 'smtp';
    }

    public function channel(): Channel
    {
        return Channel::Email;
    }

    public function send(OutboundMessage $message): ProviderResult
    {
        $messageId = \sprintf('%s@notifications.local', $message->deliveryId->value);
        $email = (new Email())
            ->from($this->from)
            ->to($message->recipient->address())
            ->subject($message->content->subject())
            ->text($message->content->body());
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);

        try {
            $this->transport->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->failures->classify($this->name(), $exception);
        }

        return ProviderResult::accepted(\sprintf('<%s>', $messageId));
    }
}
