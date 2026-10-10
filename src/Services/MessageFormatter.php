<?php

namespace MSahidurr\DeployNotifier\Services;

use MSahidurr\DeployNotifier\DTOs\DeploymentEvent;

/**
 * Turns a DeploymentEvent into a concise, mobile-friendly Telegram
 * message. Never includes stack traces, environment files, tokens,
 * or any other credential-shaped content - that is enforced further
 * by SecretRedactor, which every message passes through before send.
 */
class MessageFormatter
{
    private const MAX_FIELD_LENGTH = 160;

    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {
    }

    public function format(DeploymentEvent $event): string
    {
        $body = match ($event->type) {
            DeploymentEvent::TYPE_PUSH => $this->formatPush($event),
            DeploymentEvent::TYPE_PULL => $this->formatPull($event),
            DeploymentEvent::TYPE_STARTED => $this->formatStarted($event),
            DeploymentEvent::TYPE_SUCCEEDED => $this->formatSucceeded($event),
            DeploymentEvent::TYPE_FAILED => $this->formatFailed($event),
            DeploymentEvent::TYPE_ROLLED_BACK => $this->formatRolledBack($event),
            default => $this->formatGeneric($event),
        };

        return $this->redactor->redact($body);
    }

    private function formatPush(DeploymentEvent $event): string
    {
        $lines = [
            '📤 GIT PUSH',
            '',
            "Project: {$event->project}",
        ];

        $this->appendIf($lines, 'Repository', $this->field($event->repository));
        $this->appendIf($lines, 'Branch', $this->field($event->branch));
        $this->appendIf($lines, 'Commit', $this->short($event->commit));
        $this->appendIf($lines, 'Author', $this->field($event->author));
        $this->appendIf($lines, 'Message', $this->field($event->commitMessage));

        $lines[] = '';
        $lines[] = "Environment: {$event->environment}";
        $lines[] = 'Event: PUSH RECEIVED';

        return implode("\n", $lines);
    }

    private function formatPull(DeploymentEvent $event): string
    {
        $status = $event->exitCode === null || $event->exitCode === 0 ? 'SUCCESS' : 'FAILED';

        $lines = [
            $status === 'SUCCESS' ? '📥 GIT PULL COMPLETED' : '📥 GIT PULL FAILED',
            '',
            "Project: {$event->project}",
        ];

        $this->appendIf($lines, 'Server', $this->field($event->server));
        $this->appendIf($lines, 'Branch', $this->field($event->branch));
        $this->appendIf($lines, 'Previous commit', $this->short($event->previousCommit));
        $this->appendIf($lines, 'Current commit', $this->short($event->commit));

        if ($event->newCommitCount !== null) {
            $lines[] = "New commits: {$event->newCommitCount}";
        }

        $lines[] = '';
        $lines[] = "Status: {$status}";
        $lines[] = "Deployment ID: {$event->deploymentId}";

        return implode("\n", $lines);
    }

    private function formatStarted(DeploymentEvent $event): string
    {
        $lines = [
            '🚀 DEPLOYMENT STARTED',
            '',
            "Project: {$event->project}",
        ];

        $this->appendIf($lines, 'Server', $this->field($event->server));
        $this->appendIf($lines, 'Branch', $this->field($event->branch));
        $this->appendIf($lines, 'Commit', $this->short($event->commit));

        $lines[] = '';
        $lines[] = "Environment: {$event->environment}";
        $lines[] = "Deployment ID: {$event->deploymentId}";

        return implode("\n", $lines);
    }

    private function formatSucceeded(DeploymentEvent $event): string
    {
        $lines = [
            '✅ DEPLOYMENT SUCCESS',
            '',
            "Project: {$event->project}",
        ];

        $this->appendIf($lines, 'Server', $this->field($event->server));
        $this->appendIf($lines, 'Branch', $this->field($event->branch));
        $this->appendIf($lines, 'Commit', $this->short($event->commit));

        $lines[] = '';
        $lines[] = "Environment: {$event->environment}";
        $lines[] = "Deployment ID: {$event->deploymentId}";

        return implode("\n", $lines);
    }

    private function formatFailed(DeploymentEvent $event): string
    {
        $lines = [
            '❌ DEPLOYMENT FAILED',
            '',
            "Project: {$event->project}",
        ];

        $this->appendIf($lines, 'Server', $this->field($event->server));
        $this->appendIf($lines, 'Environment', $event->environment);
        $lines[] = '';
        $this->appendIf($lines, 'Failed step', $this->field($event->step));

        if ($event->exitCode !== null) {
            $lines[] = "Exit code: {$event->exitCode}";
        }

        $this->appendIf($lines, 'Commit', $this->short($event->commit));
        $lines[] = "Deployment ID: {$event->deploymentId}";
        $lines[] = '';
        $lines[] = 'Check server logs for details.';

        return implode("\n", $lines);
    }

    private function formatRolledBack(DeploymentEvent $event): string
    {
        $lines = [
            '↩️ DEPLOYMENT ROLLED BACK',
            '',
            "Project: {$event->project}",
        ];

        $this->appendIf($lines, 'Server', $this->field($event->server));
        $this->appendIf($lines, 'Environment', $event->environment);
        $this->appendIf($lines, 'Rolled back to', $this->short($event->commit));
        $lines[] = "Deployment ID: {$event->deploymentId}";

        return implode("\n", $lines);
    }

    private function formatGeneric(DeploymentEvent $event): string
    {
        return "Deployment event [{$event->type}] for {$event->project} ({$event->deploymentId}).";
    }

    private function appendIf(array &$lines, string $label, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $lines[] = "{$label}: {$value}";
        }
    }

    private function short(?string $commit): ?string
    {
        if ($commit === null || $commit === '') {
            return null;
        }

        return substr($commit, 0, 7);
    }

    private function field(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace(["\r", "\n"], ' ', $value);

        return mb_strimwidth($value, 0, self::MAX_FIELD_LENGTH, '…');
    }
}
