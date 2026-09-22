<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Configuration;

use App\NotificationPublisher\Application\Exception\InvalidChannelConfiguration;
use App\NotificationPublisher\Application\Ordering\PriorityOrdering;
use App\NotificationPublisher\Application\Ordering\ProviderOrdering;
use App\NotificationPublisher\Application\Ordering\RoundRobinOrdering;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ChannelConfiguration
{
    /**
     * @var array<string, array{enabled: bool, ordering: ProviderOrdering, providers: list<string>}>
     */
    private array $channels;

    /**
     * Name checks against the provider registry happen in the compiler pass, which passes `$knownProviders`.
     * The container calls this without that list; unit tests that only care about order omit it too.
     *
     * @param array<string, mixed> $raw
     * @param list<string>|null $knownProviders
     */
    public function __construct(
        #[Autowire('%notifications.channels%')]
        array $raw,
        ?array $knownProviders = null,
    ) {
        $this->channels = self::parse($raw, $knownProviders);
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<string>|null $knownProviders
     */
    public static function fromArray(array $raw, ?array $knownProviders = null): self
    {
        return new self($raw, $knownProviders);
    }

    public function isEnabled(Channel $channel): bool
    {
        return $this->settings($channel)['enabled'];
    }

    /**
     * @return list<string>
     */
    public function providersFor(Channel $channel, DeliveryId $deliveryId): array
    {
        $settings = $this->settings($channel);

        return $settings['ordering']->order($settings['providers'], $deliveryId);
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<string>|null $knownProviders
     *
     * @return array<string, array{enabled: bool, ordering: ProviderOrdering, providers: list<string>}>
     */
    private static function parse(array $raw, ?array $knownProviders): array
    {
        $channels = [];
        foreach ($raw as $name => $cfg) {
            if (!\is_string($name) || !\is_array($cfg)) {
                throw InvalidChannelConfiguration::unknownChannel((string) $name);
            }

            try {
                $channel = Channel::from($name);
            } catch (\ValueError) {
                throw InvalidChannelConfiguration::unknownChannel($name);
            }

            if (!\is_bool($cfg['enabled'] ?? null)) {
                throw InvalidChannelConfiguration::because(\sprintf('Channel "%s" enabled flag must be a boolean.', $name));
            }

            $strategy = $cfg['strategy'] ?? null;
            if (!\is_string($strategy)) {
                throw InvalidChannelConfiguration::unknownStrategy($name, '');
            }

            $providers = $cfg['providers'] ?? null;
            if (!\is_array($providers)) {
                throw InvalidChannelConfiguration::noProviders($name);
            }

            $providerNames = [];
            foreach ($providers as $provider) {
                if (!\is_string($provider) || '' === $provider) {
                    throw InvalidChannelConfiguration::unknownProvider($name, \is_string($provider) ? $provider : '');
                }
                $providerNames[] = $provider;
            }

            if ($cfg['enabled'] && [] === $providerNames) {
                throw InvalidChannelConfiguration::noProviders($name);
            }

            if (null !== $knownProviders) {
                foreach ($providerNames as $provider) {
                    if (!\in_array($provider, $knownProviders, true)) {
                        throw InvalidChannelConfiguration::unknownProvider($name, $provider);
                    }
                }
            }

            $channels[$channel->value] = [
                'enabled' => $cfg['enabled'],
                'ordering' => self::ordering($name, $strategy),
                'providers' => $providerNames,
            ];
        }

        return $channels;
    }

    private static function ordering(string $channel, string $strategy): ProviderOrdering
    {
        return match ($strategy) {
            'priority' => new PriorityOrdering(),
            'round_robin' => new RoundRobinOrdering(),
            default => throw InvalidChannelConfiguration::unknownStrategy($channel, $strategy),
        };
    }

    /**
     * @return array{enabled: bool, ordering: ProviderOrdering, providers: list<string>}
     */
    private function settings(Channel $channel): array
    {
        return $this->channels[$channel->value]
            ?? throw InvalidChannelConfiguration::missingChannel($channel->value);
    }
}
