<?php

namespace MSahidurr\DeployNotifier\Commands;

use MSahidurr\DeployNotifier\Contracts\NotificationTransport;
use MSahidurr\DeployNotifier\Services\NotificationRouter;

class TestCommand extends BaseCommand
{
    protected $signature = 'deploy-notifier:test {--environment= : Environment whose destination should receive the test message}';

    protected $description = 'Send a test notification through the configured Telegram transport';

    public function handle(NotificationTransport $transport, NotificationRouter $router): int
    {
        if (! config('deploy-notifier.enabled', true)) {
            $this->warn('deploy-notifier is disabled (DEPLOY_NOTIFIER_ENABLED=false). Enable it before testing.');

            return self::FAILURE;
        }

        $environment = $this->option('environment') ?: $this->environment();

        $destination = $router->destinationFor($environment);

        if ($destination === null) {
            $this->error("No Telegram destination is configured for environment [{$environment}].");

            return self::FAILURE;
        }

        $message = sprintf(
            "🧪 TEST NOTIFICATION\n\nProject: %s\nEnvironment: %s\nServer: %s\n\nIf you can read this, deploy-notifier is configured correctly.",
            $this->project(),
            $environment,
            $this->server(),
        );

        $delivered = $transport->send($destination, $message, ['type' => 'test']);

        if ($delivered) {
            $this->info("Test message delivered to the [{$environment}] destination.");

            return self::SUCCESS;
        }

        $this->error('Delivery failed. Check the application log for details.');

        return self::FAILURE;
    }
}
