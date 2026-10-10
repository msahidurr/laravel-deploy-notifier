<?php

namespace MSahidurr\DeployNotifier\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MSahidurr\DeployNotifier\Transports\DiscordTransport;
use MSahidurr\DeployNotifier\Tests\TestCase;

class DiscordTransportTest extends TestCase
{
    private const WEBHOOK_URL = 'https://discord.com/api/webhooks/123456/SECRET-DISCORD-TOKEN';

    public function test_it_delivers_successfully(): void
    {
        Http::fake([self::WEBHOOK_URL => Http::response('', 204)]);

        $transport = new DiscordTransport();

        $this->assertTrue($transport->send(self::WEBHOOK_URL, 'hello'));

        Http::assertSent(fn ($request) => $request->url() === self::WEBHOOK_URL && $request['content'] === 'hello');
    }

    public function test_it_skips_delivery_with_an_empty_destination(): void
    {
        Http::fake();

        $transport = new DiscordTransport();

        $this->assertFalse($transport->send('', 'hello'));
        Http::assertNothingSent();
    }

    public function test_it_fails_gracefully_on_an_error_response(): void
    {
        Http::fake([self::WEBHOOK_URL => Http::response('invalid_payload', 400)]);

        $transport = new DiscordTransport(retryTimes: 1);

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

        $transport = new DiscordTransport(retryTimes: 1);
        $transport->send(self::WEBHOOK_URL, 'hello');

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context) {
            return ! str_contains(json_encode($context), 'SECRET-DISCORD-TOKEN');
        })->once();
    }

    public function test_content_is_truncated_to_discords_2000_character_limit(): void
    {
        Http::fake([self::WEBHOOK_URL => Http::response('', 204)]);

        $transport = new DiscordTransport();
        $transport->send(self::WEBHOOK_URL, str_repeat('x', 5000));

        // mb_strlen, not strlen: the truncation is character-width based
        // (matching Discord's 2000-*character* limit), and the "…"
        // trimmer is a multi-byte character, so the byte length can
        // exceed 2000 even though the character count does not.
        Http::assertSent(fn ($request) => mb_strlen($request['content']) <= 2000);
    }
}
