<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\Uid\Uuid;

abstract class UuidIdentityType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $uuid = $value instanceof Uuid ? $value : Uuid::fromString((string) $value);

        return $this->fromRfc4122($uuid->toRfc4122());
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }

        if (!\is_string($value)) {
            $value = $this->toRfc4122($value);
        }

        return Uuid::fromString($value)->toRfc4122();
    }

    abstract protected function fromRfc4122(string $rfc4122): object;

    abstract protected function toRfc4122(object $value): string;
}
