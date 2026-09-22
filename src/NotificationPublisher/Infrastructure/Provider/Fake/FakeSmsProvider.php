<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Provider\Fake;

use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\OutboundMessage;
use App\NotificationPublisher\Domain\Model\ProviderResult;
use App\NotificationPublisher\Domain\Port\NotificationProvider;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTaggedItem(index: 'fake_sms')]
final class FakeSmsProvider implements NotificationProvider
{
    /** @var list<OutboundMessage> */
    private array $sent = [];

    private string $name = 'fake_sms';

    private ?\Closure $beforeSend = null;

    public function __construct(
        #[Autowire('%env(FAKE_SMS_MODE)%')]
        private string $mode,
    ) {}

    public static function withMode(FakeMode $mode, string $name = 'fake_sms'): self
    {
        $provider = new self($mode->value);
        $provider->name = $name;

        return $provider;
    }

    public function beforeSend(\Closure $beforeSend): self
    {
        $this->beforeSend = $beforeSend;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function channel(): Channel
    {
        return Channel::Sms;
    }

    public function send(OutboundMessage $message): ProviderResult
    {
        if (null !== $this->beforeSend) {
            ($this->beforeSend)();
        }

        $failure = FakeMode::from($this->mode)->failure($this->name(), 'invalid_number');
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
