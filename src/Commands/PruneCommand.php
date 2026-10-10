<?php

namespace MSahidurr\DeployNotifier\Commands;

use MSahidurr\DeployNotifier\Contracts\EventStore;
use MSahidurr\DeployNotifier\Services\DatabaseEventStore;

class PruneCommand extends BaseCommand
{
    protected $signature = 'deploy-notifier:prune';

    protected $description = 'Delete expired dedup/lock rows (only relevant when the database event store is used)';

    public function handle(EventStore $store): int
    {
        if (! $store instanceof DatabaseEventStore) {
            $this->info('deploy-notifier is using the cache event store, which expires entries on its own - nothing to prune.');

            return self::SUCCESS;
        }

        $deleted = $store->pruneExpired();

        $this->info("Pruned {$deleted} expired row(s).");

        return self::SUCCESS;
    }
}
