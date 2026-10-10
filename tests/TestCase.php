<?php

namespace MSahidurr\DeployNotifier\Tests;

use MSahidurr\DeployNotifier\DeployNotifierServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DeployNotifierServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');

        // Force an isolated in-memory SQLite connection for every test,
        // regardless of whatever database.* env vars happen to be set on
        // the host - the database-backed EventStore tests must never
        // touch a real database.
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('deploy-notifier.project', 'Test Project');
        $app['config']->set('deploy-notifier.environment', 'production');
        $app['config']->set('deploy-notifier.server', 'test-server');
        $app['config']->set('deploy-notifier.telegram.bot_token', 'test-bot-token');
        $app['config']->set('deploy-notifier.telegram.channels', [
            'production' => '111',
            'staging' => '222',
        ]);
    }
}
