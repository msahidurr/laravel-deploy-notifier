<?php

namespace MSahidurr\DeployNotifier\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MSahidurr\DeployNotifier\Services\DatabaseEventStore;
use MSahidurr\DeployNotifier\Tests\TestCase;

class DatabaseEventStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // These tests exercise a real schema, so they need a database
        // connection. SQLite in-memory keeps that cheap and self-contained
        // without requiring a real server - skip gracefully if the
        // environment's PHP build doesn't have pdo_sqlite, rather than
        // failing for a reason unrelated to the code under test.
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

    private function store(): DatabaseEventStore
    {
        return new DatabaseEventStore(connection: null, table: 'deploy_notifier_events');
    }

    public function test_mark_seen_once_is_true_only_the_first_time(): void
    {
        $store = $this->store();

        $this->assertTrue($store->markSeenOnce('key-1', 3600));
        $this->assertFalse($store->markSeenOnce('key-1', 3600));
        $this->assertTrue($store->markSeenOnce('key-2', 3600));

        $this->assertDatabaseHas('deploy_notifier_events', ['type' => 'dedup', 'key' => 'key-1']);
    }

    public function test_an_expired_dedup_key_can_be_marked_seen_again(): void
    {
        $store = $this->store();

        $this->assertTrue($store->markSeenOnce('key-1', -10)); // already expired

        $this->assertTrue($store->markSeenOnce('key-1', 3600));
    }

    public function test_lock_cannot_be_acquired_twice_until_released(): void
    {
        $store = $this->store();

        $this->assertTrue($store->acquireLock('deploy-lock:app:production', 3600));
        $this->assertFalse($store->acquireLock('deploy-lock:app:production', 3600));

        $store->releaseLock('deploy-lock:app:production');

        $this->assertTrue($store->acquireLock('deploy-lock:app:production', 3600));
    }

    public function test_dedup_and_lock_keys_do_not_collide_with_each_other(): void
    {
        $store = $this->store();

        $this->assertTrue($store->markSeenOnce('same-key', 3600));
        $this->assertTrue($store->acquireLock('same-key', 3600));
    }

    public function test_prune_expired_removes_only_expired_rows(): void
    {
        $store = $this->store();

        $store->markSeenOnce('expired', -10);
        $store->markSeenOnce('still-valid', 3600);

        $deleted = $store->pruneExpired();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('deploy_notifier_events', ['key' => 'expired']);
        $this->assertDatabaseHas('deploy_notifier_events', ['key' => 'still-valid']);
    }
}
