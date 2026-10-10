<?php

namespace MSahidurr\DeployNotifier\Tests\Feature;

use MSahidurr\DeployNotifier\Services\DeploymentManager;
use MSahidurr\DeployNotifier\Tests\TestCase;
use MSahidurr\DeployNotifier\Transports\SlackTransport;

class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged_with_sensible_defaults(): void
    {
        $this->assertTrue(config('deploy-notifier.enabled'));
        $this->assertSame('Test Project', config('deploy-notifier.project'));
        $this->assertIsArray(config('deploy-notifier.telegram.channels'));
        $this->assertFalse(config('deploy-notifier.slack.enabled'));
    }

    public function test_core_services_resolve_out_of_the_container(): void
    {
        $this->assertInstanceOf(DeploymentManager::class, $this->app->make(DeploymentManager::class));
        $this->assertInstanceOf(SlackTransport::class, $this->app->make(SlackTransport::class));
    }

    public function test_the_slack_listener_is_registered_on_deployment_event_received(): void
    {
        $listeners = $this->app['events']->getListeners(\MSahidurr\DeployNotifier\Events\DeploymentEventReceived::class);

        $this->assertNotEmpty($listeners, 'No listener is registered for DeploymentEventReceived.');
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
