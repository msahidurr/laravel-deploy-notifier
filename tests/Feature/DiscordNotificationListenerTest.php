<?php

namespace MSahidurr\DeployNotifier\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use MSahidurr\DeployNotifier\Tests\TestCase;

class DiscordNotificationListenerTest extends TestCase
{
    private const DISCORD_WEBHOOK = 'https://discord.com/api/webhooks/123456/XXX';

    protected function setUp(): void
    {
        parent::setUp();

        Process::fake(['*' => Process::result(output: "a81d23fabc123\n")]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            self::DISCORD_WEBHOOK => Http::response('', 204),
        ]);
    }

    public function test_discord_is_not_notified_when_disabled(): void
    {
        config(['deploy-notifier.discord.enabled' => false]);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        Http::assertNotSent(fn ($request) => $request->url() === self::DISCORD_WEBHOOK);
        Http::assertSentCount(1); // Telegram only
    }

    public function test_discord_is_notified_alongside_telegram_when_enabled(): void
    {
        config([
            'deploy-notifier.discord.enabled' => true,
            'deploy-notifier.discord.webhooks.production' => self::DISCORD_WEBHOOK,
        ]);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        Http::assertSentCount(2); // Telegram + Discord
        Http::assertSent(fn ($request) => $request->url() === self::DISCORD_WEBHOOK
            && str_contains($request['content'] ?? '', 'DEPLOYMENT STARTED'));
    }

    public function test_slack_and_discord_can_both_be_enabled_alongside_telegram(): void
    {
        config([
            'deploy-notifier.slack.enabled' => true,
            'deploy-notifier.slack.webhooks.production' => 'https://hooks.slack.com/services/T000/B000/XXX',
            'deploy-notifier.discord.enabled' => true,
            'deploy-notifier.discord.webhooks.production' => self::DISCORD_WEBHOOK,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://hooks.slack.com/*' => Http::response('ok', 200),
            self::DISCORD_WEBHOOK => Http::response('', 204),
        ]);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        Http::assertSentCount(3); // Telegram + Slack + Discord
    }

    public function test_discord_is_skipped_without_a_webhook_for_the_environment(): void
    {
        config([
            'deploy-notifier.discord.enabled' => true,
            'deploy-notifier.discord.webhooks.production' => null,
        ]);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        Http::assertSentCount(1); // Telegram only, Discord skipped
    }

    public function test_an_environment_name_containing_a_literal_dot_still_resolves_its_webhook(): void
    {
        config([
            'deploy-notifier.environment' => 'client.staging',
            'deploy-notifier.telegram.channels' => ['client.staging' => '999'],
            'deploy-notifier.discord.enabled' => true,
            'deploy-notifier.discord.webhooks' => ['client.staging' => self::DISCORD_WEBHOOK],
        ]);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        Http::assertSentCount(2); // Telegram + Discord
        Http::assertSent(fn ($request) => $request->url() === self::DISCORD_WEBHOOK);
    }

    public function test_a_failed_discord_delivery_does_not_affect_the_command_exit_code(): void
    {
        config([
            'deploy-notifier.discord.enabled' => true,
            'deploy-notifier.discord.webhooks.production' => self::DISCORD_WEBHOOK,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            self::DISCORD_WEBHOOK => Http::response('server_error', 500),
        ]);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);
    }
}
