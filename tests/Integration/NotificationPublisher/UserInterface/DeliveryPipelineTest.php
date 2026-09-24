<?php

declare(strict_types=1);

namespace App\Tests\Integration\NotificationPublisher\UserInterface;

use App\NotificationPublisher\Application\Command\DeliverNotification;
use App\NotificationPublisher\Application\Exception\DeliveryRequiresRetry;
use App\NotificationPublisher\Domain\Model\AttemptOutcome;
use App\NotificationPublisher\Domain\Model\Delivery;
use App\NotificationPublisher\Domain\Model\DeliveryStatus;
use App\NotificationPublisher\Domain\Model\Notification;
use App\NotificationPublisher\Domain\Port\NotificationRepository;
use App\Tests\Support\DeliveryPipeline;
use App\Tests\Support\OpenApiAssertions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class DeliveryPipelineTest extends WebTestCase
{
    use OpenApiAssertions;

    protected function tearDown(): void
    {
        DeliveryPipeline::restoreEnv();
        parent::tearDown();
    }

    public function test_it_marks_the_delivery_sent_when_the_primary_succeeds(): void
    {
        $client = $this->emailClient('success');
        $pipeline = $this->pipeline($client);

        $id = $this->acceptEmail($client, $pipeline, 'e2e-success');
        $failure = $pipeline->drain();

        self::assertNull($failure);
        $this->assertHttpDeliveryStatus($client, $id, 'sent');
        $delivery = $this->delivery($pipeline, $id, 'email');
        self::assertSame(DeliveryStatus::Sent, $delivery->status());
        self::assertSame('fake_email', $delivery->sentViaProvider());
        self::assertSame([AttemptOutcome::Succeeded], $this->outcomes($delivery));
    }

    public function test_it_fails_over_to_the_secondary_when_the_primary_is_transient(): void
    {
        $client = $this->emailClient('transient');
        $pipeline = $this->pipeline($client);

        $id = $this->acceptEmail($client, $pipeline, 'e2e-failover');
        $failure = $pipeline->drain();

        self::assertNull($failure);
        $this->assertHttpDeliveryStatus($client, $id, 'sent');
        $delivery = $this->delivery($pipeline, $id, 'email');
        self::assertSame(DeliveryStatus::Sent, $delivery->status());
        self::assertSame('smtp', $delivery->sentViaProvider());
        self::assertSame(
            [AttemptOutcome::TransientFailure, AttemptOutcome::Succeeded],
            $this->outcomes($delivery),
        );
        self::assertSame(['fake_email', 'smtp'], $this->providers($delivery));
    }

    public function test_it_keeps_the_delivery_pending_when_every_provider_fails_transiently(): void
    {
        $client = $this->emailClient('transient', 'smtp://127.0.0.1:1');
        $pipeline = $this->pipeline($client);

        $id = $this->acceptEmail($client, $pipeline, 'e2e-all-fail');
        $failure = $pipeline->drain();

        self::assertInstanceOf(DeliveryRequiresRetry::class, $failure);
        $this->assertHttpDeliveryStatus($client, $id, 'pending');
        $delivery = $this->delivery($pipeline, $id, 'email');
        self::assertSame(DeliveryStatus::Pending, $delivery->status());
        self::assertNull($delivery->sentViaProvider());
        self::assertSame(
            [AttemptOutcome::TransientFailure, AttemptOutcome::TransientFailure],
            $this->outcomes($delivery),
        );
        self::assertSame(['fake_email', 'smtp'], $this->providers($delivery));
    }

    public function test_it_moves_the_message_to_the_failed_transport_when_retries_are_exhausted(): void
    {
        $client = $this->emailClient('transient', 'smtp://127.0.0.1:1');
        $pipeline = $this->pipeline($client);
        $id = $this->acceptEmail($client, $pipeline, 'e2e-failed-transport');

        $async = self::getContainer()->get('messenger.transport.async');
        $failed = self::getContainer()->get('messenger.transport.failed');
        $bus = self::getContainer()->get('delivery.bus');
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        self::assertInstanceOf(InMemoryTransport::class, $failed);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $handled = 0;
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$handled): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();

                return;
            }

            ++$handled;
            // Test retry max is 1, so a correct strategy rejects on the second handle. A third
            // handle means the message is being retried without a cap; stop so the test can fail.
            if ($handled >= 3) {
                $event->getWorker()->stop();
            }
        });

        (new Worker(['async' => $async], $bus, $dispatcher))->run(['sleep' => 0, 'time_limit' => 5]);

        self::assertCount(1, $failed->getSent());
        self::assertSame([], $async->get());
        $this->assertHttpDeliveryStatus($client, $id, 'pending');
        $delivery = $this->delivery($pipeline, $id, 'email');
        self::assertSame(DeliveryStatus::Pending, $delivery->status());
    }

    public function test_it_retries_later_without_failover_when_the_primary_times_out(): void
    {
        $client = $this->emailClient('timeout');
        $pipeline = $this->pipeline($client);

        $id = $this->acceptEmail($client, $pipeline, 'e2e-unknown');
        $failure = $pipeline->drain();

        self::assertInstanceOf(DeliveryRequiresRetry::class, $failure);
        $this->assertHttpDeliveryStatus($client, $id, 'pending');
        $delivery = $this->delivery($pipeline, $id, 'email');
        self::assertSame(DeliveryStatus::Pending, $delivery->status());
        self::assertSame([AttemptOutcome::Unknown], $this->outcomes($delivery));
        self::assertSame(['fake_email'], $this->providers($delivery));
    }

    public function test_it_fails_the_delivery_when_the_recipient_is_rejected(): void
    {
        $client = $this->emailClient('permanent_recipient');
        $pipeline = $this->pipeline($client);

        $id = $this->acceptEmail($client, $pipeline, 'e2e-permanent');
        $failure = $pipeline->drain();

        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $failure);
        $this->assertHttpDeliveryStatus($client, $id, 'failed');
        $delivery = $this->delivery($pipeline, $id, 'email');
        self::assertSame(DeliveryStatus::Failed, $delivery->status());
        self::assertSame([AttemptOutcome::PermanentFailure], $this->outcomes($delivery));
        self::assertSame(['fake_email'], $this->providers($delivery));
    }

    public function test_it_skips_a_disabled_channel_and_queues_nothing(): void
    {
        DeliveryPipeline::setEnv('NOTIFICATIONS_SMS_ENABLED', 'false');
        $client = self::createClient();

        $pipeline = $this->pipeline($client);
        $id = $pipeline->accept([
            'userId' => 'user-1',
            'idempotencyKey' => 'e2e-disabled',
            'channels' => ['sms'],
            'subject' => 'Hello',
            'body' => 'Skip sms.',
        ]);
        $this->assertResponseIsDocumented($client);

        self::assertSame([], $pipeline->queued());
        $this->assertHttpDeliveryStatus($client, $id, 'skipped');
        $delivery = $this->delivery($pipeline, $id, 'sms');
        self::assertSame(DeliveryStatus::Skipped, $delivery->status());
        self::assertSame([], $delivery->attempts());
    }

    public function test_it_throttles_the_fourth_user_action_and_ignores_the_rest(): void
    {
        $client = self::createClient();
        $pipeline = $this->pipeline($client);

        $plain = $this->acceptFor($client, $pipeline, 'throttle-plain', false);
        self::assertNull($pipeline->drain());
        $this->assertHttpDeliveryStatus($client, $plain, 'sent');

        $sent = [];
        foreach (['throttle-1', 'throttle-2', 'throttle-3'] as $key) {
            $sent[] = $this->acceptFor($client, $pipeline, $key, true);
            self::assertNull($pipeline->drain());
        }
        foreach ($sent as $id) {
            $this->assertHttpDeliveryStatus($client, $id, 'sent');
        }

        $blocked = $this->acceptFor($client, $pipeline, 'throttle-4', true);
        self::assertNull($pipeline->drain());
        $delayed = $this->delayed($pipeline);
        $delivery = $this->delivery($pipeline, $blocked, 'email');

        self::assertSame(DeliveryStatus::Throttled, $delivery->status());
        self::assertSame([], $delivery->attempts());
        self::assertCount(1, $delayed);
        $message = $delayed[0]->getMessage();
        self::assertInstanceOf(DeliverNotification::class, $message);
        self::assertSame($delivery->id()->value, $message->deliveryId);
        $stamp = $delayed[0]->last(DelayStamp::class);
        self::assertInstanceOf(DelayStamp::class, $stamp);
        self::assertGreaterThan(0, $stamp->getDelay());
        $this->assertHttpDeliveryStatus($client, $blocked, 'throttled');

        $after = $this->acceptFor($client, $pipeline, 'throttle-plain-after', false);
        self::assertNull($pipeline->drain());
        $this->assertHttpDeliveryStatus($client, $after, 'sent');
    }

    public function test_it_does_not_record_another_attempt_when_the_same_message_is_delivered_twice(): void
    {
        $client = $this->emailClient('success');
        $pipeline = $this->pipeline($client);

        $id = $this->acceptEmail($client, $pipeline, 'e2e-redelivery');
        $envelopes = $pipeline->queued();
        self::assertCount(1, $envelopes);

        self::assertNull($pipeline->dispatch($envelopes[0]));
        self::assertNull($pipeline->dispatch($envelopes[0]));

        $this->assertHttpDeliveryStatus($client, $id, 'sent');
        $delivery = $this->delivery($pipeline, $id, 'email');
        self::assertSame([AttemptOutcome::Succeeded], $this->outcomes($delivery));
    }

    public function test_it_delivers_every_requested_channel(): void
    {
        DeliveryPipeline::setEnv('FAKE_EMAIL_MODE', 'success');
        DeliveryPipeline::setEnv('FAKE_SMS_MODE', 'success');
        DeliveryPipeline::setEnv('NOTIFICATIONS_EMAIL_PROVIDERS', 'fake_email,smtp');
        DeliveryPipeline::setEnv('NOTIFICATIONS_SMS_PROVIDERS', 'fake_sms');
        $client = self::createClient();

        $pipeline = $this->pipeline($client);
        $id = $pipeline->accept([
            'userId' => 'user-1',
            'idempotencyKey' => 'e2e-both-channels',
            'channels' => ['email', 'sms'],
            'subject' => 'Hello',
            'body' => 'Both channels.',
        ]);
        $this->assertResponseIsDocumented($client);
        self::assertCount(2, $pipeline->queued());
        self::assertNull($pipeline->drain());

        $client->request('GET', '/notifications/' . $id);
        self::assertResponseStatusCodeSame(200);
        $this->assertResponseIsDocumented($client);
        $body = $this->json($client);
        self::assertSame('sent', $body['status']);

        $notification = $pipeline->reload($id);
        self::assertSame(DeliveryStatus::Sent, $this->deliveryFrom($notification, 'email')->status());
        self::assertSame(DeliveryStatus::Sent, $this->deliveryFrom($notification, 'sms')->status());
        self::assertSame('fake_email', $this->deliveryFrom($notification, 'email')->sentViaProvider());
        self::assertSame('fake_sms', $this->deliveryFrom($notification, 'sms')->sentViaProvider());
    }

    private function emailClient(string $mode, ?string $mailerDsn = null): KernelBrowser
    {
        DeliveryPipeline::setEnv('FAKE_EMAIL_MODE', $mode);
        DeliveryPipeline::setEnv('NOTIFICATIONS_EMAIL_PROVIDERS', 'fake_email,smtp');
        if (null !== $mailerDsn) {
            DeliveryPipeline::setEnv('MAILER_DSN', $mailerDsn);
        }

        return self::createClient();
    }

    private function pipeline(KernelBrowser $client): DeliveryPipeline
    {
        return new DeliveryPipeline($client, static function (string $id): object {
            $service = match ($id) {
                'messenger.transport.async' => self::getContainer()->get('messenger.transport.async'),
                'delivery.bus' => self::getContainer()->get('delivery.bus'),
                EntityManagerInterface::class => self::getContainer()->get(EntityManagerInterface::class),
                NotificationRepository::class => self::getContainer()->get(NotificationRepository::class),
                default => self::fail(\sprintf('Unknown service "%s".', $id)),
            };
            self::assertIsObject($service);

            return $service;
        });
    }

    private function acceptFor(KernelBrowser $client, DeliveryPipeline $pipeline, string $idempotencyKey, bool $requiresUserAction): string
    {
        $id = $pipeline->accept([
            'userId' => 'user-1',
            'idempotencyKey' => $idempotencyKey,
            'channels' => ['email'],
            'subject' => 'Hello',
            'body' => 'End to end.',
            'requiresUserAction' => $requiresUserAction,
        ]);
        $this->assertResponseIsDocumented($client);

        return $id;
    }

    /** @return list<Envelope> */
    private function delayed(DeliveryPipeline $pipeline): array
    {
        $delayed = [];
        foreach ($pipeline->queued() as $envelope) {
            if (null !== $envelope->last(DelayStamp::class)) {
                $delayed[] = $envelope;
            }
        }

        return $delayed;
    }

    private function acceptEmail(KernelBrowser $client, DeliveryPipeline $pipeline, string $idempotencyKey): string
    {
        $id = $pipeline->accept([
            'userId' => 'user-1',
            'idempotencyKey' => $idempotencyKey,
            'channels' => ['email'],
            'subject' => 'Hello',
            'body' => 'End to end.',
        ]);
        $this->assertResponseIsDocumented($client);

        return $id;
    }

    private function assertHttpDeliveryStatus(KernelBrowser $client, string $id, string $status): void
    {
        $client->request('GET', '/notifications/' . $id);
        self::assertResponseStatusCodeSame(200);
        $this->assertResponseIsDocumented($client);
        $body = $this->json($client);
        self::assertSame($status, $body['status']);
        self::assertIsArray($body['deliveries']);
        self::assertIsArray($body['deliveries'][0]);
        self::assertSame($status, $body['deliveries'][0]['status']);
    }

    private function delivery(DeliveryPipeline $pipeline, string $id, string $channel): Delivery
    {
        return $this->deliveryFrom($pipeline->reload($id), $channel);
    }

    private function deliveryFrom(Notification $notification, string $channel): Delivery
    {
        foreach ($notification->deliveries() as $delivery) {
            if ($delivery->channel()->value === $channel) {
                return $delivery;
            }
        }

        self::fail(\sprintf('No %s delivery.', $channel));
    }

    /** @return list<AttemptOutcome> */
    private function outcomes(Delivery $delivery): array
    {
        $outcomes = [];
        foreach ($delivery->attempts() as $attempt) {
            $outcomes[] = $attempt->outcome();
        }

        return $outcomes;
    }

    /** @return list<string> */
    private function providers(Delivery $delivery): array
    {
        $providers = [];
        foreach ($delivery->attempts() as $attempt) {
            $providers[] = $attempt->provider();
        }

        return $providers;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
