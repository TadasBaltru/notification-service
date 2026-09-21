<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http;

use App\NotificationPublisher\Domain\Exception\ChannelContactNotFound;
use App\NotificationPublisher\Domain\Exception\DeliveryNotFound;
use App\NotificationPublisher\Domain\Exception\DomainException;
use App\NotificationPublisher\Domain\Exception\NotificationNotFound;
use App\NotificationPublisher\Domain\Exception\UnknownUser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -8)]
final readonly class JsonExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $this->unwrap($event->getThrowable());
        $problem = $this->problem($throwable);
        if (null === $problem) {
            return;
        }

        $event->setResponse(new JsonResponse(
            $problem,
            $problem['status'],
            ['Content-Type' => 'application/problem+json'],
        ));
    }

    private function unwrap(\Throwable $throwable): \Throwable
    {
        $current = $throwable;
        while ($current instanceof HandlerFailedException) {
            $wrapped = $current->getWrappedExceptions();
            if ([] === $wrapped) {
                break;
            }
            $current = $wrapped[array_key_first($wrapped)];
        }

        return $current;
    }

    /**
     * @return array{type: string, title: string, status: int, detail: string, violations: list<array{propertyPath: string, message: string}>}|null
     */
    private function problem(\Throwable $throwable): ?array
    {
        $violations = $this->violations($throwable);
        [$status, $title, $detail] = $this->map($throwable);
        if (!\in_array($status, [400, 404, 422], true)) {
            return null;
        }

        $body = [
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail ?? '',
            'violations' => $violations,
        ];

        return $body;
    }

    /**
     * @return array{0: int, 1: string, 2: string|null}
     */
    private function map(\Throwable $throwable): array
    {
        return match (true) {
            $throwable instanceof NotificationNotFound,
            $throwable instanceof DeliveryNotFound => [404, 'Notification not found', $throwable->getMessage()],
            $throwable instanceof UnknownUser => [422, 'Unknown user', $throwable->getMessage()],
            $throwable instanceof ChannelContactNotFound => [422, 'No contact for requested channel', $throwable->getMessage()],
            $throwable instanceof DomainException => [422, 'Unprocessable request', $throwable->getMessage()],
            $throwable instanceof HttpExceptionInterface => [
                $throwable->getStatusCode(),
                $this->httpTitle($throwable->getStatusCode()),
                $throwable->getMessage(),
            ],
            $throwable instanceof ValidationFailedException => [422, 'Unprocessable request', 'Request payload validation failed'],
            default => [500, 'Internal error', null],
        };
    }

    private function httpTitle(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            default => 'Error',
        };
    }

    /**
     * @return list<array{propertyPath: string, message: string}>
     */
    private function violations(\Throwable $throwable): array
    {
        if ($throwable instanceof ChannelContactNotFound) {
            return [[
                'propertyPath' => 'channels',
                'message' => $throwable->getMessage(),
            ]];
        }

        $current = $throwable;
        while (null !== $current) {
            if ($current instanceof ValidationFailedException) {
                return $this->fromList($current->getViolations());
            }
            $current = $current->getPrevious();
        }

        return [];
    }

    /**
     * @return list<array{propertyPath: string, message: string}>
     */
    private function fromList(ConstraintViolationListInterface $list): array
    {
        $violations = [];
        foreach ($list as $violation) {
            $violations[] = [
                'propertyPath' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return $violations;
    }
}
