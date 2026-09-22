<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Delivery;

use App\NotificationPublisher\Application\Configuration\ChannelConfiguration;
use App\NotificationPublisher\Application\Provider\ProviderDirectory;
use App\NotificationPublisher\Domain\Exception\DeliveryAlreadyFinal;
use App\NotificationPublisher\Domain\Exception\PermanentProviderFailure;
use App\NotificationPublisher\Domain\Exception\TransientProviderFailure;
use App\NotificationPublisher\Domain\Exception\UnknownProviderOutcome;
use App\NotificationPublisher\Domain\Model\AttemptOutcome;
use App\NotificationPublisher\Domain\Model\Delivery;
use App\NotificationPublisher\Domain\Model\DeliveryAttemptId;
use App\NotificationPublisher\Domain\Model\DeliveryStatus;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class FailoverDeliveryStrategy
{
    public function __construct(
        private ChannelConfiguration $channels,
        private ProviderDirectory $providers,
        private ClockInterface $clock,
    ) {}

    public function deliver(Delivery $delivery): DeliveryResult
    {
        $this->assertDeliverable($delivery);

        $providerFailovers = 0;
        $lastProviderFailure = null;

        foreach ($this->channels->providersFor($delivery->channel(), $delivery->id()) as $name) {
            $lastProviderFailure = null;
            $provider = $this->providers->get($name);
            $attempt = $delivery->startAttempt(
                DeliveryAttemptId::fromString(Uuid::v7()->toRfc4122()),
                $name,
                $this->clock->now(),
            );

            try {
                $receipt = $provider->send($delivery->toOutboundMessage());
                $delivery->completeAttempt($attempt, $receipt, $this->clock->now());

                return DeliveryResult::sent($name);
            } catch (TransientProviderFailure $e) {
                $delivery->failAttempt($attempt, AttemptOutcome::TransientFailure, $e, $this->clock->now());
            } catch (UnknownProviderOutcome $e) {
                $delivery->failAttempt($attempt, AttemptOutcome::Unknown, $e, $this->clock->now());

                return DeliveryResult::retryLater($e->getMessage());
            } catch (PermanentProviderFailure $e) {
                $delivery->failAttempt($attempt, AttemptOutcome::PermanentFailure, $e, $this->clock->now());
                if ($e->recipientLevel || $providerFailovers >= 1) {
                    $delivery->markFailed($this->clock->now());

                    return DeliveryResult::failed($e->getMessage());
                }

                ++$providerFailovers;
                $lastProviderFailure = $e;
            }
        }

        if ($lastProviderFailure instanceof PermanentProviderFailure) {
            $delivery->markFailed($this->clock->now());

            return DeliveryResult::failed($lastProviderFailure->getMessage());
        }

        return DeliveryResult::retryLater('All providers failed transiently');
    }

    private function assertDeliverable(Delivery $delivery): void
    {
        if (DeliveryStatus::Pending !== $delivery->status() && DeliveryStatus::Throttled !== $delivery->status()) {
            throw DeliveryAlreadyFinal::for($delivery->id(), $delivery->status());
        }
    }
}
