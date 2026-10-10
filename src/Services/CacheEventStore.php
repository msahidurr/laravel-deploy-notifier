<?php

namespace MSahidurr\DeployNotifier\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use MSahidurr\DeployNotifier\Contracts\EventStore;

/**
 * Cache-backed implementation of EventStore. Good enough for the MVP:
 * single-cache-store deduplication and a simple mutex, no durable
 * history. Swap the binding for a database-backed implementation
 * later without touching any caller.
 */
class CacheEventStore implements EventStore
{
    private readonly CacheRepository $cache;

    public function __construct(
        ?string $store,
        private readonly string $prefix = 'deploy-notifier:dedup:',
    ) {
        $this->cache = Cache::store($store);
    }

    public function markSeenOnce(string $key, int $ttlSeconds): bool
    {
        // Cache::add() is atomic: it only succeeds if the key is not
        // already present, which is exactly the "first time only"
        // semantics deduplication needs.
        return $this->cache->add($this->prefix.$key, true, $ttlSeconds);
    }

    public function acquireLock(string $key, int $ttlSeconds): bool
    {
        return $this->cache->add($this->prefix.'lock:'.$key, true, $ttlSeconds);
    }

    public function releaseLock(string $key): void
    {
        $this->cache->forget($this->prefix.'lock:'.$key);
    }
}
