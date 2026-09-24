<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http;

use App\NotificationPublisher\Application\Command\SendNotificationResult;
use App\NotificationPublisher\Application\Query\GetNotificationStatus;
use App\NotificationPublisher\Application\Query\ListUserNotifications;
use App\NotificationPublisher\Application\Query\UserNotificationList;
use App\NotificationPublisher\Domain\Model\Notification;
use App\NotificationPublisher\UserInterface\Http\Exception\MissingHandledResult;
use App\NotificationPublisher\UserInterface\Http\OpenApi\NotificationSchema;
use App\NotificationPublisher\UserInterface\Http\OpenApi\ProblemSchema;
use App\NotificationPublisher\UserInterface\Http\OpenApi\UserNotificationListSchema;
use App\NotificationPublisher\UserInterface\Http\Request\SendNotificationRequest;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Notifications')]
final readonly class NotificationController
{
    public function __construct(
        private MessageBusInterface $bus,
        private NotificationPresenter $presenter,
    ) {}

    #[Route('/notifications', name: 'notifications_create', methods: ['POST'])]
    #[OA\Post(summary: 'Accept a notification')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            ref: new Model(type: SendNotificationRequest::class),
            example: [
                'userId' => 'user-1',
                'idempotencyKey' => 'order-42',
                'channels' => ['email'],
                'subject' => 'Hello',
                'body' => 'Your order is confirmed.',
                'requiresUserAction' => false,
            ],
        ),
    )]
    #[OA\Response(
        response: 202,
        description: 'Notification accepted; deliveries are pending',
        content: new OA\JsonContent(ref: new Model(type: NotificationSchema::class)),
    )]
    #[OA\Response(
        response: 200,
        description: 'Idempotent replay of an existing notification',
        content: new OA\JsonContent(ref: new Model(type: NotificationSchema::class)),
    )]
    #[OA\Response(
        response: 400,
        description: 'Malformed JSON',
        content: new OA\JsonContent(ref: new Model(type: ProblemSchema::class)),
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation failed, unknown user, or missing contact for a requested channel',
        content: new OA\JsonContent(ref: new Model(type: ProblemSchema::class)),
    )]
    public function send(#[MapRequestPayload] SendNotificationRequest $request): JsonResponse
    {
        $result = $this->handled($this->bus->dispatch($request->toCommand()), SendNotificationResult::class);

        return new JsonResponse($this->presenter->present($result->notification), $result->created ? 202 : 200);
    }

    #[Route('/notifications/{id}', name: 'notifications_get', methods: ['GET'])]
    #[OA\Get(summary: 'Notification status')]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string', format: 'uuid'),
    )]
    #[OA\Response(
        response: 200,
        description: 'Current notification and per-channel delivery status',
        content: new OA\JsonContent(ref: new Model(type: NotificationSchema::class)),
    )]
    #[OA\Response(
        response: 404,
        description: 'Unknown notification id',
        content: new OA\JsonContent(ref: new Model(type: ProblemSchema::class)),
    )]
    public function status(string $id): JsonResponse
    {
        $notification = $this->handled($this->bus->dispatch(new GetNotificationStatus($id)), Notification::class);

        return new JsonResponse($this->presenter->present($notification));
    }

    #[Route('/users/{userId}/notifications', name: 'user_notifications_list', methods: ['GET'])]
    #[OA\Get(summary: 'Notifications for one user')]
    #[OA\Parameter(
        name: 'userId',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string', example: 'user-1'),
    )]
    #[OA\Parameter(
        name: 'since',
        in: 'query',
        schema: new OA\Schema(type: 'string', format: 'date-time', example: '2026-01-01T00:00:00+00:00'),
    )]
    #[OA\Response(
        response: 200,
        description: 'Notifications created at or after since, newest first',
        content: new OA\JsonContent(ref: new Model(type: UserNotificationListSchema::class)),
    )]
    #[OA\Response(
        response: 400,
        description: 'Query parameter since is not a date-time',
        content: new OA\JsonContent(ref: new Model(type: ProblemSchema::class)),
    )]
    public function listForUser(string $userId, Request $request): JsonResponse
    {
        $list = $this->handled(
            $this->bus->dispatch(new ListUserNotifications($userId, $this->since($request))),
            UserNotificationList::class,
        );

        return new JsonResponse($this->presenter->presentList($list->notifications));
    }

    private function since(Request $request): ?\DateTimeImmutable
    {
        if (!$request->query->has('since')) {
            return null;
        }

        $raw = $request->query->getString('since');
        if ('' === trim($raw)) {
            throw new BadRequestHttpException('Query parameter "since" must be an ISO-8601 date-time.');
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\DateMalformedStringException) {
            throw new BadRequestHttpException('Query parameter "since" must be an ISO-8601 date-time.');
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function handled(Envelope $envelope, string $class): object
    {
        $stamp = $envelope->last(HandledStamp::class);
        if (!$stamp instanceof HandledStamp) {
            throw MissingHandledResult::for($class);
        }
        $result = $stamp->getResult();
        if (!$result instanceof $class) {
            throw MissingHandledResult::for($class);
        }

        return $result;
    }
}
