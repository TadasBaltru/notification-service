<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Provider\Sms;

use App\NotificationPublisher\Domain\Exception\PermanentProviderFailure;
use App\NotificationPublisher\Domain\Exception\TransientProviderFailure;
use App\NotificationPublisher\Domain\Exception\UnknownProviderOutcome;
use App\NotificationPublisher\Domain\Model\ProviderResult;

/**
 * Twilio error docs (checked 2026-09-22): 21211 is an invalid To number, 21614 is a
 * non-mobile To. Both are the recipient. 429 is also a 4xx, so it is classified
 * before the permanent-provider fallback.
 */
final readonly class TwilioFailureClassifier
{
    private const RECIPIENT_ERROR_CODES = [21211, 21614];

    /**
     * @param array<array-key, mixed> $body
     */
    public function classify(string $provider, int $status, array $body): ProviderResult
    {
        if (201 === $status) {
            $sid = $body['sid'] ?? null;
            if (!\is_string($sid) || '' === $sid) {
                throw new UnknownProviderOutcome($provider, \sprintf('Twilio response has no sid (%s)', $provider));
            }

            return ProviderResult::accepted($sid);
        }

        $code = $body['code'] ?? null;
        if (400 === $status && \is_int($code) && \in_array($code, self::RECIPIENT_ERROR_CODES, true)) {
            throw PermanentProviderFailure::recipient($provider, (string) $code);
        }

        if (429 === $status || $status >= 500) {
            throw TransientProviderFailure::fromStatus($provider, $status);
        }

        throw PermanentProviderFailure::provider($provider, $status);
    }
}
