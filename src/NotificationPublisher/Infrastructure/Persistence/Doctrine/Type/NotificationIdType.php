<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Persistence\Doctrine\Type;

use App\NotificationPublisher\Domain\Model\NotificationId;
use Doctrine\DBAL\Types\ConversionException;

final class NotificationIdType extends UuidIdentityType
{
    public const NAME = 'notification_id';

    protected function fromRfc4122(string $rfc4122): NotificationId
    {
        return NotificationId::fromString($rfc4122);
    }

    protected function toRfc4122(object $value): string
    {
        if (!$value instanceof NotificationId) {
            throw new ConversionException(\sprintf('Expected %s, got %s.', NotificationId::class, $value::class));
        }

        return $value->value;
    }
}
