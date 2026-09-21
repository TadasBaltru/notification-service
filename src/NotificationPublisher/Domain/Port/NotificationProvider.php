<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Domain\Port;

use App\NotificationPublisher\Domain\Exception\PermanentProviderFailure;
use App\NotificationPublisher\Domain\Exception\TransientProviderFailure;
use App\NotificationPublisher\Domain\Exception\UnknownProviderOutcome;
use App\NotificationPublisher\Domain\Model\Channel;
use App\NotificationPublisher\Domain\Model\OutboundMessage;
use App\NotificationPublisher\Domain\Model\ProviderResult;

interface NotificationProvider
{
    public function name(): string;

    public function channel(): Channel;

    /**
     * @throws TransientProviderFailure
     * @throws PermanentProviderFailure
     * @throws UnknownProviderOutcome
     */
    public function send(OutboundMessage $message): ProviderResult;
}
