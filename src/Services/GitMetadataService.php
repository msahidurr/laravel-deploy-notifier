<?php

namespace MSahidurr\DeployNotifier\Services;

use Illuminate\Support\Facades\Process;

/**
 * Reads Git metadata from a local repository path via the `git` CLI.
 * Never writes to the repository and never executes anything derived
 * from untrusted input - callers only ever pass a configured, trusted
 * repository path.
 */
class GitMetadataService
{
    public function __construct(
        private readonly string $repositoryPath,
        private readonly string $remote = 'origin',
    ) {
    }

    public function currentCommit(): ?string
    {
        return $this->run(['git', 'rev-parse', 'HEAD']);
    }

    public function currentBranch(): ?string
    {
        return $this->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
    }

    public function authorOfCommit(string $commit = 'HEAD'): ?string
    {
        return $this->run(['git', 'show', '-s', '--format=%an', $commit]);
    }

    public function subjectOfCommit(string $commit = 'HEAD'): ?string
    {
        return $this->run(['git', 'show', '-s', '--format=%s', $commit]);
    }

    public function remoteUrl(): ?string
    {
        return $this->run(['git', 'remote', 'get-url', $this->remote]);
    }

    /**
     * Number of commits reachable from $to but not from $from.
     * Returns null if either SHA is unknown to the repository.
     */
    public function countCommitsBetween(string $from, string $to): ?int
    {
        $value = $this->run(['git', 'rev-list', '--count', "{$from}..{$to}"]);

        return $value === null ? null : (int) $value;
    }

    public function isValidRepository(): bool
    {
        return is_dir($this->repositoryPath)
            && $this->run(['git', 'rev-parse', '--is-inside-work-tree']) === 'true';
    }

    /**
     * @param  string[]  $command
     */
    private function run(array $command): ?string
    {
        if (! is_dir($this->repositoryPath)) {
            return null;
        }

        $result = Process::path($this->repositoryPath)
            ->timeout(10)
            ->run($command);

        if (! $result->successful()) {
            return null;
        }

        $output = trim($result->output());

        return $output === '' ? null : $output;
    }
}
