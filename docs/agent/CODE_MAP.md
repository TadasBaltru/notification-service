# Code map

One row per class under `src/`. Read the rows you need instead of opening neighbouring files. Kept in sync by the
`docs-sync` rule and verified by `tools/check-docs.php` (every class must have a row; paths and tests must exist).

Columns: Path | Layer | Responsibility | Key public API | Covering test (`-` if none)

| Path | Layer | Responsibility | Key public API | Test |
|---|---|---|---|---|
| `src/Kernel.php` | framework | Symfony micro-kernel; loads `config/`; registers channel validation | `build(ContainerBuilder): void` | HealthEndpointTest |
| `src/Controller/HealthController.php` | UserInterface (shared) | `GET /health` liveness probe used by the compose healthcheck; OpenAPI template for later endpoints | `__invoke(): JsonResponse` | HealthEndpointTest |
| `src/NotificationPublisher/UserInterface/Http/OpenApi/ProblemSchema.php` | UserInterface | RFC 7807 `Problem` body (`type`, `title`, `status`, `detail`, `violations`) | `__construct(string $type, string $title, int $status, ?string $detail, array $violations)` | OpenApiCoverageTest, NotificationApiTest |
| `src/NotificationPublisher/UserInterface/Http/OpenApi/ProblemViolationSchema.php` | UserInterface | One validation violation in a `Problem` body | `__construct(string $propertyPath, string $message)` | OpenApiCoverageTest, NotificationApiTest |
| `src/NotificationPublisher/UserInterface/Http/OpenApi/NotificationSchema.php` | UserInterface | Accept/status JSON: `id`, derived `status`, `deliveries` | `__construct(string $id, string $status, array $deliveries)` | NotificationApiTest, OpenApiCoverageTest |
| `src/NotificationPublisher/UserInterface/Http/OpenApi/DeliverySchema.php` | UserInterface | Per-channel delivery in the HTTP response | `__construct(string $id, string $channel, string $status, string $recipient)` | NotificationApiTest, OpenApiCoverageTest |
| `src/NotificationPublisher/Domain/Model/Notification.php` | Domain | Aggregate root; one `Delivery` per requested channel; ORM entity | `request(...): self`; `addDelivery(DeliveryId, Channel, Recipient, DateTimeImmutable): Delivery`; `deliveries(): list<Delivery>` | NotificationTest |
| `src/NotificationPublisher/Domain/Model/Delivery.php` | Domain | Channel delivery lifecycle; owns attempts; named transitions; ORM entity | `pending(...)`; `markSent/Failed/Skipped/Throttled`; `startAttempt/completeAttempt/failAttempt`; `isFinal()`; `toOutboundMessage()` | DeliveryTest |
| `src/NotificationPublisher/Domain/Model/DeliveryAttempt.php` | Domain | One try against one provider; starts `in_progress` | `start(...)`; `succeed(ProviderResult, DateTimeImmutable)`; `fail(AttemptOutcome, ProviderFailure, DateTimeImmutable)`; `outcome()` | DeliveryTest |
| `src/NotificationPublisher/Domain/Model/Channel.php` | Domain | Requested channel (`sms`, `email`) | `values(): list<string>`; `recipientField(): string` | NotificationTest |
| `src/NotificationPublisher/Domain/Model/DeliveryStatus.php` | Domain | Delivery status; final = sent\|failed\|skipped | `isFinal(): bool` | DeliveryTest |
| `src/NotificationPublisher/Domain/Model/AttemptOutcome.php` | Domain | Attempt outcome including unknown | `allowsFailover(): bool`; `isFailure(): bool` | DeliveryTest |
| `src/NotificationPublisher/Domain/Model/NotificationId.php` | Domain | UUID string identity for a notification | `fromString(string): self`; `equals(self): bool`; `__toString(): string` | ValueObjectTest |
| `src/NotificationPublisher/Domain/Model/DeliveryId.php` | Domain | UUID string identity for a delivery | `fromString(string): self`; `equals(self): bool`; `__toString(): string` | DeliveryTest, ValueObjectTest |
| `src/NotificationPublisher/Domain/Model/DeliveryAttemptId.php` | Domain | UUID string identity for an attempt | `fromString(string): self`; `equals(self): bool`; `__toString(): string` | DeliveryTest, ValueObjectTest |
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
| `src/NotificationPublisher/Domain/Exception/UnknownUser.php` | Domain | Identity stub does not know the user id | `withId(UserId): self` | InMemoryRecipientResolverTest, NotificationApiTest |
| `src/NotificationPublisher/Domain/Exception/ChannelContactNotFound.php` | Domain | Known user has no address for a requested channel | `for(UserId, Channel): self` | InMemoryRecipientResolverTest, NotificationApiTest |
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
| `src/NotificationPublisher/Domain/Exception/NotificationNotFound.php` | Domain | Repository miss by notification id | `withId(NotificationId): self` | NotificationApiTest, JsonExceptionListenerTest |
| `src/NotificationPublisher/Domain/Exception/DeliveryNotFound.php` | Domain | Repository miss by delivery id | `withId(DeliveryId): self` | - |
| `src/NotificationPublisher/Domain/Port/NotificationProvider.php` | Domain | Port for channel adapters | `name()`; `channel()`; `send(OutboundMessage): ProviderResult` | - |
| `src/NotificationPublisher/Domain/Port/NotificationRepository.php` | Domain | Persistence port for the aggregate | `save(Notification)`; `get(NotificationId)`; `findByIdempotencyKey(IdempotencyKey)` | DoctrineNotificationRepositoryTest |
| `src/NotificationPublisher/Domain/Port/DeliveryRepository.php` | Domain | Persistence port for a delivery | `save(Delivery)`; `get(DeliveryId)` | DoctrineNotificationRepositoryTest |
| `src/NotificationPublisher/Domain/Port/RecipientResolver.php` | Domain | Resolve a contact for a user+channel | `resolve(UserId, Channel): Recipient` | InMemoryRecipientResolverTest |
| `src/NotificationPublisher/Domain/Port/DeliveryThrottle.php` | Domain | Per-user send budget for `requiresUserAction` | `decide(Delivery): ThrottleDecision` | - |
| `src/NotificationPublisher/Infrastructure/Persistence/Doctrine/DoctrineNotificationRepository.php` | Infrastructure | Doctrine adapter for `NotificationRepository` | `save(Notification)`; `get(NotificationId)`; `findByIdempotencyKey(IdempotencyKey)` | DoctrineNotificationRepositoryTest |
| `src/NotificationPublisher/Infrastructure/Persistence/Doctrine/DoctrineDeliveryRepository.php` | Infrastructure | Doctrine adapter for `DeliveryRepository` | `save(Delivery)`; `get(DeliveryId)` | DoctrineNotificationRepositoryTest |
| `src/NotificationPublisher/Infrastructure/Persistence/Doctrine/Type/UuidIdentityType.php` | Infrastructure | Shared DBAL type: Domain UUID string via `Uuid` | `convertToPHPValue`; `convertToDatabaseValue` | DoctrineNotificationRepositoryTest |
| `src/NotificationPublisher/Infrastructure/Persistence/Doctrine/Type/NotificationIdType.php` | Infrastructure | DBAL type `notification_id` | `fromRfc4122`; `toRfc4122` | DoctrineNotificationRepositoryTest |
| `src/NotificationPublisher/Infrastructure/Persistence/Doctrine/Type/DeliveryIdType.php` | Infrastructure | DBAL type `delivery_id` | `fromRfc4122`; `toRfc4122` | DoctrineNotificationRepositoryTest |
| `src/NotificationPublisher/Infrastructure/Persistence/Doctrine/Type/DeliveryAttemptIdType.php` | Infrastructure | DBAL type `delivery_attempt_id` | `fromRfc4122`; `toRfc4122` | DoctrineNotificationRepositoryTest |
| `src/NotificationPublisher/Infrastructure/Persistence/Doctrine/Type/UserIdType.php` | Infrastructure | DBAL type `user_id` | `convertToPHPValue`; `convertToDatabaseValue` | DoctrineNotificationRepositoryTest |
| `src/NotificationPublisher/Infrastructure/Persistence/Doctrine/Type/IdempotencyKeyType.php` | Infrastructure | DBAL type `idempotency_key` | `convertToPHPValue`; `convertToDatabaseValue` | DoctrineNotificationRepositoryTest |
| `src/NotificationPublisher/Infrastructure/Persistence/Doctrine/Type/RecipientType.php` | Infrastructure | DBAL type `recipient` | `convertToPHPValue`; `convertToDatabaseValue` | DoctrineNotificationRepositoryTest |
| `src/NotificationPublisher/Infrastructure/Identity/InMemoryRecipientResolver.php` | Infrastructure | Config-seeded identity-service stub | `resolve(UserId, Channel): Recipient` | InMemoryRecipientResolverTest |
| `src/NotificationPublisher/Infrastructure/Provider/Fake/FakeMode.php` | Infrastructure | Env mode for demo providers | `failure(string, string): ?ProviderFailure` | FakeProviderTest |
| `src/NotificationPublisher/Infrastructure/Provider/Fake/FakeSmsProvider.php` | Infrastructure | Tagged SMS demo provider; records sent messages | `name()`; `channel()`; `send(OutboundMessage): ProviderResult`; `withMode(FakeMode, string $name = 'fake_sms'): self`; `beforeSend(Closure): self` | FakeProviderTest |
| `src/NotificationPublisher/Infrastructure/Provider/Fake/FakeEmailProvider.php` | Infrastructure | Tagged email demo provider; records sent messages | `name()`; `channel()`; `send(OutboundMessage): ProviderResult`; `withMode(FakeMode): self` | FakeProviderTest |
| `src/NotificationPublisher/Infrastructure/Provider/ProviderRegistry.php` | Infrastructure | Tagged providers keyed by `AsTaggedItem` index; `fromList()` for unit tests; implements `ProviderDirectory` | `get(string): NotificationProvider`; `names(): list<string>`; `fromList(list): self` | ProviderRegistryTest, ProviderRegistryContainerTest |
| `src/NotificationPublisher/Infrastructure/DependencyInjection/ValidateChannelConfigurationPass.php` | Infrastructure | Fails container build on a bad channel map | `process(ContainerBuilder): void` | ProviderRegistryContainerTest |
| `src/NotificationPublisher/Application/Command/SendNotification.php` | Application | Intent to accept a notification | `__construct(userId, idempotencyKey, channels, subject, body, requiresUserAction, recipientEmail, recipientPhone)` | NotificationApiTest |
| `src/NotificationPublisher/Application/Command/SendNotificationResult.php` | Application | Handler result: aggregate + created vs replay | `__construct(Notification $notification, bool $created)` | NotificationApiTest |
| `src/NotificationPublisher/Application/Command/SendNotificationHandler.php` | Application | Idempotency, recipient resolution; disabled channel stored `skipped` | `__invoke(SendNotification): SendNotificationResult` | NotificationApiTest, SendNotificationHandlerTest |
| `src/NotificationPublisher/Application/Exception/ApplicationException.php` | Application | Layer base for application errors | extends `\RuntimeException` | - |
| `src/NotificationPublisher/Application/Exception/InvalidChannelConfiguration.php` | Application | Channel map rejected at container build | `unknownChannel/unknownStrategy/noProviders/unknownProvider` | ChannelConfigurationTest |
| `src/NotificationPublisher/Application/Exception/UnknownProvider.php` | Application | Registry asked for a name it does not have | `named(string): self` | ProviderRegistryTest |
| `src/NotificationPublisher/Application/Ordering/ProviderOrdering.php` | Application | How a channel's provider list is rotated before failover | `order(list<string>, DeliveryId): list<string>` | ProviderOrderingTest |
| `src/NotificationPublisher/Application/Ordering/PriorityOrdering.php` | Application | Always start at the first configured provider | `order(list<string>, DeliveryId): list<string>` | ProviderOrderingTest |
| `src/NotificationPublisher/Application/Ordering/RoundRobinOrdering.php` | Application | Start at a stable hash of the delivery id, then walk the list | `order(list<string>, DeliveryId): list<string>` | ProviderOrderingTest |
| `src/NotificationPublisher/Application/Configuration/ChannelConfiguration.php` | Application | Enabled flag and ordered provider names per channel | `fromArray(array, ?list): self`; `isEnabled(Channel): bool`; `providersFor(Channel, DeliveryId): list<string>` | ChannelConfigurationTest, SendNotificationHandlerTest |
| `src/NotificationPublisher/Application/Provider/ProviderDirectory.php` | Application | Lookup port so the strategy does not import Infrastructure | `get(string): NotificationProvider` | FailoverDeliveryStrategyTest |
| `src/NotificationPublisher/Application/Delivery/DeliveryResult.php` | Application | Strategy outcome; handler maps it to Messenger exceptions in 3.1 | `sent(string): self`; `retryLater(string): self`; `failed(string): self`; `succeeded()`; `isPermanent()`; `provider()`; `reason()` | FailoverDeliveryStrategyTest |
| `src/NotificationPublisher/Application/Delivery/FailoverDeliveryStrategy.php` | Application | Fail over on transient; stop on recipient permanent; one provider-level failover; no failover on unknown | `deliver(Delivery): DeliveryResult` | FailoverDeliveryStrategyTest |
| `src/NotificationPublisher/Application/Query/GetNotificationStatus.php` | Application | Read notification by id | `__construct(string $id)` | NotificationApiTest |
| `src/NotificationPublisher/Application/Query/GetNotificationStatusHandler.php` | Application | Load aggregate for GET status | `__invoke(GetNotificationStatus): Notification` | NotificationApiTest |
| `src/NotificationPublisher/UserInterface/Http/Request/SendNotificationRequest.php` | UserInterface | POST body DTO with Validator constraints | `toCommand(): SendNotification` | NotificationApiTest |
| `src/NotificationPublisher/UserInterface/Http/Request/RecipientOverrideRequest.php` | UserInterface | Optional email/phone override on the request | `__construct(?string $email, ?string $phone)` | NotificationApiTest |
| `src/NotificationPublisher/UserInterface/Http/NotificationPresenter.php` | UserInterface | Maps aggregate to `{id, status, deliveries}` | `present(Notification): array` | NotificationApiTest |
| `src/NotificationPublisher/UserInterface/Http/NotificationController.php` | UserInterface | `POST /notifications`, `GET /notifications/{id}`; dispatches on default bus | `send(SendNotificationRequest)`; `status(string)` | NotificationApiTest |
| `src/NotificationPublisher/UserInterface/Http/JsonExceptionListener.php` | UserInterface | Unwraps `HandlerFailedException`; RFC 7807 JSON for 400/404/422 | `__invoke(ExceptionEvent): void` | JsonExceptionListenerTest, NotificationApiTest |
| `src/NotificationPublisher/UserInterface/Http/Exception/MissingHandledResult.php` | UserInterface | Bus handled stamp missing or wrong type | `for(string $type): self` | NotificationApiTest |
