<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Persistence\Doctrine\Type;

use App\NotificationPublisher\Domain\Model\DeliveryAttemptId;
use Doctrine\DBAL\Types\ConversionException;

final class DeliveryAttemptIdType extends UuidIdentityType
{
    public const NAME = 'delivery_attempt_id';

    protected function fromRfc4122(string $rfc4122): DeliveryAttemptId
    {
        return DeliveryAttemptId::fromString($rfc4122);
    }

    protected function toRfc4122(object $value): string
    {
        if (!$value instanceof DeliveryAttemptId) {
            throw new ConversionException(\sprintf('Expected %s, got %s.', DeliveryAttemptId::class, $value::class));
        }

        return $value->value;
    }
}
