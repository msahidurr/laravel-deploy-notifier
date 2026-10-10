<?php

namespace MSahidurr\DeployNotifier\Events;

use MSahidurr\DeployNotifier\DTOs\DeploymentEvent;

/**
 * Dispatched after a lifecycle event has been accepted and processed
 * (regardless of whether the Telegram delivery itself succeeded). Lets
 * applications hook additional behaviour - persistence, metrics, Slack -
 * without modifying this package.
 */
class DeploymentEventReceived
{
    public function __construct(
        public readonly DeploymentEvent $event,
        public readonly bool $delivered,
    ) {
    }
}
