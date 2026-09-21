<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\InvalidUserId;

final readonly class UserId
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ('' === $value || \strlen($value) > 64) {
            throw InvalidUserId::because('must be 1-64 characters');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
