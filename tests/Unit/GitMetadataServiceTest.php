<?php

namespace MSahidurr\DeployNotifier\Tests\Unit;

use Illuminate\Support\Facades\Process;
use MSahidurr\DeployNotifier\Services\GitMetadataService;
use MSahidurr\DeployNotifier\Tests\TestCase;

class GitMetadataServiceTest extends TestCase
{
    public function test_it_returns_null_when_the_path_is_not_a_directory(): void
    {
        $service = new GitMetadataService('/path/does/not/exist');

        $this->assertNull($service->currentCommit());
        $this->assertFalse($service->isValidRepository());
    }

    public function test_it_reads_the_current_commit_via_git(): void
    {
        Process::fake([
            '*' => Process::result(output: "a81d23fabc123\n"),
        ]);

        $service = new GitMetadataService(sys_get_temp_dir());

        $this->assertSame('a81d23fabc123', $service->currentCommit());
    }

    public function test_it_returns_null_when_git_fails(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'fatal: not a git repository', exitCode: 128),
        ]);

        $service = new GitMetadataService(sys_get_temp_dir());

        $this->assertNull($service->currentCommit());
    }

    public function test_commits_between_casts_output_to_int(): void
    {
        Process::fake([
            '*' => Process::result(output: "3\n"),
        ]);

        $service = new GitMetadataService(sys_get_temp_dir());

        $this->assertSame(3, $service->countCommitsBetween('abc', 'def'));
    }
}
