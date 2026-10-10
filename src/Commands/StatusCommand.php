<?php

namespace MSahidurr\DeployNotifier\Commands;

class StatusCommand extends BaseCommand
{
    protected $signature = 'deploy-notifier:status';

    protected $description = 'Show the current deploy-notifier configuration and Git diagnostics';

    public function handle(): int
    {
        $this->components->twoColumnDetail('Enabled', config('deploy-notifier.enabled', true) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Project', $this->project() ?: '<not set>');
        $this->components->twoColumnDetail('Environment', $this->environment() ?: '<not set>');
        $this->components->twoColumnDetail('Server', $this->server() ?: '<not set>');

        $this->newLine();
        $this->line('<fg=yellow>Git</>');
        $repositoryPath = (string) config('deploy-notifier.git.repository_path');
        $this->components->twoColumnDetail('Repository path', $repositoryPath);

        $git = $this->git();

        if ($git->isValidRepository()) {
            $this->components->twoColumnDetail('Valid repository', 'yes');
            $this->components->twoColumnDetail('Current branch', $git->currentBranch() ?? '<unknown>');
            $this->components->twoColumnDetail('Current commit', $git->currentCommit() ?? '<unknown>');
        } else {
            $this->components->twoColumnDetail('Valid repository', '<fg=red>no</>');
        }

        $this->newLine();
        $this->line('<fg=yellow>Telegram</>');
        $this->components->twoColumnDetail('Bot token', config('deploy-notifier.telegram.bot_token') ? 'configured' : '<fg=red>missing</>');

        foreach ((array) config('deploy-notifier.telegram.channels', []) as $environment => $chatId) {
            $this->components->twoColumnDetail("Channel [{$environment}]", $chatId ? 'configured' : '<fg=red>not set</>');
        }

        $this->newLine();
        $this->line('<fg=yellow>GitHub webhook</>');
        $this->components->twoColumnDetail('Webhook secret', config('deploy-notifier.github.webhook_secret') ? 'configured' : 'not set (webhook route disabled)');
        $this->components->twoColumnDetail('Webhook path', '/'.trim((string) config('deploy-notifier.github.webhook_path'), '/'));
        $allowed = array_filter((array) config('deploy-notifier.github.allowed_repositories', []));
        $this->components->twoColumnDetail('Allowed repositories', $allowed === [] ? '<fg=red>none (all repositories accepted)</>' : implode(', ', $allowed));

        $this->newLine();
        $this->line('<fg=yellow>Safety</>');
        $this->components->twoColumnDetail('Deduplication', config('deploy-notifier.deduplication.enabled', true) ? 'enabled' : 'disabled');
        $this->components->twoColumnDetail('Deployment locking', config('deploy-notifier.locking.enabled', true) ? 'enabled' : 'disabled');

        $storeDriver = config('deploy-notifier.store.driver', 'cache');
        $this->components->twoColumnDetail('Event store', $storeDriver);

        if ($storeDriver === 'database') {
            $this->components->twoColumnDetail(
                'Event store table',
                config('deploy-notifier.store.database.table', 'deploy_notifier_events')
                .' (run vendor:publish --tag=deploy-notifier-migrations + migrate if you have not yet)'
            );
        }

        return self::SUCCESS;
    }
}
