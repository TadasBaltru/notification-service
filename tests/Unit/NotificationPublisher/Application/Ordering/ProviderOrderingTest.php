<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Application\Ordering;

use App\NotificationPublisher\Application\Ordering\PriorityOrdering;
use App\NotificationPublisher\Application\Ordering\RoundRobinOrdering;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use PHPUnit\Framework\TestCase;

final class ProviderOrderingTest extends TestCase
{
    public function test_priority_always_starts_with_the_first_provider(): void
    {
        $ordering = new PriorityOrdering();
        $providers = ['fake_a', 'fake_b', 'fake_c'];

        self::assertSame($providers, $ordering->order($providers, $this->id(1)));
        self::assertSame($providers, $ordering->order($providers, $this->id(2)));
    }

    public function test_round_robin_rotates_from_a_stable_hash_of_the_delivery_id(): void
    {
        $ordering = new RoundRobinOrdering();
        $providers = ['fake_a', 'fake_b', 'fake_c'];
        $first = $ordering->order($providers, $this->id(1));

        self::assertSame($first, $ordering->order($providers, $this->id(1)));
        $this->assertIsRotation($providers, $first);

        $different = null;
        for ($i = 2; $i < 40; ++$i) {
            $candidate = $ordering->order($providers, $this->id($i));
            $this->assertIsRotation($providers, $candidate);
            if ($candidate !== $first) {
                $different = $candidate;
                break;
            }
        }

        self::assertNotNull($different);
    }

    public function test_round_robin_leaves_a_single_provider_in_place(): void
    {
        $ordering = new RoundRobinOrdering();

        self::assertSame(['only'], $ordering->order(['only'], $this->id(1)));
    }

    /** @param list<string> $providers
     * @param list<string> $order
     */
    private function assertIsRotation(array $providers, array $order): void
    {
        self::assertCount(\count($providers), $order);
        self::assertEqualsCanonicalizing($providers, $order);
        $cycle = implode(',', [...$providers, ...$providers]);
        self::assertStringContainsString(implode(',', $order), $cycle);
    }

    private function id(int $n): DeliveryId
    {
        return DeliveryId::fromString(\sprintf('01990a2f-0000-7000-8000-%012x', $n));
    }
}
