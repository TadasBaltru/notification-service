<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\InvalidIdempotencyKey;
use App\NotificationPublisher\Domain\Exception\InvalidIdentity;
use App\NotificationPublisher\Domain\Exception\InvalidNotificationContent;
use App\NotificationPublisher\Domain\Exception\InvalidRecipient;
use App\NotificationPublisher\Domain\Exception\InvalidUserId;
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
}
