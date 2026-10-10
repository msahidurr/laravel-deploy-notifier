<?php

namespace MSahidurr\DeployNotifier\Commands;

use Illuminate\Console\Command;
use MSahidurr\DeployNotifier\Services\DeploymentManager;
use MSahidurr\DeployNotifier\Services\GitMetadataService;

abstract class BaseCommand extends Command
{
    protected function manager(): DeploymentManager
    {
        return $this->laravel->make(DeploymentManager::class);
    }

    protected function git(): GitMetadataService
    {
        return $this->laravel->make(GitMetadataService::class);
    }

    protected function project(): string
    {
        return (string) config('deploy-notifier.project');
    }

    protected function environment(): string
    {
        return (string) config('deploy-notifier.environment');
    }

    protected function server(): string
    {
        return (string) config('deploy-notifier.server');
    }

    /**
     * Every lifecycle command that mutates/reports state needs a
     * deployment id to correlate with start/pull/success/fail events.
     * Falls back to a freshly generated id when none was supplied, so an
     * ad-hoc invocation without --deployment-id still works - though it
     * then can't correlate with any other command's event, since nothing
     * else will know the id that was generated. See the StartCommand's
     * warning about this and examples/deploy.sh, which generates its own
     * id up front and passes it explicitly to every command instead.
     */
    protected function resolveDeploymentId(?string $option): string
    {
        if ($option !== null && trim($option) !== '') {
            return trim($option);
        }

        return \MSahidurr\DeployNotifier\Support\DeploymentIdGenerator::generate();
    }

    /**
     * For every lifecycle command except :start: there is no legitimate
     * reason to fabricate a deployment id here. Auto-generating one
     * (like resolveDeploymentId() does) would silently produce an id
     * that cannot possibly match the :start call it's supposed to
     * correlate with, defeating the entire point of --deployment-id -
     * and the command would still "succeed" and send a notification,
     * giving no indication anything was wrong. Fail loudly instead.
     *
     * Returns null (having already printed the error) when the option
     * is missing; callers should return self::FAILURE in that case.
     */
    protected function requireDeploymentId(?string $option): ?string
    {
        if ($option !== null && trim($option) !== '') {
            return trim($option);
        }

        $this->error(
            'The --deployment-id option is required so this event correlates with the matching '.
            ':start call. Pass the same id your deployment script generated for :start - see '.
            'examples/deploy.sh.'
        );

        return null;
    }

    protected function reportOutcome(bool $delivered, string $skippedMessage = 'Notification was not delivered (see logs for the reason); the reported outcome itself was still recorded.'): void
    {
        if ($delivered) {
            $this->info('Notification delivered.');
        } else {
            $this->warn($skippedMessage);
        }
    }
}
