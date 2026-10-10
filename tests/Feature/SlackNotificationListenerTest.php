<?php

namespace MSahidurr\DeployNotifier\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use MSahidurr\DeployNotifier\Tests\TestCase;

class SlackNotificationListenerTest extends TestCase
{
    private const SLACK_WEBHOOK = 'https://hooks.slack.com/services/T000/B000/XXX';

    protected function setUp(): void
    {
        parent::setUp();

        Process::fake(['*' => Process::result(output: "a81d23fabc123\n")]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            self::SLACK_WEBHOOK => Http::response('ok', 200),
        ]);
    }

    public function test_slack_is_not_notified_when_disabled(): void
    {
        config(['deploy-notifier.slack.enabled' => false]);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        Http::assertNotSent(fn ($request) => $request->url() === self::SLACK_WEBHOOK);
        Http::assertSentCount(1); // Telegram only
    }

    public function test_slack_is_notified_alongside_telegram_when_enabled(): void
    {
        config([
            'deploy-notifier.slack.enabled' => true,
            'deploy-notifier.slack.webhooks.production' => self::SLACK_WEBHOOK,
        ]);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        Http::assertSentCount(2); // Telegram + Slack
        Http::assertSent(fn ($request) => $request->url() === self::SLACK_WEBHOOK
            && str_contains($request['text'] ?? '', 'DEPLOYMENT STARTED'));
    }

    public function test_slack_is_skipped_without_a_webhook_for_the_environment(): void
    {
        config([
            'deploy-notifier.slack.enabled' => true,
            'deploy-notifier.slack.webhooks.production' => null,
        ]);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        Http::assertSentCount(1); // Telegram only, Slack skipped
    }

    public function test_an_environment_name_containing_a_literal_dot_still_resolves_its_webhook(): void
    {
        // config()'s dot notation would otherwise misread an environment
        // like "client.staging" as the nested path webhooks->client->staging
        // rather than the flat key "client.staging" it actually is.
        config([
            'deploy-notifier.environment' => 'client.staging',
            'deploy-notifier.telegram.channels' => ['client.staging' => '999'],
            'deploy-notifier.slack.enabled' => true,
            'deploy-notifier.slack.webhooks' => ['client.staging' => self::SLACK_WEBHOOK],
        ]);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        Http::assertSentCount(2); // Telegram + Slack
        Http::assertSent(fn ($request) => $request->url() === self::SLACK_WEBHOOK);
    }

    public function test_a_failed_slack_delivery_does_not_affect_the_command_exit_code(): void
    {
        config([
            'deploy-notifier.slack.enabled' => true,
            'deploy-notifier.slack.webhooks.production' => self::SLACK_WEBHOOK,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            self::SLACK_WEBHOOK => Http::response('server_error', 500),
        ]);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);
    }
}
