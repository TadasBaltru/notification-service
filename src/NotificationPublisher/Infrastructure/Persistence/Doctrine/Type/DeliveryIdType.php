<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Persistence\Doctrine\Type;

use App\NotificationPublisher\Domain\Model\DeliveryId;
use Doctrine\DBAL\Types\ConversionException;

final class DeliveryIdType extends UuidIdentityType
{
    public const NAME = 'delivery_id';

    protected function fromRfc4122(string $rfc4122): DeliveryId
    {
        return DeliveryId::fromString($rfc4122);
    }

    protected function toRfc4122(object $value): string
    {
        if (!$value instanceof DeliveryId) {
            throw new ConversionException(\sprintf('Expected %s, got %s.', DeliveryId::class, $value::class));
        }

        return $value->value;
    }
}
