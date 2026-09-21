<?php

declare(strict_types=1);

namespace App\NotificationPublisher\UserInterface\Http\Request;

use App\NotificationPublisher\Application\Command\SendNotification;
use App\NotificationPublisher\Domain\Model\Channel;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;

#[Exclude]
final readonly class SendNotificationRequest
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        #[OA\Property(example: 'user-1')]
        public string $userId,
        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        #[OA\Property(example: 'order-42')]
        public string $idempotencyKey,
        #[Assert\Count(min: 1)]
        #[Assert\Unique]
        #[Assert\All([
            new Assert\NotBlank(),
            new Assert\Choice(callback: [Channel::class, 'values']),
        ])]
        #[OA\Property(type: 'array', items: new OA\Items(type: 'string', enum: ['email', 'sms']), example: ['email'])]
        public array $channels,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(example: 'Hello')]
        public string $subject,
        #[Assert\NotBlank]
        #[Assert\Length(max: 65535)]
        #[OA\Property(example: 'Your order is confirmed.')]
        public string $body,
        #[OA\Property(example: false)]
        public bool $requiresUserAction = false,
        #[Assert\Valid]
        public ?RecipientOverrideRequest $recipient = null,
    ) {}

    public function toCommand(): SendNotification
    {
        return new SendNotification(
            userId: $this->userId,
            idempotencyKey: $this->idempotencyKey,
            channels: $this->channels,
            subject: $this->subject,
            body: $this->body,
            requiresUserAction: $this->requiresUserAction,
            recipientEmail: $this->recipient?->email,
            recipientPhone: $this->recipient?->phone,
        );
    }
}
