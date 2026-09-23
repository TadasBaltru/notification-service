<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\NotificationPublisher\Domain\Model\Notification;
use App\NotificationPublisher\Domain\Model\NotificationId;
use App\NotificationPublisher\Domain\Port\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Posts a notification, then runs queued DeliverNotification messages on delivery.bus.
 * ReceivedStamp tells Messenger the envelope was consumed, so it is handled instead of queued again.
 */
final class DeliveryPipeline
{
    /** @var array<string, array{env: ?string, server: ?string}> */
    private static array $previousEnv = [];

    /** @param \Closure(string): object $services resolved in the test, where private services are visible */
    public function __construct(
        private KernelBrowser $client,
        private \Closure $services,
    ) {}

    public static function setEnv(string $name, string $value): void
    {
        if (!\array_key_exists($name, self::$previousEnv)) {
            $envValue = $_ENV[$name] ?? null;
            $serverValue = $_SERVER[$name] ?? null;
            // Dotenv in this app fills $_ENV / $_SERVER and does not call putenv, so getenv() is not the source of truth.
            self::$previousEnv[$name] = [
                'env' => \is_string($envValue) ? $envValue : null,
                'server' => \is_string($serverValue) ? $serverValue : null,
            ];
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }

    public static function restoreEnv(): void
    {
        foreach (self::$previousEnv as $name => $previous) {
            if (null === $previous['env']) {
                unset($_ENV[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $previous['env'];
                putenv($name . '=' . $previous['env']);
            }

            if (null === $previous['server']) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $previous['server'];
            }
        }

        self::$previousEnv = [];
    }

    /**
     * @param array<string, mixed> $body
     */
    public function accept(array $body): string
    {
        $this->client->request(
            'POST',
            '/notifications',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
        WebTestCase::assertResponseStatusCodeSame(202);

        $id = self::json($this->client)['id'] ?? null;
        WebTestCase::assertIsString($id);

        return $id;
    }

    /** @return list<Envelope> */
    public function queued(): array
    {
        return array_values($this->transport()->getSent());
    }

    public function dispatch(Envelope $envelope): ?\Throwable
    {
        $this->entityManager()->clear();

        try {
            $this->bus()->dispatch($envelope->with(new ReceivedStamp('async')));
        } catch (HandlerFailedException $exception) {
            return self::unwrap($exception);
        }

        return null;
    }

    public function drain(): ?\Throwable
    {
        $failure = null;
        foreach ($this->queued() as $envelope) {
            $failure ??= $this->dispatch($envelope);
        }

        return $failure;
    }

    public function reload(string $id): Notification
    {
        $this->entityManager()->clear();

        $notifications = $this->service(NotificationRepository::class);
        WebTestCase::assertInstanceOf(NotificationRepository::class, $notifications);

        return $notifications->get(NotificationId::fromString($id));
    }

    private function transport(): InMemoryTransport
    {
        $transport = $this->service('messenger.transport.async');
        WebTestCase::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function bus(): MessageBusInterface
    {
        $bus = $this->service('delivery.bus');
        WebTestCase::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->service(EntityManagerInterface::class);
        WebTestCase::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function service(string $id): object
    {
        return ($this->services)($id);
    }

    private static function unwrap(\Throwable $throwable): \Throwable
    {
        while ($throwable instanceof HandlerFailedException) {
            $next = null;
            foreach ($throwable->getWrappedExceptions() as $candidate) {
                $next = $candidate;

                break;
            }
            if (!$next instanceof \Throwable) {
                return $throwable;
            }

            $throwable = $next;
        }

        return $throwable;
    }

    /** @return array<string, mixed> */
    private static function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        WebTestCase::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
