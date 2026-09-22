<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Infrastructure\Provider\Email;

use App\NotificationPublisher\Domain\Exception\PermanentProviderFailure;
use App\NotificationPublisher\Domain\Exception\TransientProviderFailure;
use App\NotificationPublisher\Domain\Exception\UnknownProviderOutcome;
use App\NotificationPublisher\Infrastructure\Provider\Email\SmtpFailureClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class SmtpFailureClassifierTest extends TestCase
{
    private const BEFORE_DATA = <<<'LOG'
        [2026-01-01T10:00:00.000000+00:00] > EHLO [127.0.0.1]
        [2026-01-01T10:00:00.000001+00:00] < 250 OK
        [2026-01-01T10:00:00.000002+00:00] > MAIL FROM:<noreply@notifications.local>
        [2026-01-01T10:00:00.000003+00:00] < 250 OK
        LOG;

    private const MAIL_FROM_REJECTED = <<<'LOG'
        [2026-01-01T10:00:00.000000+00:00] > MAIL FROM:<noreply@notifications.local>
        [2026-01-01T10:00:00.000001+00:00] < 550 relay denied
        LOG;

    private const RECIPIENT_REJECTED = <<<'LOG'
        [2026-01-01T10:00:00.000000+00:00] > MAIL FROM:<noreply@notifications.local>
        [2026-01-01T10:00:00.000001+00:00] < 250 OK
        [2026-01-01T10:00:00.000002+00:00] > RCPT TO:<nobody@example.test>
        [2026-01-01T10:00:00.000003+00:00] < 550 5.1.1 User unknown
        LOG;

    private const DATA_ACCEPTED = <<<'LOG'
        [2026-01-01T10:00:00.000000+00:00] > RCPT TO:<user1@example.test>
        [2026-01-01T10:00:00.000001+00:00] < 250 OK
        [2026-01-01T10:00:00.000002+00:00] > DATA
        [2026-01-01T10:00:00.000003+00:00] < 354 End data with <CR><LF>.<CR><LF>
        LOG;

    private const DATA_SENT_WITHOUT_354 = <<<'LOG'
        [2026-01-01T10:00:00.000000+00:00] > RCPT TO:<user1@example.test>
        [2026-01-01T10:00:00.000001+00:00] < 250 OK
        [2026-01-01T10:00:00.000002+00:00] > DATA
        LOG;

    private SmtpFailureClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new SmtpFailureClassifier();
    }

    public function test_it_treats_a_failure_before_data_as_transient(): void
    {
        $transportException = $this->exception(self::BEFORE_DATA);

        try {
            $this->classifier->classify('smtp', $transportException);
        } catch (TransientProviderFailure $e) {
            self::assertSame('smtp', $e->provider);
            self::assertNull($e->errorCode);
            self::assertSame($transportException, $e->getPrevious());
        }
    }

    public function test_it_treats_a_mail_from_rejection_as_transient(): void
    {
        try {
            $this->classifier->classify('smtp', $this->exception(self::MAIL_FROM_REJECTED));
        } catch (TransientProviderFailure $e) {
            self::assertSame('smtp', $e->provider);
        }
    }

    public function test_it_treats_a_rejected_recipient_as_permanent(): void
    {
        $transportException = $this->exception(self::RECIPIENT_REJECTED);

        try {
            $this->classifier->classify('smtp', $transportException);
        } catch (PermanentProviderFailure $e) {
            self::assertTrue($e->recipientLevel);
            self::assertSame('550', $e->errorCode);
            self::assertSame('smtp', $e->provider);
            self::assertSame($transportException, $e->getPrevious());
        }
    }

    #[DataProvider('dialoguesThatReachedData')]
    public function test_it_treats_a_failure_from_data_onwards_as_unknown(string $debug): void
    {
        $transportException = $this->exception($debug);

        try {
            $this->classifier->classify('smtp', $transportException);
        } catch (UnknownProviderOutcome $e) {
            self::assertSame('smtp', $e->provider);
            self::assertNull($e->errorCode);
            self::assertSame($transportException, $e->getPrevious());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function dialoguesThatReachedData(): iterable
    {
        yield 'DATA and 354' => [self::DATA_ACCEPTED];
        yield 'DATA without 354' => [self::DATA_SENT_WITHOUT_354];
    }

    private function exception(string $debug): TransportExceptionInterface
    {
        $exception = new TransportException('smtp failure');
        $exception->appendDebug($debug);

        return $exception;
    }
}
