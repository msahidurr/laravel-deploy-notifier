<?php

namespace MSahidurr\DeployNotifier\DTOs;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Immutable description of a single lifecycle event.
 *
 * One DeploymentEvent never represents more than one thing happening -
 * a push, a pull, a deployment start/success/failure, or a rollback.
 * Multiple events sharing the same deploymentId correlate into one
 * deployment attempt.
 */
final class DeploymentEvent
{
    public const TYPE_PUSH = 'git.push';
    public const TYPE_PULL = 'git.pull';
    public const TYPE_STARTED = 'deployment.started';
    public const TYPE_SUCCEEDED = 'deployment.succeeded';
    public const TYPE_FAILED = 'deployment.failed';
    public const TYPE_ROLLED_BACK = 'deployment.rolled_back';

    public const TYPES = [
        self::TYPE_PUSH,
        self::TYPE_PULL,
        self::TYPE_STARTED,
        self::TYPE_SUCCEEDED,
        self::TYPE_FAILED,
        self::TYPE_ROLLED_BACK,
    ];

    public readonly DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $type,
        public readonly string $deploymentId,
        public readonly string $project,
        public readonly string $environment,
        public readonly ?string $server = null,
        public readonly ?string $repository = null,
        public readonly ?string $branch = null,
        public readonly ?string $commit = null,
        public readonly ?string $previousCommit = null,
        public readonly ?string $author = null,
        public readonly ?string $commitMessage = null,
        public readonly ?string $step = null,
        public readonly ?int $exitCode = null,
        public readonly ?int $newCommitCount = null,
        ?DateTimeImmutable $occurredAt = null,
        /** @var array<string, mixed> */
        public readonly array $extra = [],
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown deployment event type [{$type}].");
        }

        if (trim($deploymentId) === '') {
            throw new \InvalidArgumentException('A deployment event requires a non-empty deployment id.');
        }

        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }

    public function withExtra(array $extra): self
    {
        return new self(
            type: $this->type,
            deploymentId: $this->deploymentId,
            project: $this->project,
            environment: $this->environment,
            server: $this->server,
            repository: $this->repository,
            branch: $this->branch,
            commit: $this->commit,
            previousCommit: $this->previousCommit,
            author: $this->author,
            commitMessage: $this->commitMessage,
            step: $this->step,
            exitCode: $this->exitCode,
            newCommitCount: $this->newCommitCount,
            occurredAt: $this->occurredAt,
            extra: array_merge($this->extra, $extra),
        );
    }

    /** A stable key identifying this exact event, for deduplication. */
    public function dedupeKey(): string
    {
        return "{$this->type}:{$this->deploymentId}";
    }

    public function isTerminal(): bool
    {
        return in_array($this->type, [
            self::TYPE_SUCCEEDED,
            self::TYPE_FAILED,
            self::TYPE_ROLLED_BACK,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'deployment_id' => $this->deploymentId,
            'project' => $this->project,
            'environment' => $this->environment,
            'server' => $this->server,
            'repository' => $this->repository,
            'branch' => $this->branch,
            'commit' => $this->commit,
            'previous_commit' => $this->previousCommit,
            'author' => $this->author,
            'commit_message' => $this->commitMessage,
            'step' => $this->step,
            'exit_code' => $this->exitCode,
            'new_commit_count' => $this->newCommitCount,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::ATOM),
            'extra' => $this->extra,
        ];
    }
}
