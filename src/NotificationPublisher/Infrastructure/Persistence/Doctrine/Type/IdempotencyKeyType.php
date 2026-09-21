<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Persistence\Doctrine\Type;

use App\NotificationPublisher\Domain\Model\IdempotencyKey;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class IdempotencyKeyType extends Type
{
    public const NAME = 'idempotency_key';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['length'] = $column['length'] ?? 128;

        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?IdempotencyKey
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return IdempotencyKey::fromString((string) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof IdempotencyKey ? $value->value : (string) $value;
    }
}
