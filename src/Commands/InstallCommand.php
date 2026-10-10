<?php

namespace MSahidurr\DeployNotifier\Commands;

class InstallCommand extends BaseCommand
{
    protected $signature = 'deploy-notifier:install {--force : Overwrite the published config file if it already exists}';

    protected $description = 'Publish the deploy-notifier configuration and print setup guidance';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'deploy-notifier-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->newLine();
        $this->info('deploy-notifier installed.');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Set DEPLOY_TELEGRAM_BOT_TOKEN and at least one *_CHAT_ID variable in .env.');
        $this->line('  2. Run: php artisan deploy-notifier:test');
        $this->line('  3. Run: php artisan deploy-notifier:status');
        $this->line('  4. Wire deploy-notifier:start / :success / :fail into your deployment script.');
        $this->line('  5. (Optional) Set DEPLOY_GITHUB_WEBHOOK_SECRET and register the webhook on GitHub');
        $this->line('     to receive push notifications. See config/deploy-notifier.php for the webhook path.');
        $this->line('  6. (Optional) Set DEPLOY_NOTIFIER_STORE_DRIVER=database for dedup/lock state that');
        $this->line('     survives a cache flush, then run:');
        $this->line('       php artisan vendor:publish --tag=deploy-notifier-migrations');
        $this->line('       php artisan migrate');

        return self::SUCCESS;
    }
}
