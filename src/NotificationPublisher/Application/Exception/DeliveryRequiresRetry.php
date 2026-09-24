<?php

declare(strict_types=1);

namespace App\NotificationPublisher\Application\Exception;

/**
 * Asks Messenger to retry with the transport strategy.
 * Must not implement RecoverableExceptionInterface: that interface retries forever and ignores max_retries.
 */
final class DeliveryRequiresRetry extends ApplicationException {}
