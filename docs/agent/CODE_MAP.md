# Code map

One row per class under `src/`. Read the rows you need instead of opening neighbouring files. Kept in sync by the
`docs-sync` rule and verified by `tools/check-docs.php` (every class must have a row; paths and tests must exist).

Columns: Path | Layer | Responsibility | Key public API | Covering test (`-` if none)

| Path | Layer | Responsibility | Key public API | Test |
|---|---|---|---|---|
| `src/Kernel.php` | framework | Symfony micro-kernel; loads `config/` | — | HealthEndpointTest |
| `src/Controller/HealthController.php` | UserInterface (shared) | `GET /health` liveness probe used by the compose healthcheck; OpenAPI template for later endpoints | `__invoke(): JsonResponse` | HealthEndpointTest |
| `src/NotificationPublisher/UserInterface/Http/OpenApi/ProblemSchema.php` | UserInterface | RFC 7807 `Problem` schema; 1.3 error listener must emit this shape | `#[OA\Schema(schema: 'Problem')]` | OpenApiCoverageTest |
| `src/NotificationPublisher/Domain/Model/Notification.php` | Domain | Aggregate root; one `Delivery` per requested channel | `request(...): self`; `addDelivery(DeliveryId, Channel, Recipient, DateTimeImmutable): Delivery`; `deliveries(): list<Delivery>` | NotificationTest |
| `src/NotificationPublisher/Domain/Model/Delivery.php` | Domain | Channel delivery lifecycle; owns attempts; named transitions | `pending(...)`; `markSent/Failed/Skipped/Throttled`; `startAttempt/completeAttempt/failAttempt`; `isFinal()`; `toOutboundMessage()` | DeliveryTest |
| `src/NotificationPublisher/Domain/Model/DeliveryAttempt.php` | Domain | One try against one provider; starts `in_progress` | `start(...)`; `succeed(ProviderResult, DateTimeImmutable)`; `fail(AttemptOutcome, ProviderFailure, DateTimeImmutable)`; `outcome()` | DeliveryTest |
| `src/NotificationPublisher/Domain/Model/Channel.php` | Domain | Requested channel (`sms`, `email`) | `values(): list<string>`; `recipientField(): string` | NotificationTest |
| `src/NotificationPublisher/Domain/Model/DeliveryStatus.php` | Domain | Delivery status; final = sent\|failed\|skipped | `isFinal(): bool` | DeliveryTest |
| `src/NotificationPublisher/Domain/Model/AttemptOutcome.php` | Domain | Attempt outcome including unknown | `allowsFailover(): bool`; `isFailure(): bool` | DeliveryTest |
| `src/NotificationPublisher/Domain/Model/NotificationId.php` | Domain | UUID string identity for a notification | `fromString(string): self`; `equals(self): bool` | ValueObjectTest |
| `src/NotificationPublisher/Domain/Model/DeliveryId.php` | Domain | UUID string identity for a delivery | `fromString(string): self`; `equals(self): bool` | DeliveryTest |
| `src/NotificationPublisher/Domain/Model/DeliveryAttemptId.php` | Domain | UUID string identity for an attempt | `fromString(string): self`; `equals(self): bool` | DeliveryTest |
| `src/NotificationPublisher/Domain/Model/UserId.php` | Domain | Identity-service user id (not a UUID) | `fromString(string): self`; `equals(self): bool` | ValueObjectTest |
| `src/NotificationPublisher/Domain/Model/IdempotencyKey.php` | Domain | 1–128 char duplicate-request key | `fromString(string): self`; `equals(self): bool` | ValueObjectTest |
| `src/NotificationPublisher/Domain/Model/Recipient.php` | Domain | Address for one delivery (email or phone) | `fromString(string): self`; `address(): string`; `equals(self): bool` | ValueObjectTest |
| `src/NotificationPublisher/Domain/Model/NotificationContent.php` | Domain | Pre-rendered subject + body | `fromStrings(string, string): self`; `subject()`; `body()`; `equals(self): bool` | ValueObjectTest |
| `src/NotificationPublisher/Domain/Model/ProviderResult.php` | Domain | Successful provider receipt | `accepted(string): self` | DeliveryTest |
| `src/NotificationPublisher/Domain/Model/OutboundMessage.php` | Domain | Snapshot a provider may send; no aggregate mutation | `__construct(DeliveryId, Channel, Recipient, NotificationContent)` | DeliveryTest |
| `src/NotificationPublisher/Domain/Model/ThrottleDecision.php` | Domain | Allow vs delay for `DeliveryThrottle` | `allow(): self`; `delay(int): self`; `isThrottled()`; `retryAfterMs()` | - |
| `src/NotificationPublisher/Domain/Exception/DomainException.php` | Domain | Layer base for domain errors | extends `\RuntimeException` | DeliveryTest |
| `src/NotificationPublisher/Domain/Exception/ProviderFailure.php` | Domain | Base for provider outcomes (`errorCode` not `$code` — collides with `\Exception::$code`) | `__construct(string $provider, ?string $errorCode, string $message, ?Throwable)` | DeliveryTest |
| `src/NotificationPublisher/Domain/Exception/TransientProviderFailure.php` | Domain | Retryable provider failure (429/5xx) | `fromStatus(string $provider, int $status): self` | DeliveryTest |
| `src/NotificationPublisher/Domain/Exception/PermanentProviderFailure.php` | Domain | Recipient-level vs provider-level permanent failure | `recipient(...)`; `provider(string, int)`; `$recipientLevel` | - |
| `src/NotificationPublisher/Domain/Exception/UnknownProviderOutcome.php` | Domain | Timeout after send; no same-run failover | `__construct(string $provider, string $message, ?Throwable)` | - |
| `src/NotificationPublisher/Domain/Exception/ChannelAlreadyRequested.php` | Domain | Duplicate channel on one notification | `for(NotificationId, Channel): self` | NotificationTest |
| `src/NotificationPublisher/Domain/Exception/DeliveryAlreadyFinal.php` | Domain | Illegal transition on sent/failed/skipped | `for(DeliveryId, DeliveryStatus): self` | DeliveryTest |
| `src/NotificationPublisher/Domain/Exception/AttemptAlreadyInProgress.php` | Domain | Second `startAttempt` while one is open | `for(DeliveryId): self` | DeliveryTest |
| `src/NotificationPublisher/Domain/Exception/AttemptNotInProgress.php` | Domain | Complete/fail an attempt that is not open | `for(DeliveryAttemptId): self` | DeliveryTest |
| `src/NotificationPublisher/Domain/Exception/InvalidAttemptOutcome.php` | Domain | `failAttempt` with a non-failure outcome | `for(AttemptOutcome): self` | DeliveryTest |
| `src/NotificationPublisher/Domain/Exception/InvalidIdempotencyKey.php` | Domain | Idempotency key failed validation | `because(string): self` | ValueObjectTest |
| `src/NotificationPublisher/Domain/Exception/InvalidUserId.php` | Domain | User id failed validation | `because(string): self` | ValueObjectTest |
| `src/NotificationPublisher/Domain/Exception/InvalidRecipient.php` | Domain | Recipient address failed validation | `because(string): self` | ValueObjectTest |
| `src/NotificationPublisher/Domain/Exception/InvalidNotificationContent.php` | Domain | Subject/body failed validation | `because(string): self` | ValueObjectTest |
| `src/NotificationPublisher/Domain/Exception/InvalidIdentity.php` | Domain | UUID string failed validation | `for(string $type, string $value): self` | ValueObjectTest |
| `src/NotificationPublisher/Domain/Exception/NotificationNotFound.php` | Domain | Repository miss by notification id | `withId(NotificationId): self` | - |
| `src/NotificationPublisher/Domain/Exception/DeliveryNotFound.php` | Domain | Repository miss by delivery id | `withId(DeliveryId): self` | - |
| `src/NotificationPublisher/Domain/Port/NotificationProvider.php` | Domain | Port for channel adapters | `name()`; `channel()`; `send(OutboundMessage): ProviderResult` | - |
| `src/NotificationPublisher/Domain/Port/NotificationRepository.php` | Domain | Persistence port for the aggregate | `save(Notification)`; `get(NotificationId)`; `findByIdempotencyKey(IdempotencyKey)` | - |
| `src/NotificationPublisher/Domain/Port/DeliveryRepository.php` | Domain | Persistence port for a delivery | `save(Delivery)`; `get(DeliveryId)` | - |
| `src/NotificationPublisher/Domain/Port/RecipientResolver.php` | Domain | Resolve a contact for a user+channel | `resolve(UserId, Channel): Recipient` | - |
| `src/NotificationPublisher/Domain/Port/DeliveryThrottle.php` | Domain | Per-user send budget for `requiresUserAction` | `decide(Delivery): ThrottleDecision` | - |
