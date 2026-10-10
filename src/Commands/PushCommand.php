<?php

namespace MSahidurr\DeployNotifier\Commands;

use MSahidurr\DeployNotifier\DTOs\DeploymentEvent;
use MSahidurr\DeployNotifier\Support\DeploymentIdGenerator;

class PushCommand extends BaseCommand
{
    protected $signature = 'deploy-notifier:push
        {--branch= : The branch that was pushed}
        {--commit= : The commit SHA that was pushed}
        {--repository= : owner/repo, defaults to the configured remote}
        {--author= : Commit author}
        {--message= : Commit subject}';

    protected $description = 'Manually report a Git push (for testing only - prefer the signed GitHub webhook for real push detection)';

    public function handle(): int
    {
        $this->comment(
            'Note: this command only reports what you tell it. It is not proof that a remote push '.
            'actually happened - use the signed GitHub webhook (see config/deploy-notifier.php) for that.'
        );

        $git = $this->git();

        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_PUSH,
            deploymentId: 'manual-push-'.DeploymentIdGenerator::generate(),
            project: $this->project(),
            environment: $this->environment(),
            repository: $this->option('repository') ?: $git->remoteUrl(),
            branch: $this->option('branch') ?: $git->currentBranch(),
            commit: $this->option('commit') ?: $git->currentCommit(),
            author: $this->option('author') ?: $git->authorOfCommit(),
            commitMessage: $this->option('message') ?: $git->subjectOfCommit(),
        );

        $delivered = $this->manager()->record($event);

        $this->info('Push event recorded.');
        $this->reportOutcome($delivered);

        return self::SUCCESS;
    }
}
