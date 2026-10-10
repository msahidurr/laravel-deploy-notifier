<?php

namespace MSahidurr\DeployNotifier\Tests\Unit;

use Illuminate\Support\Facades\Log;
use MSahidurr\DeployNotifier\Services\NotificationRouter;
use MSahidurr\DeployNotifier\Tests\TestCase;

class NotificationRouterTest extends TestCase
{
    public function test_it_resolves_a_configured_destination(): void
    {
        $router = new NotificationRouter(['production' => '555']);

        $this->assertSame('555', $router->destinationFor('production'));
    }

    public function test_it_returns_null_and_logs_a_warning_when_unconfigured(): void
    {
        Log::spy();

        $router = new NotificationRouter(['production' => '555']);

        $this->assertNull($router->destinationFor('staging'));

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_it_treats_an_empty_string_destination_as_unconfigured(): void
    {
        $router = new NotificationRouter(['production' => '']);

        $this->assertNull($router->destinationFor('production'));
    }

    public function test_it_coerces_a_numeric_chat_id_hardcoded_as_an_int_to_a_string(): void
    {
        // A Telegram group chat id is negative and numeric (e.g.
        // -1001234567890); easy to write as a literal int if someone
        // edits the published config file directly instead of using env().
        $router = new NotificationRouter(['production' => -1001234567890]);

        $this->assertSame('-1001234567890', $router->destinationFor('production'));
    }
}
