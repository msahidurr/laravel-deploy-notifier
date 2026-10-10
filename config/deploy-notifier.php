<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / disable
    |--------------------------------------------------------------------------
    |
    | When disabled, every command and webhook endpoint provided by this
    | package becomes a safe no-op. Nothing is sent, nothing is logged
    | as an error, and deployment scripts are never blocked.
    |
    */

    'enabled' => env('DEPLOY_NOTIFIER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Project identity
    |--------------------------------------------------------------------------
    */

    'project' => env('DEPLOY_NOTIFIER_PROJECT', env('APP_NAME', 'Laravel')),

    'environment' => env(
        'DEPLOY_NOTIFIER_ENV',
        env('APP_ENV', 'production')
    ),

    'server' => env(
        'DEPLOY_NOTIFIER_SERVER',
        gethostname() ?: 'unknown-server'
    ),

    /*
    |--------------------------------------------------------------------------
    | Git
    |--------------------------------------------------------------------------
    */

    'git' => [
        'repository_path' => env(
            'DEPLOY_NOTIFIER_REPOSITORY_PATH',
            base_path()
        ),
        'remote' => env('DEPLOY_NOTIFIER_GIT_REMOTE', 'origin'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram transport
    |--------------------------------------------------------------------------
    */

    'telegram' => [
        'bot_token' => env('DEPLOY_TELEGRAM_BOT_TOKEN'),
        'timeout' => (int) env('DEPLOY_TELEGRAM_TIMEOUT', 5),
        'retry_times' => (int) env('DEPLOY_TELEGRAM_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('DEPLOY_TELEGRAM_RETRY_SLEEP_MS', 250),

        // Environment name => Telegram chat id. Add as many as you need.
        'channels' => [
            'production' => env('DEPLOY_TELEGRAM_PRODUCTION_CHAT_ID'),
            'staging' => env('DEPLOY_TELEGRAM_STAGING_CHAT_ID'),
            'development' => env('DEPLOY_TELEGRAM_DEVELOPMENT_CHAT_ID'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Slack transport (optional, additional to Telegram)
    |--------------------------------------------------------------------------
    |
    | Disabled by default. When enabled, every event already delivered
    | through Telegram is also posted to the matching Slack incoming
    | webhook - this does not replace Telegram, it supplements it. See
    | SendSlackNotification, which listens for DeploymentEventReceived.
    |
    */

    'slack' => [
        'enabled' => env('DEPLOY_SLACK_ENABLED', false),
        'timeout' => (int) env('DEPLOY_SLACK_TIMEOUT', 5),
        'retry_times' => (int) env('DEPLOY_SLACK_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('DEPLOY_SLACK_RETRY_SLEEP_MS', 250),

        // Environment name => incoming webhook URL. The URL itself is
        // the secret - Slack has no separate token - so treat it like
        // one (keep it out of version control, rotate it if it leaks).
        'webhooks' => [
            'production' => env('DEPLOY_SLACK_PRODUCTION_WEBHOOK_URL'),
            'staging' => env('DEPLOY_SLACK_STAGING_WEBHOOK_URL'),
            'development' => env('DEPLOY_SLACK_DEVELOPMENT_WEBHOOK_URL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Discord transport (optional, additional to Telegram)
    |--------------------------------------------------------------------------
    |
    | Disabled by default. Independent of Slack - Telegram, Slack and
    | Discord can all be enabled at once. See SendDiscordNotification,
    | which listens for DeploymentEventReceived.
    |
    */

    'discord' => [
        'enabled' => env('DEPLOY_DISCORD_ENABLED', false),
        'timeout' => (int) env('DEPLOY_DISCORD_TIMEOUT', 5),
        'retry_times' => (int) env('DEPLOY_DISCORD_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('DEPLOY_DISCORD_RETRY_SLEEP_MS', 250),

        // Environment name => incoming webhook URL. The URL itself is
        // the secret - Discord has no separate token - so treat it like
        // one (keep it out of version control, rotate it if it leaks).
        'webhooks' => [
            'production' => env('DEPLOY_DISCORD_PRODUCTION_WEBHOOK_URL'),
            'staging' => env('DEPLOY_DISCORD_STAGING_WEBHOOK_URL'),
            'development' => env('DEPLOY_DISCORD_DEVELOPMENT_WEBHOOK_URL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub push webhook
    |--------------------------------------------------------------------------
    */

    'github' => [
        'webhook_secret' => env('DEPLOY_GITHUB_WEBHOOK_SECRET'),

        // URI the webhook is registered on, relative to the app root.
        'webhook_path' => env('DEPLOY_GITHUB_WEBHOOK_PATH', 'webhooks/deploy-notifier/github'),

        // comma separated "owner/repo" values, e.g. "acme/example-app,acme/example-api"
        'allowed_repositories' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DEPLOY_GITHUB_ALLOWED_REPOSITORIES', ''))
        ))),

        // Maximum accepted raw request body size, in bytes.
        'max_payload_bytes' => (int) env('DEPLOY_GITHUB_MAX_PAYLOAD_BYTES', 1_000_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication
    |--------------------------------------------------------------------------
    |
    | Applies to both incoming webhook deliveries (by GitHub's delivery id)
    | and locally-dispatched deployment events (by deployment id + type).
    | Backed by whichever store is selected below.
    |
    */

    'deduplication' => [
        'enabled' => true,
        'ttl' => (int) env('DEPLOY_NOTIFIER_DEDUP_TTL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deployment locking
    |--------------------------------------------------------------------------
    |
    | Prevents two deployments from running concurrently for the same
    | project/environment pair. Backed by whichever store is selected below.
    |
    */

    'locking' => [
        'enabled' => true,
        'ttl' => (int) env('DEPLOY_NOTIFIER_LOCK_TTL', 1800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event store
    |--------------------------------------------------------------------------
    |
    | Where deduplication markers and the deployment lock live. "cache" is
    | the zero-setup MVP default - good enough for a single app server with
    | a shared cache (Redis/Memcached/database cache driver). Switch to
    | "database" if you want dedup/lock state to survive a cache flush, or
    | to see it directly in a table rather than trusting cache internals.
    |
    */

    'store' => [
        'driver' => env('DEPLOY_NOTIFIER_STORE_DRIVER', 'cache'), // 'cache' or 'database'

        'cache' => [
            'store' => env('DEPLOY_NOTIFIER_CACHE_STORE'),
            'prefix' => 'deploy-notifier:dedup:',
        ],

        'database' => [
            'connection' => env('DEPLOY_NOTIFIER_DB_CONNECTION'),
            'table' => env('DEPLOY_NOTIFIER_DB_TABLE', 'deploy_notifier_events'),
        ],
    ],

];
