<?php

namespace MSahidurr\DeployNotifier\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MSahidurr\DeployNotifier\Transports\TelegramTransport;
use MSahidurr\DeployNotifier\Tests\TestCase;

class TelegramTransportTest extends TestCase
{
    public function test_it_delivers_successfully(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $transport = new TelegramTransport(botToken: 'secret-token');

        $this->assertTrue($transport->send('111', 'hello'));
    }

    public function test_it_retries_a_transient_server_error_then_succeeds(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false], 500)
            ->push(['ok' => true], 200)]);

        $transport = new TelegramTransport(botToken: 'secret-token', retryTimes: 2, retrySleepMs: 1);

        $this->assertTrue($transport->send('111', 'hello'));
        Http::assertSentCount(2);
    }

    public function test_it_gives_up_after_exhausting_retries_on_persistent_server_errors(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);

        $transport = new TelegramTransport(botToken: 'secret-token', retryTimes: 3, retrySleepMs: 1);

        $this->assertFalse($transport->send('111', 'hello'));
        Http::assertSentCount(3); // retryTimes is the total number of attempts, not additional retries
    }

    public function test_the_bot_token_never_reaches_the_log_on_an_api_error_response(): void
    {
        Log::spy();

        Http::fake(['api.telegram.org/*' => Http::response('bad request: chat not found for bot123456:SECRET-TOKEN', 400)]);

        $transport = new TelegramTransport(botToken: 'bot123456:SECRET-TOKEN');
        $transport->send('111', 'hello');

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context) {
            return ! str_contains(json_encode($context), 'SECRET-TOKEN');
        })->once();
    }

    public function test_the_bot_token_never_reaches_the_log_on_a_connection_exception(): void
    {
        Log::spy();

        Http::fake(function () {
            throw new ConnectionException(
                'cURL error 28: Connection timed out after 5000 milliseconds for https://api.telegram.org/botbot123456:SECRET-TOKEN/sendMessage'
            );
        });

        $transport = new TelegramTransport(botToken: 'bot123456:SECRET-TOKEN');
        $transport->send('111', 'hello');

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context) {
            return ! str_contains(json_encode($context), 'SECRET-TOKEN');
        })->once();
    }

    public function test_it_skips_delivery_without_a_bot_token(): void
    {
        Http::fake();

        $transport = new TelegramTransport(botToken: null);

        $this->assertFalse($transport->send('111', 'hello'));
        Http::assertNothingSent();
    }

    public function test_it_skips_delivery_with_an_empty_destination(): void
    {
        Http::fake();

        $transport = new TelegramTransport(botToken: 'secret-token');

        $this->assertFalse($transport->send('', 'hello'));
        Http::assertNothingSent();
    }
}
