<?php

namespace MSahidurr\DeployNotifier\Listeners;

use Illuminate\Support\Facades\Log;
use MSahidurr\DeployNotifier\Events\DeploymentEventReceived;
use MSahidurr\DeployNotifier\Services\MessageFormatter;
use MSahidurr\DeployNotifier\Transports\DiscordTransport;

/**
 * Optional, additional delivery to Discord - same pattern as
 * SendSlackNotification. A no-op whenever deploy-notifier.discord.enabled
 * is false (the default), so registering this listener is always safe
 * regardless of config. Runs independently of SendSlackNotification, so
 * Telegram, Slack and Discord can all be enabled at once.
 */
class SendDiscordNotification
{
    public function __construct(
        private readonly DiscordTransport $transport,
        private readonly MessageFormatter $formatter,
    ) {
    }

    public function handle(DeploymentEventReceived $received): void
    {
        if (! config('deploy-notifier.discord.enabled', false)) {
            return;
        }

        $environment = $received->event->environment;

        // A plain array lookup, not config("...webhooks.{$environment}")
        // - an environment name containing a literal "." (e.g.
        // "client.staging") would otherwise be misread by config()'s
        // dot-notation as a deeper nested key instead of this flat one.
        $webhooks = (array) config('deploy-notifier.discord.webhooks', []);
        $webhookUrl = $webhooks[$environment] ?? null;

        if (! $webhookUrl) {
            Log::warning("deploy-notifier: Discord is enabled but no webhook is configured for environment [{$environment}]; skipping.");

            return;
        }

        $this->transport->send($webhookUrl, $this->formatter->format($received->event), [
            'type' => $received->event->type,
            'deployment_id' => $received->event->deploymentId,
        ]);
    }
}
