<?php

namespace MSahidurr\DeployNotifier\Contracts;

interface EventStore
{
    /**
     * Atomically mark a key as seen. Returns true the first time a given
     * key is seen within the TTL, and false on every subsequent call -
     * used to deduplicate webhook deliveries and repeated lifecycle events.
     */
    public function markSeenOnce(string $key, int $ttlSeconds): bool;

    /**
     * Atomically acquire a simple cache-based mutex for $key. Returns
     * false if it is already held (i.e. another deployment for the
     * same project/environment is already in progress).
     *
     * Deliberately not owner-token based: a manual SSH deployment is a
     * single-writer workflow split across several CLI invocations
     * (start, pull, success/fail), so the lock must be releasable by a
     * later, separate process rather than only by the process that
     * acquired it.
     */
    public function acquireLock(string $key, int $ttlSeconds): bool;

    public function releaseLock(string $key): void;
}
