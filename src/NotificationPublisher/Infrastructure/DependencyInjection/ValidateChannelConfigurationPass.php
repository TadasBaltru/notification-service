<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Infrastructure\DependencyInjection;

use App\NotificationPublisher\Application\Configuration\ChannelConfiguration;
use App\NotificationPublisher\Application\Exception\InvalidChannelConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A constructor check runs only when the service is created. This pass fails `cache:clear`
 * so a typo never reaches a request. Env placeholders are read at compile time on purpose:
 * changing NOTIFICATIONS_* then requires a cache rebuild, which is the configurability demo.
 */
#[Exclude]
final class ValidateChannelConfigurationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('notifications.channels')) {
            throw InvalidChannelConfiguration::because('Parameter "notifications.channels" is not set.');
        }

        $raw = $container->getParameter('notifications.channels');
        if (!\is_array($raw)) {
            throw InvalidChannelConfiguration::because('Parameter "notifications.channels" must be a map.');
        }

        $resolved = $container->resolveEnvPlaceholders($raw, true);
        if (!\is_array($resolved)) {
            throw InvalidChannelConfiguration::because('Parameter "notifications.channels" must be a map.');
        }

        ChannelConfiguration::fromArray($resolved, $this->knownProviders($container));
    }

    /** @return list<string> */
    private function knownProviders(ContainerBuilder $container): array
    {
        $names = [];
        foreach ($container->findTaggedServiceIds('notification.provider') as $id => $tags) {
            foreach ($tags as $attributes) {
                $index = $attributes['index'] ?? null;
                if (\is_string($index) && '' !== $index) {
                    $names[] = $index;
                }
            }

            if (!$container->hasDefinition($id)) {
                continue;
            }

            $class = $container->getDefinition($id)->getClass() ?? $id;
            $class = $container->getParameterBag()->resolveValue($class);
            if (!\is_string($class) || !class_exists($class)) {
                continue;
            }

            foreach ((new \ReflectionClass($class))->getAttributes(AsTaggedItem::class) as $attribute) {
                $index = $attribute->newInstance()->index;
                if (\is_string($index) && '' !== $index) {
                    $names[] = $index;
                }
            }
        }

        return array_values(array_unique($names));
    }
}
