<?php

namespace MSahidurr\DeployNotifier\Tests\Feature;

use MSahidurr\DeployNotifier\Tests\TestCase;

class PruneCommandTest extends TestCase
{
    public function test_it_is_a_no_op_on_the_cache_store(): void
    {
        $this->artisan('deploy-notifier:prune')
            ->expectsOutputToContain('cache event store')
            ->assertExitCode(0);
    }
}
