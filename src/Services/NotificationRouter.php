<?php

namespace MSahidurr\DeployNotifier\Services;

use Illuminate\Support\Facades\Log;

/**
 * Decides which configured destination a deployment event's environment
 * maps to. Never hardcodes a destination - everything comes from the
 * published config so one package install can serve multiple apps,
 * each routing production and staging independently.
 */
class NotificationRouter
{
    /**
     * @param  array<string, string|null>  $channels  environment => destination id
     */
    public function __construct(
        private readonly array $channels,
    ) {
    }

    /**
     * Resolve the destination for an environment, or null when none is
     * configured. Callers must treat null as "skip delivery, log it",
     * never as an error.
     */
    public function destinationFor(string $environment): ?string
    {
        $destination = $this->channels[$environment] ?? null;

        // Config values aren't guaranteed to be strings - a Telegram
        // chat id hardcoded directly in a published config file (rather
        // than via env()) is easy to write as a literal int.
        if ($destination !== null) {
            $destination = (string) $destination;
        }

        if ($destination === null || $destination === '') {
            Log::warning("deploy-notifier: no Telegram destination configured for environment [{$environment}]; skipping delivery.");

            return null;
        }

        return $destination;
    }
}
