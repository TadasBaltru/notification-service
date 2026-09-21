<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\InvalidIdempotencyKey;
use App\NotificationPublisher\Domain\Exception\InvalidIdentity;
use App\NotificationPublisher\Domain\Exception\InvalidNotificationContent;
use App\NotificationPublisher\Domain\Exception\InvalidRecipient;
use App\NotificationPublisher\Domain\Exception\InvalidUserId;
use App\NotificationPublisher\Domain\Model\DeliveryAttemptId;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Model\IdempotencyKey;
use App\NotificationPublisher\Domain\Model\NotificationContent;
use App\NotificationPublisher\Domain\Model\NotificationId;
use App\NotificationPublisher\Domain\Model\Recipient;
use App\NotificationPublisher\Domain\Model\UserId;
use PHPUnit\Framework\TestCase;

final class ValueObjectTest extends TestCase
{
    public function test_idempotency_key_rejects_blank_values(): void
    {
        $this->expectException(InvalidIdempotencyKey::class);

        IdempotencyKey::fromString('   ');
    }

    public function test_user_id_rejects_empty_values(): void
    {
        $this->expectException(InvalidUserId::class);

        UserId::fromString('');
    }

    public function test_recipient_rejects_empty_address(): void
    {
        $this->expectException(InvalidRecipient::class);

        Recipient::fromString('');
    }

    public function test_content_rejects_empty_body(): void
    {
        $this->expectException(InvalidNotificationContent::class);

        NotificationContent::fromStrings('Hi', '  ');
    }

    public function test_notification_id_rejects_non_uuid_strings(): void
    {
        $this->expectException(InvalidIdentity::class);

        NotificationId::fromString('not-a-uuid');
    }

    public function test_identity_value_objects_stringify_to_their_value(): void
    {
        $value = '01990a2f-0000-7000-8000-000000000001';

        self::assertSame($value, (string) NotificationId::fromString($value));
        self::assertSame($value, (string) DeliveryId::fromString($value));
        self::assertSame($value, (string) DeliveryAttemptId::fromString($value));
    }
}
