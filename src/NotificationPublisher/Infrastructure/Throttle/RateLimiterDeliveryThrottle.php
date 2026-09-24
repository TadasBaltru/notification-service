<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Throttle;

use App\NotificationPublisher\Domain\Model\Delivery;
use App\NotificationPublisher\Domain\Model\ThrottleDecision;
use App\NotificationPublisher\Domain\Port\DeliveryThrottle;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsAlias(DeliveryThrottle::class)]
final readonly class RateLimiterDeliveryThrottle implements DeliveryThrottle
{
    public function __construct(
        #[Target('perUserNotificationsLimiter')]
        private RateLimiterFactory $limiters,
        private ClockInterface $clock,
    ) {}

    public function decide(Delivery $delivery): ThrottleDecision
    {
        $limit = $this->limiters->create($delivery->notification()->userId()->value)->consume(1);
        if ($limit->isAccepted()) {
            return ThrottleDecision::allow();
        }

        $retryAfterMs = ($limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()) * 1000;

        return ThrottleDecision::delay(max(0, $retryAfterMs));
    }
}
