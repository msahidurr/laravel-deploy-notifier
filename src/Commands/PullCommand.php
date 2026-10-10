<?php

namespace MSahidurr\DeployNotifier\Commands;

use MSahidurr\DeployNotifier\DTOs\DeploymentEvent;

class PullCommand extends BaseCommand
{
    protected $signature = 'deploy-notifier:pull
        {--deployment-id= : Correlation id matching the :start call (required)}
        {--previous-commit= : The commit SHA the server was on before the pull}
        {--exit-code=0 : The exit code of the `git pull` command itself}';

    protected $description = 'Record the outcome of a server-side Git pull and report it to Telegram';

    public function handle(): int
    {
        $deploymentId = $this->requireDeploymentId($this->option('deployment-id'));

        if ($deploymentId === null) {
            return self::FAILURE;
        }

        $git = $this->git();

        $currentCommit = $git->currentCommit();
        $previousCommit = $this->option('previous-commit');

        $newCommitCount = null;

        if ($previousCommit && $currentCommit) {
            $newCommitCount = $git->countCommitsBetween($previousCommit, $currentCommit);
        }

        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_PULL,
            deploymentId: $deploymentId,
            project: $this->project(),
            environment: $this->environment(),
            server: $this->server(),
            branch: $git->currentBranch(),
            commit: $currentCommit,
            previousCommit: $previousCommit,
            newCommitCount: $newCommitCount,
            exitCode: (int) $this->option('exit-code'),
        );

        $delivered = $this->manager()->record($event);

        $this->info("Git pull recorded for deployment: {$deploymentId}");
        $this->reportOutcome($delivered);

        return self::SUCCESS;
    }
}
