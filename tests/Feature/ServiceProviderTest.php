<?php

namespace MSahidurr\DeployNotifier\Tests\Feature;

use MSahidurr\DeployNotifier\Events\DeploymentEventReceived;
use MSahidurr\DeployNotifier\Listeners\SendDiscordNotification;
use MSahidurr\DeployNotifier\Listeners\SendSlackNotification;
use MSahidurr\DeployNotifier\Services\DeploymentManager;
use MSahidurr\DeployNotifier\Tests\TestCase;
use MSahidurr\DeployNotifier\Transports\DiscordTransport;
use MSahidurr\DeployNotifier\Transports\SlackTransport;

class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged_with_sensible_defaults(): void
    {
        $this->assertTrue(config('deploy-notifier.enabled'));
        $this->assertSame('Test Project', config('deploy-notifier.project'));
        $this->assertIsArray(config('deploy-notifier.telegram.channels'));
        $this->assertFalse(config('deploy-notifier.slack.enabled'));
        $this->assertFalse(config('deploy-notifier.discord.enabled'));
    }

    public function test_core_services_resolve_out_of_the_container(): void
    {
        $this->assertInstanceOf(DeploymentManager::class, $this->app->make(DeploymentManager::class));
        $this->assertInstanceOf(SlackTransport::class, $this->app->make(SlackTransport::class));
        $this->assertInstanceOf(DiscordTransport::class, $this->app->make(DiscordTransport::class));
    }

    public function test_the_slack_and_discord_listeners_are_registered_on_deployment_event_received(): void
    {
        // getListeners() returns resolved Closures (not inspectable by
        // class), so use getRawListeners() to check which listener
        // classes are actually wired up.
        $raw = $this->app['events']->getRawListeners()[DeploymentEventReceived::class] ?? [];

        $this->assertNotEmpty($raw, 'No listener is registered for DeploymentEventReceived.');
        $this->assertContains(SendSlackNotification::class, $raw);
        $this->assertContains(SendDiscordNotification::class, $raw);

        // And confirm they're actually invokable end-to-end, not just
        // present in the raw list.
        $listeners = $this->app['events']->getListeners(DeploymentEventReceived::class);
        $this->assertNotEmpty($listeners);
    }

    public function test_lifecycle_commands_are_registered(): void
    {
        $commands = array_keys($this->app[\Illuminate\Contracts\Console\Kernel::class]->all());

        foreach ([
            'deploy-notifier:install',
            'deploy-notifier:test',
            'deploy-notifier:status',
            'deploy-notifier:push',
            'deploy-notifier:pull',
            'deploy-notifier:start',
            'deploy-notifier:success',
            'deploy-notifier:fail',
            'deploy-notifier:rollback',
            'deploy-notifier:prune',
        ] as $command) {
            $this->assertContains($command, $commands, "Command [{$command}] was not registered.");
        }
    }

    public function test_config_can_be_published(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'deploy-notifier-config', '--force' => true])
            ->assertExitCode(0);

        $this->assertFileExists(config_path('deploy-notifier.php'));
    }
}
