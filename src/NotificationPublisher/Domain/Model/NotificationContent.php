<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Model;

use App\NotificationPublisher\Domain\Exception\InvalidNotificationContent;

final readonly class NotificationContent
{
    private function __construct(
        private string $subject,
        private string $body,
    ) {}

    public static function fromStrings(string $subject, string $body): self
    {
        $subject = trim($subject);
        $body = trim($body);
        if (\strlen($subject) > 255) {
            throw InvalidNotificationContent::because('subject must be at most 255 characters');
        }
        if ('' === $body || \strlen($body) > 65535) {
            throw InvalidNotificationContent::because('body must be 1-65535 characters');
        }

        return new self($subject, $body);
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function equals(self $other): bool
    {
        return $this->subject === $other->subject && $this->body === $other->body;
    }
}
