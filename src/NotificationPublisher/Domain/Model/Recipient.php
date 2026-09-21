<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\InvalidRecipient;

final readonly class Recipient
{
    private function __construct(private string $address) {}

    public static function fromString(string $address): self
    {
        $address = trim($address);
        if ('' === $address || \strlen($address) > 255) {
            throw InvalidRecipient::because('address must be 1-255 characters');
        }

        return new self($address);
    }

    public function address(): string
    {
        return $this->address;
    }

    public function equals(self $other): bool
    {
        return $this->address === $other->address;
    }
}
