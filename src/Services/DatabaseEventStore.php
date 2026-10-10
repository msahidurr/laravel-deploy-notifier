<?php

namespace MSahidurr\DeployNotifier\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use MSahidurr\DeployNotifier\Contracts\EventStore;

/**
 * Database-backed EventStore. An opt-in alternative to CacheEventStore
 * for deployments that want dedup/lock state to survive a cache flush,
 * or to be able to inspect it directly in a table. Requires the
 * published migration to have been run - see deploy-notifier:install.
 */
class DatabaseEventStore implements EventStore
{
    public function __construct(
        private readonly ?string $connection,
        private readonly string $table,
    ) {
    }

    public function markSeenOnce(string $key, int $ttlSeconds): bool
    {
        return $this->tryInsert('dedup', $key, $ttlSeconds);
    }

    public function acquireLock(string $key, int $ttlSeconds): bool
    {
        return $this->tryInsert('lock', $key, $ttlSeconds);
    }

    public function releaseLock(string $key): void
    {
        $this->query()->where('type', 'lock')->where('key', $key)->delete();
    }

    /**
     * Prune every expired row, regardless of type. Safe to call from a
     * scheduled command (see deploy-notifier:prune) - it never removes a
     * row that is still within its TTL, so it cannot release a lock or
     * clear a dedup marker early.
     */
    public function pruneExpired(): int
    {
        return $this->query()->where('expires_at', '<', Date::now())->delete();
    }

    private function tryInsert(string $type, string $key, int $ttlSeconds): bool
    {
        $now = Date::now();

        // Lazily clear a previous, now-expired row for this exact key so
        // the unique index doesn't block a legitimate re-acquisition.
        $this->query()
            ->where('type', $type)
            ->where('key', $key)
            ->where('expires_at', '<', $now)
            ->delete();

        try {
            $this->query()->insert([
                'type' => $type,
                'key' => $key,
                // A zero or negative TTL intentionally produces an
                // expires_at at or before "now" - i.e. already expired -
                // rather than being clamped to some minimum, so callers
                // get the semantics their TTL actually describes.
                'expires_at' => $now->copy()->addSeconds($ttlSeconds),
                'created_at' => $now,
            ]);

            return true;
        } catch (QueryException $exception) {
            // SQLSTATE class 23 is "integrity constraint violation" -
            // MySQL and SQLite report the unique-index collision we're
            // racing against as 23000, Postgres as the more specific
            // 23505. Either means another process already holds this
            // exact key; anything outside class 23 is a real database
            // problem and must surface instead of being swallowed here.
            if (str_starts_with((string) $exception->getCode(), '23')) {
                return false;
            }

            throw $exception;
        }
    }

    private function query()
    {
        return DB::connection($this->connection)->table($this->table);
    }
}
