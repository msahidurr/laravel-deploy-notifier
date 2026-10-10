<?php

namespace MSahidurr\DeployNotifier\Listeners;

use Illuminate\Support\Facades\Log;
use MSahidurr\DeployNotifier\Events\DeploymentEventReceived;
use MSahidurr\DeployNotifier\Services\MessageFormatter;
use MSahidurr\DeployNotifier\Transports\SlackTransport;

/**
 * Optional, additional delivery to Slack - demonstrates how to extend
 * notifier output beyond Telegram using the documented extension point
 * (DeploymentEventReceived) rather than touching DeploymentManager,
 * which is wired for exactly one primary transport.
 *
 * A no-op whenever deploy-notifier.slack.enabled is false (the default),
 * so registering this listener is always safe regardless of config.
 */
class SendSlackNotification
{
    public function __construct(
        private readonly SlackTransport $transport,
        private readonly MessageFormatter $formatter,
    ) {
    }

    public function handle(DeploymentEventReceived $received): void
    {
        if (! config('deploy-notifier.slack.enabled', false)) {
            return;
        }

        $environment = $received->event->environment;

        // A plain array lookup, not config("...webhooks.{$environment}")
        // - an environment name containing a literal "." (e.g.
        // "client.staging") would otherwise be misread by config()'s
        // dot-notation as a deeper nested key instead of this flat one.
        $webhooks = (array) config('deploy-notifier.slack.webhooks', []);
        $webhookUrl = $webhooks[$environment] ?? null;

        if (! $webhookUrl) {
            Log::warning("deploy-notifier: Slack is enabled but no webhook is configured for environment [{$environment}]; skipping.");

            return;
        }

        $this->transport->send($webhookUrl, $this->formatter->format($received->event), [
            'type' => $received->event->type,
            'deployment_id' => $received->event->deploymentId,
        ]);
    }
}
