<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\InvalidIdempotencyKey;

final readonly class IdempotencyKey
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ('' === $value || \strlen($value) > 128) {
            throw InvalidIdempotencyKey::because('must be 1-128 characters');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
