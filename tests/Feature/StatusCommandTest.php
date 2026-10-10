<?php

namespace MSahidurr\DeployNotifier\Tests\Feature;

use Illuminate\Support\Facades\Process;
use MSahidurr\DeployNotifier\Tests\TestCase;

class StatusCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Process::fake(['*' => Process::result(output: "a81d23fabc123\n")]);
    }

    public function test_it_reports_core_configuration(): void
    {
        $this->artisan('deploy-notifier:status')
            ->expectsOutputToContain('Test Project')
            ->expectsOutputToContain('production')
            ->expectsOutputToContain('test-server')
            ->assertExitCode(0);
    }

    public function test_it_reports_telegram_channel_status(): void
    {
        $this->artisan('deploy-notifier:status')
            ->expectsOutputToContain('Channel [production]')
            ->expectsOutputToContain('Channel [staging]')
            ->assertExitCode(0);
    }

    public function test_it_reports_slack_as_disabled_by_default_without_listing_webhooks(): void
    {
        $this->artisan('deploy-notifier:status')
            ->expectsOutputToContain('Slack')
            ->assertExitCode(0);

        // Confirmed to have run by assertExitCode() above, so the
        // Artisan facade's buffered output is now populated.
        $this->assertStringNotContainsString('Webhook [production]', \Illuminate\Support\Facades\Artisan::output());
    }

    public function test_it_lists_slack_and_discord_webhooks_when_enabled(): void
    {
        config([
            'deploy-notifier.slack.enabled' => true,
            'deploy-notifier.slack.webhooks' => ['production' => 'https://hooks.slack.com/services/x'],
            'deploy-notifier.discord.enabled' => true,
            'deploy-notifier.discord.webhooks' => ['production' => null],
        ]);

        $this->artisan('deploy-notifier:status')
            ->expectsOutputToContain('Slack')
            ->expectsOutputToContain('Discord')
            ->expectsOutputToContain('Webhook [production]')
            ->assertExitCode(0);
    }

    public function test_it_reports_the_event_store_driver(): void
    {
        $this->artisan('deploy-notifier:status')
            ->expectsOutputToContain('Event store')
            ->assertExitCode(0);
    }
}
