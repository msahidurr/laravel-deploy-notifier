<?php

namespace MSahidurr\DeployNotifier\Services;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use MSahidurr\DeployNotifier\Contracts\EventStore;
use MSahidurr\DeployNotifier\Contracts\NotificationTransport;
use MSahidurr\DeployNotifier\DTOs\DeploymentEvent;
use MSahidurr\DeployNotifier\Events\DeploymentEventReceived;

/**
 * Single entry point every Artisan command and the webhook controller
 * goes through. Owns deduplication, the deployment lock, message
 * formatting/delivery, and the Laravel event dispatch - so none of
 * that logic is duplicated per command.
 */
class DeploymentManager
{
    public function __construct(
        private readonly NotificationTransport $transport,
        private readonly MessageFormatter $formatter,
        private readonly NotificationRouter $router,
        private readonly EventStore $eventStore,
        private readonly bool $enabled,
        private readonly bool $dedupeEnabled,
        private readonly int $dedupeTtl,
        private readonly bool $lockingEnabled,
        private readonly int $lockTtl,
    ) {
    }

    /**
     * Process and deliver a single lifecycle event. Returns true only
     * if the message was actually handed off successfully to Telegram.
     * Every other outcome (disabled, duplicate, no destination,
     * delivery failure) returns false but never throws - callers in a
     * deployment script must never have their own exit status affected
     * by a notification problem.
     */
    public function record(DeploymentEvent $event): bool
    {
        if (! $this->enabled) {
            Log::debug('deploy-notifier: disabled, skipping event.', ['type' => $event->type]);

            return false;
        }

        if ($this->dedupeEnabled && ! $this->eventStore->markSeenOnce($event->dedupeKey(), $this->dedupeTtl)) {
            Log::info('deploy-notifier: duplicate event suppressed.', [
                'type' => $event->type,
                'deployment_id' => $event->deploymentId,
            ]);

            return false;
        }

        $destination = $this->router->destinationFor($event->environment);
        $delivered = false;

        if ($destination !== null) {
            $message = $this->formatter->format($event);
            $delivered = $this->transport->send($destination, $message, [
                'type' => $event->type,
                'deployment_id' => $event->deploymentId,
                'project' => $event->project,
                'environment' => $event->environment,
            ]);
        }

        Event::dispatch(new DeploymentEventReceived($event, $delivered));

        return $delivered;
    }

    /**
     * Acquire the deployment lock for a project/environment pair. When
     * locking is disabled this always succeeds, so callers can call it
     * unconditionally.
     */
    public function acquireLock(string $project, string $environment): bool
    {
        if (! $this->lockingEnabled) {
            return true;
        }

        return $this->eventStore->acquireLock($this->lockKey($project, $environment), $this->lockTtl);
    }

    public function releaseLock(string $project, string $environment): void
    {
        if (! $this->lockingEnabled) {
            return;
        }

        $this->eventStore->releaseLock($this->lockKey($project, $environment));
    }

    private function lockKey(string $project, string $environment): string
    {
        return "deploy-lock:{$project}:{$environment}";
    }
}
