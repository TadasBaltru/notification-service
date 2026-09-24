<?php

declare(strict_types=1);

namespace App\Tests\Unit\NotificationPublisher\UserInterface\Http;

use App\NotificationPublisher\Application\Query\GetNotificationStatus;
use App\NotificationPublisher\Domain\Exception\DeliveryNotFound;
use App\NotificationPublisher\Domain\Exception\NotificationNotFound;
use App\NotificationPublisher\Domain\Exception\UnknownUser;
use App\NotificationPublisher\Domain\Model\DeliveryId;
use App\NotificationPublisher\Domain\Model\NotificationId;
use App\NotificationPublisher\Domain\Model\UserId;
use App\NotificationPublisher\UserInterface\Http\JsonExceptionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class JsonExceptionListenerTest extends TestCase
{
    public function test_it_unwraps_handler_failed_exception_for_unknown_notification(): void
    {
        $inner = NotificationNotFound::withId(NotificationId::fromString('01990a2f-0000-7000-8000-000000000099'));
        $response = $this->listen(new HandlerFailedException(
            new Envelope(new GetNotificationStatus($inner->getMessage())),
            [$inner],
        ));

        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(404, $body['status']);
        self::assertSame('Notification not found', $body['title']);
    }

    public function test_it_unwraps_handler_failed_exception_for_unknown_user(): void
    {
        $inner = UnknownUser::withId(UserId::fromString('ghost'));
        $response = $this->listen(new HandlerFailedException(
            new Envelope(new GetNotificationStatus('unused')),
            [$inner],
        ));

        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Unknown user', $body['title']);
    }

    public function test_it_maps_a_missing_delivery_to_404(): void
    {
        $inner = DeliveryNotFound::withId(DeliveryId::fromString('01990a2f-0000-7000-8000-000000000088'));
        $response = $this->listen(new HandlerFailedException(
            new Envelope(new GetNotificationStatus('unused')),
            [$inner],
        ));

        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Delivery not found', $body['title']);
    }

    private function listen(\Throwable $throwable): ?Response
    {
        $kernel = self::createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent($kernel, Request::create('/notifications'), HttpKernelInterface::MAIN_REQUEST, $throwable);
        (new JsonExceptionListener())($event);

        return $event->getResponse();
    }
}
