<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Exception;

use App\NotificationPublisher\Domain\Model\Channel;

final class InvalidChannelConfiguration extends ApplicationException
{
    public static function unknownChannel(string $channel): self
    {
        return new self(\sprintf(
            'Unknown notification channel "%s". Expected one of: %s.',
            $channel,
            implode(', ', Channel::values()),
        ));
    }

    public static function unknownStrategy(string $channel, string $strategy): self
    {
        return new self(\sprintf(
            'Unknown provider strategy "%s" for channel "%s". Expected priority or round_robin.',
            $strategy,
            $channel,
        ));
    }

    public static function noProviders(string $channel): self
    {
        return new self(\sprintf('Enabled channel "%s" has no providers.', $channel));
    }

    public static function unknownProvider(string $channel, string $provider): self
    {
        return new self(\sprintf('Unknown provider "%s" configured for channel "%s".', $provider, $channel));
    }

    public static function missingChannel(string $channel): self
    {
        return new self(\sprintf('Channel "%s" is not configured.', $channel));
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
