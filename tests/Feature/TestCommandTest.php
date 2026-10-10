<?php

namespace MSahidurr\DeployNotifier\Tests\Feature;

use Illuminate\Support\Facades\Http;
use MSahidurr\DeployNotifier\Tests\TestCase;

class TestCommandTest extends TestCase
{
    public function test_it_sends_a_test_message_to_the_resolved_destination(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('deploy-notifier:test')->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bottest-bot-token/sendMessage'
                && $request['chat_id'] === '111';
        });
    }

    public function test_it_fails_gracefully_when_telegram_times_out(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out.');
        });

        $this->artisan('deploy-notifier:test')->assertExitCode(1);
    }

    public function test_it_fails_when_no_destination_is_configured_for_the_environment(): void
    {
        $this->artisan('deploy-notifier:test', ['--environment' => 'nowhere'])
            ->assertExitCode(1);
    }

    public function test_it_refuses_to_run_when_the_notifier_is_disabled(): void
    {
        config(['deploy-notifier.enabled' => false]);

        $this->artisan('deploy-notifier:test')->assertExitCode(1);
    }
}
