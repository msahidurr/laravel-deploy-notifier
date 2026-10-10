<?php

namespace MSahidurr\DeployNotifier;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use MSahidurr\DeployNotifier\Commands\FailCommand;
use MSahidurr\DeployNotifier\Commands\InstallCommand;
use MSahidurr\DeployNotifier\Commands\PruneCommand;
use MSahidurr\DeployNotifier\Commands\PullCommand;
use MSahidurr\DeployNotifier\Commands\PushCommand;
use MSahidurr\DeployNotifier\Commands\RollbackCommand;
use MSahidurr\DeployNotifier\Commands\StartCommand;
use MSahidurr\DeployNotifier\Commands\StatusCommand;
use MSahidurr\DeployNotifier\Commands\SuccessCommand;
use MSahidurr\DeployNotifier\Commands\TestCommand;
use MSahidurr\DeployNotifier\Contracts\EventStore;
use MSahidurr\DeployNotifier\Contracts\NotificationTransport;
use MSahidurr\DeployNotifier\Events\DeploymentEventReceived;
use MSahidurr\DeployNotifier\Listeners\SendDiscordNotification;
use MSahidurr\DeployNotifier\Listeners\SendSlackNotification;
use MSahidurr\DeployNotifier\Services\CacheEventStore;
use MSahidurr\DeployNotifier\Services\DatabaseEventStore;
use MSahidurr\DeployNotifier\Services\DeploymentManager;
use MSahidurr\DeployNotifier\Services\GitMetadataService;
use MSahidurr\DeployNotifier\Services\MessageFormatter;
use MSahidurr\DeployNotifier\Services\NotificationRouter;
use MSahidurr\DeployNotifier\Services\SecretRedactor;
use MSahidurr\DeployNotifier\Transports\DiscordTransport;
use MSahidurr\DeployNotifier\Transports\SlackTransport;
use MSahidurr\DeployNotifier\Transports\TelegramTransport;

class DeployNotifierServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/deploy-notifier.php', 'deploy-notifier');

        $this->app->singleton(SecretRedactor::class, function ($app) {
            return new SecretRedactor([
                (string) $app['config']->get('deploy-notifier.telegram.bot_token'),
                (string) $app['config']->get('deploy-notifier.github.webhook_secret'),
                ...array_values((array) $app['config']->get('deploy-notifier.slack.webhooks', [])),
                ...array_values((array) $app['config']->get('deploy-notifier.discord.webhooks', [])),
            ]);
        });

        $this->app->singleton(NotificationTransport::class, function ($app) {
            $config = $app['config']->get('deploy-notifier.telegram');

            return new TelegramTransport(
                botToken: $config['bot_token'] ?? null,
                timeoutSeconds: (int) ($config['timeout'] ?? 5),
                retryTimes: (int) ($config['retry_times'] ?? 2),
                retrySleepMs: (int) ($config['retry_sleep_ms'] ?? 250),
            );
        });

        $this->app->singleton(SlackTransport::class, function ($app) {
            $config = $app['config']->get('deploy-notifier.slack');

            return new SlackTransport(
                timeoutSeconds: (int) ($config['timeout'] ?? 5),
                retryTimes: (int) ($config['retry_times'] ?? 2),
                retrySleepMs: (int) ($config['retry_sleep_ms'] ?? 250),
            );
        });

        $this->app->singleton(DiscordTransport::class, function ($app) {
            $config = $app['config']->get('deploy-notifier.discord');

            return new DiscordTransport(
                timeoutSeconds: (int) ($config['timeout'] ?? 5),
                retryTimes: (int) ($config['retry_times'] ?? 2),
                retrySleepMs: (int) ($config['retry_sleep_ms'] ?? 250),
            );
        });

        $this->app->singleton(EventStore::class, function ($app) {
            $driver = $app['config']->get('deploy-notifier.store.driver', 'cache');

            if ($driver === 'database') {
                return new DatabaseEventStore(
                    connection: $app['config']->get('deploy-notifier.store.database.connection'),
                    table: $app['config']->get('deploy-notifier.store.database.table', 'deploy_notifier_events'),
                );
            }

            return new CacheEventStore(
                store: $app['config']->get('deploy-notifier.store.cache.store'),
                prefix: $app['config']->get('deploy-notifier.store.cache.prefix', 'deploy-notifier:dedup:'),
            );
        });

        $this->app->singleton(GitMetadataService::class, function ($app) {
            $config = $app['config']->get('deploy-notifier.git');

            return new GitMetadataService(
                repositoryPath: $config['repository_path'] ?? base_path(),
                remote: $config['remote'] ?? 'origin',
            );
        });

        $this->app->singleton(MessageFormatter::class, function ($app) {
            return new MessageFormatter($app->make(SecretRedactor::class));
        });

        $this->app->singleton(NotificationRouter::class, function ($app) {
            return new NotificationRouter($app['config']->get('deploy-notifier.telegram.channels', []));
        });

        $this->app->singleton(DeploymentManager::class, function ($app) {
            $config = $app['config'];

            return new DeploymentManager(
                transport: $app->make(NotificationTransport::class),
                formatter: $app->make(MessageFormatter::class),
                router: $app->make(NotificationRouter::class),
                eventStore: $app->make(EventStore::class),
                enabled: (bool) $config->get('deploy-notifier.enabled', true),
                dedupeEnabled: (bool) $config->get('deploy-notifier.deduplication.enabled', true),
                dedupeTtl: (int) $config->get('deploy-notifier.deduplication.ttl', 86400),
                lockingEnabled: (bool) $config->get('deploy-notifier.locking.enabled', true),
                lockTtl: (int) $config->get('deploy-notifier.locking.ttl', 1800),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/deploy-notifier.php' => config_path('deploy-notifier.php'),
        ], 'deploy-notifier-config');

        // Published only, never auto-loaded: the database event store is
        // opt-in, and an app choosing it owns this migration in its own
        // migrations directory like any other - run
        // `php artisan vendor:publish --tag=deploy-notifier-migrations`
        // followed by `php artisan migrate` once DEPLOY_NOTIFIER_STORE_DRIVER=database.
        $this->publishes([
            __DIR__.'/../database/migrations/2024_01_01_000000_create_deploy_notifier_events_table.php' => database_path(
                'migrations/'.date('Y_m_d_His').'_create_deploy_notifier_events_table.php'
            ),
        ], 'deploy-notifier-migrations');

        if (config('deploy-notifier.enabled', true) && config('deploy-notifier.github.webhook_secret')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        }

        // Always registered, but a no-op unless the corresponding
        // deploy-notifier.{slack,discord}.enabled is true - see
        // SendSlackNotification / SendDiscordNotification. This is the
        // documented extension point (DeploymentEventReceived) rather
        // than extra arguments on DeploymentManager, which is wired for
        // one primary transport. Both can run at once, independently.
        Event::listen(DeploymentEventReceived::class, SendSlackNotification::class);
        Event::listen(DeploymentEventReceived::class, SendDiscordNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                TestCommand::class,
                StatusCommand::class,
                PushCommand::class,
                PullCommand::class,
                StartCommand::class,
                SuccessCommand::class,
                FailCommand::class,
                RollbackCommand::class,
                PruneCommand::class,
            ]);
        }
    }
}
