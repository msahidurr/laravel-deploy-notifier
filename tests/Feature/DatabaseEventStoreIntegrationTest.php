<?php

namespace MSahidurr\DeployNotifier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MSahidurr\DeployNotifier\Contracts\EventStore;
use MSahidurr\DeployNotifier\Services\DatabaseEventStore;
use MSahidurr\DeployNotifier\Tests\TestCase;

class DatabaseEventStoreIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The pdo_sqlite extension is not available in this environment.');
        }

        parent::setUp();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('deploy-notifier.store.driver', 'database');
    }

    public function test_the_container_resolves_the_database_event_store_when_configured(): void
    {
        $this->assertInstanceOf(DatabaseEventStore::class, $this->app->make(EventStore::class));
    }

    public function test_prune_command_reports_how_many_rows_it_removed(): void
    {
        $this->app->make(EventStore::class)->markSeenOnce('expired', -10);

        $this->artisan('deploy-notifier:prune')
            ->expectsOutputToContain('Pruned 1 expired row')
            ->assertExitCode(0);
    }
}
