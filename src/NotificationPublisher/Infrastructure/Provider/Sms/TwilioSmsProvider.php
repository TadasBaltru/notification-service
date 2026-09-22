<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Provider\Sms;

use App\NotificationPublisher\Domain\Exception\UnknownProviderOutcome;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\OutboundMessage;
use App\NotificationPublisher\Domain\Model\ProviderResult;
use App\NotificationPublisher\Domain\Port\NotificationProvider;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AsTaggedItem(index: 'twilio')]
final readonly class TwilioSmsProvider implements NotificationProvider
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private TwilioFailureClassifier $failures,
        #[Autowire('%env(TWILIO_ACCOUNT_SID)%')]
        private string $accountSid,
        #[Autowire('%env(TWILIO_AUTH_TOKEN)%')]
        private string $authToken,
        #[Autowire('%env(TWILIO_FROM)%')]
        private string $from,
    ) {}

    public function name(): string
    {
        return 'twilio';
    }

    public function channel(): Channel
    {
        return Channel::Sms;
    }

    public function send(OutboundMessage $message): ProviderResult
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                \sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode($this->accountSid)),
                [
                    'auth_basic' => [$this->accountSid, $this->authToken],
                    'timeout' => 5.0,
                    'body' => [
                        'To' => $message->recipient->address(),
                        'From' => $this->from,
                        'Body' => $message->content->body(),
                    ],
                ],
            );
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            throw new UnknownProviderOutcome($this->name(), \sprintf('Twilio outcome unknown (%s)', $this->name()), $exception);
        }

        return $this->failures->classify($this->name(), $status, $this->payload($response, $status));
    }

    /**
     * getStatusCode() and toArray(false) do not throw on 4xx/5xx. A transport
     * failure is the only signal that the message may already have left.
     *
     * @return array<array-key, mixed>
     */
    private function payload(ResponseInterface $response, int $status): array
    {
        if (201 !== $status && 400 !== $status) {
            return [];
        }

        try {
            return $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            throw new UnknownProviderOutcome($this->name(), \sprintf('Twilio outcome unknown (%s)', $this->name()), $exception);
        } catch (DecodingExceptionInterface) {
            if (201 === $status) {
                throw new UnknownProviderOutcome($this->name(), \sprintf('Twilio accepted the message without a readable body (%s)', $this->name()));
            }

            return [];
        }
    }
}
