<?php

namespace MSahidurr\DeployNotifier\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MSahidurr\DeployNotifier\Transports\SlackTransport;
use MSahidurr\DeployNotifier\Tests\TestCase;

class SlackTransportTest extends TestCase
{
    private const WEBHOOK_URL = 'https://hooks.slack.com/services/T000/B000/SECRET-SLACK-TOKEN';

    public function test_it_delivers_successfully(): void
    {
        Http::fake([self::WEBHOOK_URL => Http::response('ok', 200)]);

        $transport = new SlackTransport();

        $this->assertTrue($transport->send(self::WEBHOOK_URL, 'hello'));

        Http::assertSent(fn ($request) => $request->url() === self::WEBHOOK_URL && $request['text'] === 'hello');
    }

    public function test_it_skips_delivery_with_an_empty_destination(): void
    {
        Http::fake();

        $transport = new SlackTransport();

        $this->assertFalse($transport->send('', 'hello'));
        Http::assertNothingSent();
    }

    public function test_it_fails_gracefully_on_an_error_response(): void
    {
        Http::fake([self::WEBHOOK_URL => Http::response('invalid_payload', 400)]);

        $transport = new SlackTransport(retryTimes: 1);

        $this->assertFalse($transport->send(self::WEBHOOK_URL, 'hello'));
    }

    public function test_the_webhook_url_never_reaches_the_log_on_a_connection_exception(): void
    {
        Log::spy();

        Http::fake(function () {
            throw new ConnectionException(
                'cURL error 28: Connection timed out after 5000 milliseconds for '.self::WEBHOOK_URL
            );
        });

        $transport = new SlackTransport(retryTimes: 1);
        $transport->send(self::WEBHOOK_URL, 'hello');

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context) {
            return ! str_contains(json_encode($context), 'SECRET-SLACK-TOKEN');
        })->once();
    }
}
