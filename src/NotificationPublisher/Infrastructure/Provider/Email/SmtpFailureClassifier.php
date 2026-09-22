<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Provider\Email;

use App\NotificationPublisher\Domain\Exception\PermanentProviderFailure;
use App\NotificationPublisher\Domain\Exception\TransientProviderFailure;
use App\NotificationPublisher\Domain\Exception\UnknownProviderOutcome;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Symfony 7.4 SMTP debug lines are `[timestamp] > COMMAND` and `[timestamp] < REPLY`
 * (the message body is not logged). A 4xx/5xx reply to RCPT TO is the recipient.
 * Once DATA is on the wire the message may already have left, so the outcome is unknown.
 */
final readonly class SmtpFailureClassifier
{
    public function classify(string $provider, TransportExceptionInterface $exception): never
    {
        $debug = $exception->getDebug();

        if ($this->reachedData($debug)) {
            throw new UnknownProviderOutcome($provider, \sprintf('SMTP outcome unknown after DATA (%s)', $provider), $exception);
        }

        $rejectCode = $this->recipientRejectCode($debug);
        if (null !== $rejectCode) {
            throw new PermanentProviderFailure($provider, $rejectCode, \sprintf('SMTP recipient rejected by %s (%s)', $provider, $rejectCode), true, $exception);
        }

        throw new TransientProviderFailure($provider, null, \sprintf('SMTP failure before DATA (%s)', $provider), $exception);
    }

    private function reachedData(string $debug): bool
    {
        return 1 === preg_match('/\] > DATA(?:\s|$)/', $debug)
            || 1 === preg_match('/\] < 354(?:\s|$)/', $debug);
    }

    private function recipientRejectCode(string $debug): ?string
    {
        $code = null;
        $awaitingReply = false;

        foreach (preg_split('/\R/', $debug) ?: [] as $line) {
            if (1 === preg_match('/\] > RCPT TO:/', $line)) {
                $awaitingReply = true;

                continue;
            }

            if (1 === preg_match('/\] > /', $line)) {
                $awaitingReply = false;

                continue;
            }

            if ($awaitingReply && 1 === preg_match('/\] < ([45]\d{2})\b/', $line, $matches)) {
                $code = $matches[1];
                $awaitingReply = false;
            }
        }

        return $code;
    }
}
