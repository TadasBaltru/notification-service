<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\Infrastructure\Provider\Sms;

use App\NotificationPublisher\Domain\Exception\PermanentProviderFailure;
use App\NotificationPublisher\Domain\Exception\TransientProviderFailure;
use App\NotificationPublisher\Domain\Exception\UnknownProviderOutcome;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Model\NotificationContent;
use App\NotificationPublisher\Domain\Model\OutboundMessage;
use App\NotificationPublisher\Domain\Model\Recipient;
use App\NotificationPublisher\Infrastructure\Provider\Sms\TwilioFailureClassifier;
use App\NotificationPublisher\Infrastructure\Provider\Sms\TwilioSmsProvider;
use App\Tests\Support\DeliveryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class TwilioSmsProviderTest extends TestCase
{
    public function test_it_is_the_sms_provider_named_twilio(): void
    {
        $provider = $this->provider(new MockHttpClient());

        self::assertSame('twilio', $provider->name());
        self::assertSame(Channel::Sms, $provider->channel());
    }

    public function test_it_posts_messages_json_with_basic_auth_and_returns_the_sid(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.twilio.com/2010-04-01/Accounts/ACtest/Messages.json', $url);
            self::assertSame(
                ['Authorization: Basic ' . base64_encode('ACtest:test-token')],
                $options['normalized_headers']['authorization'] ?? null,
            );
            self::assertSame(5.0, $options['timeout']);
            self::assertIsString($options['body']);
            self::assertStringContainsString('To=%2B37060000001', $options['body']);
            self::assertStringContainsString('From=%2B15005550006', $options['body']);
            self::assertStringContainsString('Body=Hello', $options['body']);

            return new MockResponse((string) json_encode(['sid' => 'SM2', 'status' => 'queued'], \JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });

        $result = $this->provider($client)->send($this->sms());

        self::assertSame('SM2', $result->providerMessageId);
    }

    public function test_it_treats_a_created_message_without_a_sid_as_unknown(): void
    {
        $provider = $this->provider(new MockHttpClient([self::json(201, ['status' => 'queued'])]));

        $this->expectException(UnknownProviderOutcome::class);

        $provider->send($this->sms());
    }

    public function test_it_treats_a_transport_failure_as_unknown(): void
    {
        $provider = $this->provider(new MockHttpClient([
            new MockResponse('', ['error' => 'Idle timeout reached']),
        ]));

        try {
            $provider->send($this->sms());
            self::fail('A transport failure must not look like a completed send.');
        } catch (UnknownProviderOutcome $e) {
            self::assertSame('twilio', $e->provider);
            self::assertNull($e->errorCode);
            self::assertInstanceOf(TransportExceptionInterface::class, $e->getPrevious());
        }
    }

    #[DataProvider('classifiedFailures')]
    public function test_it_classifies_twilio_status_codes(MockResponse $response, string $type, ?bool $recipientLevel, string $errorCode): void
    {
        $provider = $this->provider(new MockHttpClient([$response]));

        try {
            $provider->send($this->sms());
            self::fail('Expected ' . $type);
        } catch (PermanentProviderFailure $e) {
            self::assertSame($type, $e::class);
            self::assertSame($recipientLevel, $e->recipientLevel);
            self::assertSame($errorCode, $e->errorCode);
            self::assertSame('twilio', $e->provider);
        } catch (TransientProviderFailure $e) {
            self::assertSame($type, $e::class);
            self::assertNull($recipientLevel);
            self::assertSame($errorCode, $e->errorCode);
            self::assertSame('twilio', $e->provider);
        }
    }

    /**
     * @return iterable<string, array{0: MockResponse, 1: class-string, 2: bool|null, 3: string}>
     */
    public static function classifiedFailures(): iterable
    {
        yield 'invalid to' => [self::json(400, ['code' => 21211, 'message' => 'Invalid To']), PermanentProviderFailure::class, true, '21211'];
        yield 'landline' => [self::json(400, ['code' => 21614, 'message' => 'Not a mobile number']), PermanentProviderFailure::class, true, '21614'];
        yield 'invalid from' => [self::json(400, ['code' => 21212, 'message' => 'Invalid From']), PermanentProviderFailure::class, false, '400'];
        yield 'unauthorized' => [self::json(401, ['message' => 'Authenticate']), PermanentProviderFailure::class, false, '401'];
        yield 'forbidden' => [self::json(403, ['message' => 'Forbidden']), PermanentProviderFailure::class, false, '403'];
        yield 'rate limit' => [self::json(429, ['message' => 'Too many requests']), TransientProviderFailure::class, null, '429'];
        yield 'unavailable' => [new MockResponse('', ['http_code' => 503]), TransientProviderFailure::class, null, '503'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(int $status, array $payload): MockResponse
    {
        return new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    private function provider(MockHttpClient $client): TwilioSmsProvider
    {
        return new TwilioSmsProvider($client, new TwilioFailureClassifier(), 'ACtest', 'test-token', '+15005550006');
    }

    private function sms(): OutboundMessage
    {
        return new OutboundMessage(
            DeliveryId::fromString(DeliveryBuilder::DELIVERY_ID),
            Channel::Sms,
            Recipient::fromString('+37060000001'),
            NotificationContent::fromStrings('Hi', 'Hello'),
        );
    }
}
