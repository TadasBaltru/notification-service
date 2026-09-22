<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\Provider;

use App\NotificationPublisher\Application\Exception\UnknownProvider;
use App\NotificationPublisher\Application\Provider\ProviderDirectory;
use App\NotificationPublisher\Domain\Port\NotificationProvider;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[Autoconfigure(public: true)]
final readonly class ProviderRegistry implements ProviderDirectory
{
    /** @var array<string, NotificationProvider> */
    private array $byName;

    /** @param iterable<NotificationProvider> $providers */
    public function __construct(
        #[AutowireIterator('notification.provider')]
        iterable $providers,
    ) {
        $byName = [];
        foreach ($providers as $key => $provider) {
            // Container iterator keys are the AsTaggedItem indexes. fromList() passes a list, so fall back to name().
            $name = \is_string($key) && '' !== $key ? $key : $provider->name();
            $byName[$name] = $provider;
        }
        $this->byName = $byName;
    }

    /** @param list<NotificationProvider> $providers */
    public static function fromList(array $providers): self
    {
        return new self($providers);
    }

    public function get(string $name): NotificationProvider
    {
        return $this->byName[$name] ?? throw UnknownProvider::named($name);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->byName);
    }
}
