<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Application\Delivery;

use App\NotificationPublisher\Application\Configuration\ChannelConfiguration;
use App\NotificationPublisher\Application\Delivery\FailoverDeliveryStrategy;
use App\NotificationPublisher\Domain\Exception\DeliveryAlreadyFinal;
use App\NotificationPublisher\Domain\Model\AttemptOutcome;
use App\NotificationPublisher\Domain\Model\Delivery;
use App\NotificationPublisher\Domain\Model\DeliveryAttempt;
use App\NotificationPublisher\Domain\Model\DeliveryStatus;
use App\NotificationPublisher\Infrastructure\Provider\Fake\FakeMode;
use App\NotificationPublisher\Infrastructure\Provider\Fake\FakeSmsProvider;
use App\NotificationPublisher\Infrastructure\Provider\ProviderRegistry;
use App\Tests\Support\DeliveryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class FailoverDeliveryStrategyTest extends TestCase
{
    public function test_it_fails_over_to_next_provider_on_transient_failure(): void
    {
        $primary = FakeSmsProvider::withMode(FakeMode::Transient, 'fake_a');
        $secondary = FakeSmsProvider::withMode(FakeMode::Success, 'fake_b');
        $delivery = DeliveryBuilder::aPendingSmsDelivery()->build();

        $result = $this->strategy([$primary, $secondary])->deliver($delivery);

        self::assertTrue($result->succeeded());
        self::assertSame('fake_b', $result->provider());
        self::assertSame(DeliveryStatus::Sent, $delivery->status());
        self::assertSame(
            [AttemptOutcome::TransientFailure, AttemptOutcome::Succeeded],
            $this->outcomes($delivery),
        );
        self::assertSame(['fake_a', 'fake_b'], $this->providers($delivery));
        self::assertSame(0, $primary->sentCount());
        self::assertSame(1, $secondary->sentCount());
    }

    public function test_it_stops_on_a_permanent_recipient_failure(): void
    {
        $primary = FakeSmsProvider::withMode(FakeMode::PermanentRecipient, 'fake_a');
        $secondary = FakeSmsProvider::withMode(FakeMode::Success, 'fake_b');
        $delivery = DeliveryBuilder::aPendingSmsDelivery()->build();

        $result = $this->strategy([$primary, $secondary])->deliver($delivery);

        self::assertTrue($result->isPermanent());
        self::assertFalse($result->succeeded());
        self::assertSame(DeliveryStatus::Failed, $delivery->status());
        self::assertSame([AttemptOutcome::PermanentFailure], $this->outcomes($delivery));
        self::assertSame(['fake_a'], $this->providers($delivery));
        self::assertSame(0, $secondary->sentCount());
    }

    public function test_it_fails_over_only_once_on_a_permanent_provider_failure(): void
    {
        $primary = FakeSmsProvider::withMode(FakeMode::PermanentProvider, 'fake_a');
        $secondary = FakeSmsProvider::withMode(FakeMode::PermanentProvider, 'fake_b');
        $tertiary = FakeSmsProvider::withMode(FakeMode::Success, 'fake_c');
        $delivery = DeliveryBuilder::aPendingSmsDelivery()->build();

        $result = $this->strategy([$primary, $secondary, $tertiary])->deliver($delivery);

        self::assertTrue($result->isPermanent());
        self::assertSame(DeliveryStatus::Failed, $delivery->status());
        self::assertSame(
            [AttemptOutcome::PermanentFailure, AttemptOutcome::PermanentFailure],
            $this->outcomes($delivery),
        );
        self::assertSame(['fake_a', 'fake_b'], $this->providers($delivery));
        self::assertSame(0, $tertiary->sentCount());
    }

    public function test_it_does_not_fail_over_when_outcome_is_unknown(): void
    {
        $primary = FakeSmsProvider::withMode(FakeMode::Timeout, 'fake_a');
        $secondary = FakeSmsProvider::withMode(FakeMode::Success, 'fake_b');
        $delivery = DeliveryBuilder::aPendingSmsDelivery()->build();

        $result = $this->strategy([$primary, $secondary])->deliver($delivery);

        self::assertFalse($result->succeeded());
        self::assertFalse($result->isPermanent());
        self::assertSame(DeliveryStatus::Pending, $delivery->status());
        self::assertSame([AttemptOutcome::Unknown], $this->outcomes($delivery));
        self::assertSame(0, $secondary->sentCount());
    }

    public function test_it_retries_later_when_every_provider_fails_transiently(): void
    {
        $providers = [
            FakeSmsProvider::withMode(FakeMode::Transient, 'fake_a'),
            FakeSmsProvider::withMode(FakeMode::Transient, 'fake_b'),
            FakeSmsProvider::withMode(FakeMode::Transient, 'fake_c'),
        ];
        $delivery = DeliveryBuilder::aPendingSmsDelivery()->build();

        $result = $this->strategy($providers)->deliver($delivery);

        self::assertFalse($result->succeeded());
        self::assertFalse($result->isPermanent());
        self::assertNotSame('', $result->reason());
        self::assertSame(DeliveryStatus::Pending, $delivery->status());
        self::assertCount(3, $delivery->attempts());
        self::assertSame(
            [AttemptOutcome::TransientFailure, AttemptOutcome::TransientFailure, AttemptOutcome::TransientFailure],
            $this->outcomes($delivery),
        );
    }

    public function test_priority_always_starts_at_the_first_provider(): void
    {
        $providers = $this->successes();
        $strategy = $this->strategy($providers, 'priority');
        $first = DeliveryBuilder::aPendingSmsDelivery()->withId($this->deliveryId(1))->build();
        $second = DeliveryBuilder::aPendingSmsDelivery()->withId($this->deliveryId(2))->build();

        $strategy->deliver($first);
        $strategy->deliver($second);

        self::assertSame('fake_a', $first->sentViaProvider());
        self::assertSame('fake_a', $second->sentViaProvider());
        self::assertSame(0, $providers[1]->sentCount());
        self::assertSame(0, $providers[2]->sentCount());
    }

    public function test_round_robin_starts_at_a_rotated_provider(): void
    {
        $seen = [];
        for ($i = 1; $i < 40 && \count($seen) < 2; ++$i) {
            $providers = $this->successes();
            $delivery = DeliveryBuilder::aPendingSmsDelivery()->withId($this->deliveryId($i))->build();

            $this->strategy($providers, 'round_robin')->deliver($delivery);

            $started = $delivery->sentViaProvider();
            self::assertNotNull($started);
            self::assertContains($started, ['fake_a', 'fake_b', 'fake_c']);
            $seen[$started] = true;
            foreach ($providers as $provider) {
                self::assertSame($provider->name() === $started ? 1 : 0, $provider->sentCount());
            }
        }

        self::assertGreaterThanOrEqual(2, \count($seen));
    }

    #[DataProvider('nonDeliverableStatuses')]
    public function test_it_refuses_a_delivery_that_is_not_pending_or_throttled(string $status): void
    {
        $provider = FakeSmsProvider::withMode(FakeMode::Success, 'fake_a');
        $builder = DeliveryBuilder::aPendingSmsDelivery();
        $delivery = match ($status) {
            'sent' => $builder->sent()->build(),
            'failed' => $builder->failed()->build(),
            'skipped' => $builder->skipped()->build(),
            default => self::fail('Unexpected status ' . $status),
        };

        try {
            $this->strategy([$provider])->deliver($delivery);
            self::fail('Expected DeliveryAlreadyFinal');
        } catch (DeliveryAlreadyFinal) {
            self::assertSame([], $delivery->attempts());
            self::assertSame(0, $provider->sentCount());
        }
    }

    public function test_it_delivers_a_throttled_delivery(): void
    {
        $provider = FakeSmsProvider::withMode(FakeMode::Success, 'fake_a');
        $delivery = DeliveryBuilder::aPendingSmsDelivery()->throttled()->build();

        $result = $this->strategy([$provider])->deliver($delivery);

        self::assertTrue($result->succeeded());
        self::assertSame(DeliveryStatus::Sent, $delivery->status());
        self::assertSame(1, $provider->sentCount());
    }

    public function test_it_marks_the_attempt_in_progress_before_the_provider_call(): void
    {
        $seen = null;
        $provider = FakeSmsProvider::withMode(FakeMode::Success, 'fake_a');
        $delivery = DeliveryBuilder::aPendingSmsDelivery()->build();
        $provider->beforeSend(static function () use ($delivery, &$seen): void {
            $seen = $delivery->attempts()[0]->outcome();
        });

        $this->strategy([$provider])->deliver($delivery);

        self::assertSame(AttemptOutcome::InProgress, $seen);
        self::assertSame(AttemptOutcome::Succeeded, $delivery->attempts()[0]->outcome());
    }

    public function test_it_flushes_each_attempt_while_it_is_in_progress(): void
    {
        $primary = FakeSmsProvider::withMode(FakeMode::Transient, 'fake_a');
        $secondary = FakeSmsProvider::withMode(FakeMode::Success, 'fake_b');
        $delivery = DeliveryBuilder::aPendingSmsDelivery()->build();
        $seen = [];

        $this->strategy([$primary, $secondary])->deliver($delivery, static function () use ($delivery, &$seen): void {
            $attempt = $delivery->attempts()[\count($delivery->attempts()) - 1];
            $seen[] = $attempt->outcome();
        });

        self::assertSame([AttemptOutcome::InProgress, AttemptOutcome::InProgress], $seen);
        self::assertSame(
            [AttemptOutcome::TransientFailure, AttemptOutcome::Succeeded],
            $this->outcomes($delivery),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function nonDeliverableStatuses(): iterable
    {
        yield 'sent' => ['sent'];
        yield 'failed' => ['failed'];
        yield 'skipped' => ['skipped'];
    }

    /**
     * @param list<FakeSmsProvider> $providers
     */
    private function strategy(array $providers, string $ordering = 'priority'): FailoverDeliveryStrategy
    {
        return new FailoverDeliveryStrategy(
            ChannelConfiguration::fromArray([
                'sms' => [
                    'enabled' => true,
                    'strategy' => $ordering,
                    'providers' => array_map(
                        static fn(FakeSmsProvider $provider): string => $provider->name(),
                        $providers,
                    ),
                ],
            ]),
            ProviderRegistry::fromList($providers),
            new MockClock(DeliveryBuilder::NOW),
        );
    }

    /** @return list<FakeSmsProvider> */
    private function successes(): array
    {
        return [
            FakeSmsProvider::withMode(FakeMode::Success, 'fake_a'),
            FakeSmsProvider::withMode(FakeMode::Success, 'fake_b'),
            FakeSmsProvider::withMode(FakeMode::Success, 'fake_c'),
        ];
    }

    /** @return list<AttemptOutcome> */
    private function outcomes(Delivery $delivery): array
    {
        return array_map(
            static fn(DeliveryAttempt $attempt): AttemptOutcome => $attempt->outcome(),
            $delivery->attempts(),
        );
    }

    /** @return list<string> */
    private function providers(Delivery $delivery): array
    {
        return array_map(
            static fn(DeliveryAttempt $attempt): string => $attempt->provider(),
            $delivery->attempts(),
        );
    }

    private function deliveryId(int $n): string
    {
        return \sprintf('01990a2f-0000-7000-8000-%012x', $n);
    }
}
