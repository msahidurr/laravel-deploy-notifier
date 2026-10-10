<?php

namespace MSahidurr\DeployNotifier\Tests\Unit;

use MSahidurr\DeployNotifier\Contracts\EventStore;
use MSahidurr\DeployNotifier\Contracts\NotificationTransport;
use MSahidurr\DeployNotifier\DTOs\DeploymentEvent;
use MSahidurr\DeployNotifier\Events\DeploymentEventReceived;
use MSahidurr\DeployNotifier\Services\DeploymentManager;
use MSahidurr\DeployNotifier\Services\MessageFormatter;
use MSahidurr\DeployNotifier\Services\NotificationRouter;
use MSahidurr\DeployNotifier\Services\SecretRedactor;
use MSahidurr\DeployNotifier\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class DeploymentManagerTest extends TestCase
{
    private function manager(
        ?NotificationTransport $transport = null,
        ?EventStore $eventStore = null,
        array $channels = ['production' => '111'],
        bool $enabled = true,
        bool $dedupeEnabled = true,
        bool $lockingEnabled = true,
    ): DeploymentManager {
        return new DeploymentManager(
            transport: $transport ?? new class implements NotificationTransport {
                public function send(string $destination, string $message, array $context = []): bool
                {
                    return true;
                }
            },
            formatter: new MessageFormatter(new SecretRedactor()),
            router: new NotificationRouter($channels),
            eventStore: $eventStore ?? $this->app->make(EventStore::class),
            enabled: $enabled,
            dedupeEnabled: $dedupeEnabled,
            dedupeTtl: 3600,
            lockingEnabled: $lockingEnabled,
            lockTtl: 3600,
        );
    }

    public function test_disabled_manager_never_delivers(): void
    {
        $manager = $this->manager(enabled: false);

        $event = new DeploymentEvent(DeploymentEvent::TYPE_STARTED, 'd1', 'Test', 'production');

        $this->assertFalse($manager->record($event));
    }

    public function test_it_delivers_when_a_destination_exists(): void
    {
        $manager = $this->manager();

        $event = new DeploymentEvent(DeploymentEvent::TYPE_STARTED, 'd1', 'Test', 'production');

        $this->assertTrue($manager->record($event));
    }

    public function test_it_skips_delivery_without_a_configured_destination(): void
    {
        $manager = $this->manager(channels: []);

        $event = new DeploymentEvent(DeploymentEvent::TYPE_STARTED, 'd1', 'Test', 'production');

        $this->assertFalse($manager->record($event));
    }

    public function test_it_suppresses_duplicate_events(): void
    {
        $manager = $this->manager();

        $event = new DeploymentEvent(DeploymentEvent::TYPE_STARTED, 'd1', 'Test', 'production');

        $this->assertTrue($manager->record($event));
        $this->assertFalse($manager->record($event));
    }

    public function test_a_failed_telegram_delivery_does_not_throw(): void
    {
        $manager = $this->manager(transport: new class implements NotificationTransport {
            public function send(string $destination, string $message, array $context = []): bool
            {
                return false;
            }
        });

        $event = new DeploymentEvent(DeploymentEvent::TYPE_STARTED, 'd1', 'Test', 'production');

        $this->assertFalse($manager->record($event));
    }

    public function test_it_dispatches_a_deployment_event_received(): void
    {
        Event::fake();

        $manager = $this->manager();
        $event = new DeploymentEvent(DeploymentEvent::TYPE_STARTED, 'd1', 'Test', 'production');

        $manager->record($event);

        Event::assertDispatched(DeploymentEventReceived::class, function ($dispatched) use ($event) {
            return $dispatched->event === $event && $dispatched->delivered === true;
        });
    }

    public function test_lock_prevents_concurrent_acquisition_until_released(): void
    {
        $manager = $this->manager();

        $this->assertTrue($manager->acquireLock('Test', 'production'));
        $this->assertFalse($manager->acquireLock('Test', 'production'));

        $manager->releaseLock('Test', 'production');

        $this->assertTrue($manager->acquireLock('Test', 'production'));
    }

    public function test_locking_is_a_no_op_when_disabled(): void
    {
        $manager = $this->manager(lockingEnabled: false);

        $this->assertTrue($manager->acquireLock('Test', 'production'));
        $this->assertTrue($manager->acquireLock('Test', 'production'));
    }
}
