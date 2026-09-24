<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Infrastructure\Throttle;

use App\NotificationPublisher\Infrastructure\Throttle\RateLimiterDeliveryThrottle;
use App\Tests\Support\DeliveryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RateLimiterDeliveryThrottleTest extends TestCase
{
    protected function tearDown(): void
    {
        ClockMock::withClockMock(null);
    }

    public function test_it_throttles_the_fourth_decision_until_the_window_passes(): void
    {
        $clock = new MockClock(DeliveryBuilder::NOW);
        $this->follow($clock);
        $throttle = new RateLimiterDeliveryThrottle(
            new RateLimiterFactory([
                'id' => 'per_user_notifications',
                'policy' => 'sliding_window',
                'limit' => 3,
                'interval' => '1 hour',
            ], new InMemoryStorage()),
            $clock,
        );
        $delivery = DeliveryBuilder::aPendingSmsDelivery()->build();

        self::assertFalse($throttle->decide($delivery)->isThrottled());
        self::assertFalse($throttle->decide($delivery)->isThrottled());
        self::assertFalse($throttle->decide($delivery)->isThrottled());
        $blocked = $throttle->decide($delivery);

        self::assertTrue($blocked->isThrottled());
        self::assertGreaterThan(0, $blocked->retryAfterMs());
        self::assertLessThanOrEqual(3_600_000, $blocked->retryAfterMs());

        // windowEndAt is the start plus one hour, and SlidingWindow::isExpired() is a strict ">".
        $clock->modify('+1 hour +1 second');
        $this->follow($clock);

        self::assertFalse($throttle->decide($delivery)->isThrottled());
    }

    private function follow(MockClock $clock): void
    {
        ClockMock::withClockMock((float) $clock->now()->format('U.u'));
    }
}
