<?php

namespace MSahidurr\DeployNotifier\Commands;

use MSahidurr\DeployNotifier\DTOs\DeploymentEvent;

class RollbackCommand extends BaseCommand
{
    protected $signature = 'deploy-notifier:rollback
        {--deployment-id= : Correlation id of the original deployment being rolled back (required)}
        {--commit= : The commit the server was rolled back to; detected from Git when omitted}';

    protected $description = 'Record a deployment rollback and report it to Telegram';

    public function handle(): int
    {
        $deploymentId = $this->requireDeploymentId($this->option('deployment-id'));

        if ($deploymentId === null) {
            return self::FAILURE;
        }

        $git = $this->git();

        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_ROLLED_BACK,
            deploymentId: $deploymentId,
            project: $this->project(),
            environment: $this->environment(),
            server: $this->server(),
            branch: $git->currentBranch(),
            commit: $this->option('commit') ?: $git->currentCommit(),
        );

        $delivered = $this->manager()->record($event);
        $this->manager()->releaseLock($this->project(), $this->environment());

        $this->info("Rollback recorded for deployment: {$deploymentId}");
        $this->reportOutcome($delivered);

        return self::SUCCESS;
    }
}
