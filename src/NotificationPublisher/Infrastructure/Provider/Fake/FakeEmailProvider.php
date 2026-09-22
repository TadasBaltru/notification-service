<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Provider\Fake;

use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\OutboundMessage;
use App\NotificationPublisher\Domain\Model\ProviderResult;
use App\NotificationPublisher\Domain\Port\NotificationProvider;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTaggedItem(index: 'fake_email')]
final class FakeEmailProvider implements NotificationProvider
{
    /** @var list<OutboundMessage> */
    private array $sent = [];

    public function __construct(
        #[Autowire('%env(FAKE_EMAIL_MODE)%')]
        private string $mode,
    ) {}

    public static function withMode(FakeMode $mode): self
    {
        return new self($mode->value);
    }

    public function name(): string
    {
        return 'fake_email';
    }

    public function channel(): Channel
    {
        return Channel::Email;
    }

    public function send(OutboundMessage $message): ProviderResult
    {
        $failure = FakeMode::from($this->mode)->failure($this->name(), 'invalid_address');
        if (null !== $failure) {
            throw $failure;
        }

        $this->sent[] = $message;

        return ProviderResult::accepted(\sprintf('%s:%s', $this->name(), $message->deliveryId->value));
    }

    public function sentCount(): int
    {
        return \count($this->sent);
    }

    /** @return list<OutboundMessage> */
    public function sentMessages(): array
    {
        return $this->sent;
    }
}
