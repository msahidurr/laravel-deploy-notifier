<?php

namespace MSahidurr\DeployNotifier\Commands;

use MSahidurr\DeployNotifier\DTOs\DeploymentEvent;

class SuccessCommand extends BaseCommand
{
    protected $signature = 'deploy-notifier:success {--deployment-id= : Correlation id matching the :start call (required)}';

    protected $description = 'Record a successful deployment and report it to Telegram';

    public function handle(): int
    {
        $deploymentId = $this->requireDeploymentId($this->option('deployment-id'));

        if ($deploymentId === null) {
            return self::FAILURE;
        }

        $git = $this->git();

        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_SUCCEEDED,
            deploymentId: $deploymentId,
            project: $this->project(),
            environment: $this->environment(),
            server: $this->server(),
            branch: $git->currentBranch(),
            commit: $git->currentCommit(),
        );

        $delivered = $this->manager()->record($event);
        $this->manager()->releaseLock($this->project(), $this->environment());

        $this->info("Deployment succeeded: {$deploymentId}");
        $this->reportOutcome($delivered);

        return self::SUCCESS;
    }
}
