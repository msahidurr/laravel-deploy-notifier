<?php

namespace MSahidurr\DeployNotifier\Tests\Unit;

use MSahidurr\DeployNotifier\Services\CacheEventStore;
use MSahidurr\DeployNotifier\Tests\TestCase;

class CacheEventStoreTest extends TestCase
{
    public function test_mark_seen_once_is_true_only_the_first_time(): void
    {
        $store = new CacheEventStore(store: null);

        $this->assertTrue($store->markSeenOnce('key-1', 60));
        $this->assertFalse($store->markSeenOnce('key-1', 60));
        $this->assertTrue($store->markSeenOnce('key-2', 60));
    }

    public function test_lock_cannot_be_acquired_twice_until_released(): void
    {
        $store = new CacheEventStore(store: null);

        $this->assertTrue($store->acquireLock('deploy-lock:app:production', 60));
        $this->assertFalse($store->acquireLock('deploy-lock:app:production', 60));

        $store->releaseLock('deploy-lock:app:production');

        $this->assertTrue($store->acquireLock('deploy-lock:app:production', 60));
    }
}
