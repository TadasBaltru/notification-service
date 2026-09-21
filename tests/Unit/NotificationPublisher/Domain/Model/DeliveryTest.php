<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\AttemptAlreadyInProgress;
use App\NotificationPublisher\Domain\Exception\DeliveryAlreadyFinal;
use App\NotificationPublisher\Domain\Exception\InvalidAttemptOutcome;
use App\NotificationPublisher\Domain\Exception\TransientProviderFailure;
use App\NotificationPublisher\Domain\Model\AttemptOutcome;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\DeliveryAttemptId;
use App\NotificationPublisher\Domain\Model\DeliveryStatus;
use App\NotificationPublisher\Domain\Model\ProviderResult;
use App\Tests\Support\DeliveryBuilder;
use PHPUnit\Framework\TestCase;

final class DeliveryTest extends TestCase
{
    public function test_it_cannot_be_marked_sent_twice(): void
    {
        $delivery = DeliveryBuilder::aPendingSmsDelivery()->build();
        $delivery->markSent('twilio', 'SM123', new \DateTimeImmutable(DeliveryBuilder::NOW));

        $this->expectException(DeliveryAlreadyFinal::class);

        $delivery->markSent('fake_sms', 'F1', new \DateTimeImmutable('2026-01-01 10:00:01 UTC'));
    }

    public function test_is_final_for_each_status(): void
    {
        self::assertFalse(DeliveryBuilder::aDelivery()->build()->isFinal());
        self::assertTrue(DeliveryBuilder::aDelivery()->sent()->build()->isFinal());
        self::assertTrue(DeliveryBuilder::aDelivery()->failed()->build()->isFinal());
        self::assertTrue(DeliveryBuilder::aDelivery()->skipped()->build()->isFinal());
        self::assertFalse(DeliveryBuilder::aDelivery()->throttled()->build()->isFinal());
    }

    public function test_it_rejects_further_transitions_once_sent(): void
    {
        $delivery = DeliveryBuilder::aDelivery()->sent()->build();

        $this->expectException(DeliveryAlreadyFinal::class);

        $delivery->markFailed(new \DateTimeImmutable(DeliveryBuilder::NOW));
    }

    public function test_a_throttled_delivery_can_still_be_sent(): void
    {
        $delivery = DeliveryBuilder::aDelivery()->throttled()->build();
        self::assertSame(DeliveryStatus::Throttled, $delivery->status());

        $delivery->markSent('twilio', 'SM123', new \DateTimeImmutable(DeliveryBuilder::NOW));

        self::assertTrue($delivery->isFinal());
        self::assertSame(DeliveryStatus::Sent, $delivery->status());
    }

    public function test_it_records_an_in_progress_attempt(): void
    {
        $delivery = DeliveryBuilder::aDelivery()->build();

        $attempt = $delivery->startAttempt(
            DeliveryAttemptId::fromString(DeliveryBuilder::ATTEMPT_ID),
            'twilio',
            new \DateTimeImmutable(DeliveryBuilder::NOW),
        );

        self::assertSame(AttemptOutcome::InProgress, $attempt->outcome());
        self::assertSame(1, $delivery->attemptsCount());
        self::assertFalse($delivery->isFinal());
    }

    public function test_it_cannot_start_two_in_progress_attempts(): void
    {
        $delivery = DeliveryBuilder::aDelivery()->build();
        $delivery->startAttempt(
            DeliveryAttemptId::fromString(DeliveryBuilder::ATTEMPT_ID),
            'twilio',
            new \DateTimeImmutable(DeliveryBuilder::NOW),
        );

        $this->expectException(AttemptAlreadyInProgress::class);

        $delivery->startAttempt(
            DeliveryAttemptId::fromString('01990a2f-0000-7000-8000-000000000004'),
            'fake_sms',
            new \DateTimeImmutable(DeliveryBuilder::NOW),
        );
    }

    public function test_completing_an_attempt_marks_the_delivery_sent(): void
    {
        $delivery = DeliveryBuilder::aDelivery()->build();
        $attempt = $delivery->startAttempt(
            DeliveryAttemptId::fromString(DeliveryBuilder::ATTEMPT_ID),
            'twilio',
            new \DateTimeImmutable(DeliveryBuilder::NOW),
        );

        $delivery->completeAttempt(
            $attempt,
            ProviderResult::accepted('SM123'),
            new \DateTimeImmutable(DeliveryBuilder::NOW),
        );

        self::assertTrue($delivery->isFinal());
        self::assertSame(DeliveryStatus::Sent, $delivery->status());
        self::assertSame('twilio', $delivery->sentViaProvider());
        self::assertSame(AttemptOutcome::Succeeded, $attempt->outcome());
    }

    public function test_a_failed_attempt_leaves_the_delivery_open(): void
    {
        $delivery = DeliveryBuilder::aDelivery()->build();
        $attempt = $delivery->startAttempt(
            DeliveryAttemptId::fromString(DeliveryBuilder::ATTEMPT_ID),
            'twilio',
            new \DateTimeImmutable(DeliveryBuilder::NOW),
        );

        $delivery->failAttempt(
            $attempt,
            AttemptOutcome::TransientFailure,
            TransientProviderFailure::fromStatus('twilio', 503),
            new \DateTimeImmutable(DeliveryBuilder::NOW),
        );

        self::assertFalse($delivery->isFinal());
        self::assertSame(DeliveryStatus::Pending, $delivery->status());
        self::assertSame(AttemptOutcome::TransientFailure, $attempt->outcome());
    }

    public function test_it_rejects_a_non_failure_outcome_on_fail_attempt(): void
    {
        $delivery = DeliveryBuilder::aDelivery()->build();
        $attempt = $delivery->startAttempt(
            DeliveryAttemptId::fromString(DeliveryBuilder::ATTEMPT_ID),
            'twilio',
            new \DateTimeImmutable(DeliveryBuilder::NOW),
        );

        $this->expectException(InvalidAttemptOutcome::class);

        $delivery->failAttempt(
            $attempt,
            AttemptOutcome::Succeeded,
            TransientProviderFailure::fromStatus('twilio', 503),
            new \DateTimeImmutable(DeliveryBuilder::NOW),
        );
    }

    public function test_it_builds_an_outbound_message_from_the_aggregate(): void
    {
        $delivery = DeliveryBuilder::aDelivery()->build();
        $message = $delivery->toOutboundMessage();

        self::assertSame(Channel::Sms, $message->channel);
        self::assertSame('+37060000001', $message->recipient->address());
        self::assertSame('Hi', $message->content->subject());
        self::assertTrue($delivery->id()->equals($message->deliveryId));
    }
}
