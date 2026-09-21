<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\InvalidIdentity;

final readonly class DeliveryId
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidIdentity::for('delivery id', $value);
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
