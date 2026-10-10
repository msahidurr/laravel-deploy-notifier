<?php

namespace MSahidurr\DeployNotifier\Commands;

use MSahidurr\DeployNotifier\DTOs\DeploymentEvent;

class FailCommand extends BaseCommand
{
    protected $signature = 'deploy-notifier:fail
        {--deployment-id= : Correlation id matching the :start call (required)}
        {--step= : The deployment step that failed, e.g. "composer install"}
        {--exit-code= : The exit code of the step that failed}';

    protected $description = 'Record a failed deployment and report it to Telegram';

    public function handle(): int
    {
        $deploymentId = $this->requireDeploymentId($this->option('deployment-id'));

        if ($deploymentId === null) {
            // Still self::FAILURE, not an exception: a deployment script
            // calling this through the recommended `notify` wrapper (see
            // examples/deploy.sh) already discards this exit code, so
            // returning it here cannot affect the deployment's own
            // outcome - it only surfaces the usage mistake when run
            // directly or by hand.
            return self::FAILURE;
        }

        $git = $this->git();

        $exitCode = $this->option('exit-code');

        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_FAILED,
            deploymentId: $deploymentId,
            project: $this->project(),
            environment: $this->environment(),
            server: $this->server(),
            branch: $git->currentBranch(),
            commit: $git->currentCommit(),
            step: $this->option('step'),
            exitCode: $exitCode !== null ? (int) $exitCode : null,
        );

        // Deliberately does not use the deployment transport's own
        // exception path to notify about its own failure - delivery
        // problems here are only ever logged locally (see
        // TelegramTransport::send), never re-reported as another
        // deployment failure. That would risk a notification loop.
        $delivered = $this->manager()->record($event);
        $this->manager()->releaseLock($this->project(), $this->environment());

        $this->warn("Deployment failed: {$deploymentId}");
        $this->reportOutcome($delivered);

        // This command only reports; it never changes its own exit
        // status based on the deployment's outcome, so a deployment
        // script's trap handler is free to preserve the original
        // failing exit code after calling this.
        return self::SUCCESS;
    }
}
