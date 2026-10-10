<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the optional database EventStore (config('deploy-notifier.store.driver') === 'database').
     * Holds both dedup markers and the deployment lock, distinguished by `type`.
     * Rows self-expire via `expires_at`; stale rows for a given key are
     * cleaned up lazily on the next write to that key, and the
     * `deploy-notifier:prune` command can be scheduled to sweep the rest.
     */

    // Read by the base Migration class's own getConnection() - setting
    // it here (rather than overriding that method) is what tells the
    // migrator which connection to run this migration against.
    protected $connection;

    public function __construct()
    {
        $this->connection = config('deploy-notifier.store.database.connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create(
            config('deploy-notifier.store.database.table', 'deploy_notifier_events'),
            function (Blueprint $table) {
                $table->id();
                $table->string('type', 16); // 'dedup' or 'lock'
                $table->string('key', 191);
                $table->timestamp('expires_at');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['type', 'key']);
                $table->index('expires_at');
            }
        );
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(
            config('deploy-notifier.store.database.table', 'deploy_notifier_events')
        );
    }
};
