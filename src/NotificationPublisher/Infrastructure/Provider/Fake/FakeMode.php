<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Provider\Fake;

use App\NotificationPublisher\Domain\Exception\PermanentProviderFailure;
use App\NotificationPublisher\Domain\Exception\ProviderFailure;
use App\NotificationPublisher\Domain\Exception\TransientProviderFailure;
use App\NotificationPublisher\Domain\Exception\UnknownProviderOutcome;

enum FakeMode: string
{
    case Success = 'success';
    case Transient = 'transient';
    case PermanentRecipient = 'permanent_recipient';
    case PermanentProvider = 'permanent_provider';
    case Timeout = 'timeout';

    public function failure(string $provider, string $recipientCode): ?ProviderFailure
    {
        return match ($this) {
            self::Success => null,
            self::Transient => TransientProviderFailure::fromStatus($provider, 503),
            self::PermanentRecipient => PermanentProviderFailure::recipient($provider, $recipientCode),
            self::PermanentProvider => PermanentProviderFailure::provider($provider, 401),
            self::Timeout => new UnknownProviderOutcome($provider, 'simulated timeout'),
        };
    }
}
