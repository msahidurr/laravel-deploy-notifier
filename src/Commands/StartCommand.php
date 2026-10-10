<?php

namespace MSahidurr\DeployNotifier\Commands;

use MSahidurr\DeployNotifier\DTOs\DeploymentEvent;

class StartCommand extends BaseCommand
{
    protected $signature = 'deploy-notifier:start {--deployment-id= : Correlation id for this deployment; generated if omitted}';

    protected $description = 'Record the start of a deployment and report it to Telegram';

    public function handle(): int
    {
        $deploymentId = $this->resolveDeploymentId($this->option('deployment-id'));

        if (! $this->manager()->acquireLock($this->project(), $this->environment())) {
            $this->error(sprintf(
                'Another deployment for [%s / %s] is already in progress. Refusing to start a second one.',
                $this->project(),
                $this->environment(),
            ));

            return self::FAILURE;
        }

        $git = $this->git();

        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_STARTED,
            deploymentId: $deploymentId,
            project: $this->project(),
            environment: $this->environment(),
            server: $this->server(),
            branch: $git->currentBranch(),
            commit: $git->currentCommit(),
        );

        $delivered = $this->manager()->record($event);

        $this->info("Deployment started: {$deploymentId}");
        $this->reportOutcome($delivered);

        if ($this->option('deployment-id') === null) {
            $this->comment('No --deployment-id was supplied, so one was generated above. Pass it explicitly '.
                'to the matching :pull, :success and :fail calls so they correlate with this deployment - '.
                'see the reference deploy.sh in the README, which generates the id itself for exactly this reason.');
        }

        return self::SUCCESS;
    }
}
