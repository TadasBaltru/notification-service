<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Persistence\Doctrine\Type;

use App\NotificationPublisher\Domain\Model\UserId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class UserIdType extends Type
{
    public const NAME = 'user_id';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['length'] = $column['length'] ?? 64;

        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?UserId
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return UserId::fromString((string) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof UserId ? $value->value : (string) $value;
    }
}
