<?php

namespace MSahidurr\DeployNotifier\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use MSahidurr\DeployNotifier\Tests\TestCase;

class LifecycleCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Process::fake([
            '*' => Process::result(output: "a81d23fabc123\n"),
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_start_success_lifecycle_sends_two_telegram_messages(): void
    {
        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])
            ->assertExitCode(0);

        $this->artisan('deploy-notifier:success', ['--deployment-id' => 'deploy-1'])
            ->assertExitCode(0);

        Http::assertSentCount(2);
    }

    public function test_a_second_start_is_blocked_while_the_first_is_in_progress(): void
    {
        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])
            ->assertExitCode(0);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-2'])
            ->assertExitCode(1);

        Http::assertSentCount(1);
    }

    public function test_success_releases_the_lock_for_the_next_deployment(): void
    {
        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);
        $this->artisan('deploy-notifier:success', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-2'])->assertExitCode(0);
    }

    public function test_fail_releases_the_lock_and_never_fails_itself(): void
    {
        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        $this->artisan('deploy-notifier:fail', [
            '--deployment-id' => 'deploy-1',
            '--step' => 'composer install',
            '--exit-code' => 2,
        ])->assertExitCode(0);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-2'])->assertExitCode(0);
    }

    public function test_pull_reports_new_commit_count_when_previous_commit_is_known(): void
    {
        // A Closure fake fully replaces the handler map (rather than
        // merging into the array-form fake registered in setUp(), where
        // key order would let the broad '*' rule shadow a more specific
        // one added afterwards), so this is the reliable way to give
        // one specific git subcommand a different canned answer.
        Process::fake(function ($process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($command, 'rev-list')
                ? Process::result(output: "4\n")
                : Process::result(output: "a81d23fabc123\n");
        });

        $this->artisan('deploy-notifier:pull', [
            '--deployment-id' => 'deploy-1',
            '--previous-commit' => '92b41f0abc',
        ])->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request['text'] ?? '', 'New commits: 4');
        });
    }

    public function test_rollback_is_reported_independently_of_the_original_failure(): void
    {
        $this->artisan('deploy-notifier:fail', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);
        $this->artisan('deploy-notifier:rollback', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        Http::assertSentCount(2);
    }

    public function test_duplicate_success_events_are_not_delivered_twice(): void
    {
        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);
        $this->artisan('deploy-notifier:success', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);
        $this->artisan('deploy-notifier:success', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        Http::assertSentCount(2); // start + first success only
    }

    public function test_disabled_notifier_sends_nothing(): void
    {
        config(['deploy-notifier.enabled' => false]);

        $this->artisan('deploy-notifier:start', ['--deployment-id' => 'deploy-1'])->assertExitCode(0);

        Http::assertNothingSent();
    }

    /**
     * :success, :fail, :pull and :rollback must never silently fabricate
     * a deployment id - that id is their entire reason for existing
     * (correlating with the matching :start call), so fabricating one
     * on a missing --deployment-id would quietly break that correlation
     * while still reporting as if everything worked. They must fail
     * loudly instead. :start is the one place a fresh id is legitimate,
     * since it's the command that establishes it in the first place.
     */
    public function test_success_fail_pull_and_rollback_require_an_explicit_deployment_id(): void
    {
        $this->artisan('deploy-notifier:success')
            ->expectsOutputToContain('--deployment-id option is required')
            ->assertExitCode(1);

        $this->artisan('deploy-notifier:fail')
            ->expectsOutputToContain('--deployment-id option is required')
            ->assertExitCode(1);

        $this->artisan('deploy-notifier:pull')
            ->expectsOutputToContain('--deployment-id option is required')
            ->assertExitCode(1);

        $this->artisan('deploy-notifier:rollback')
            ->expectsOutputToContain('--deployment-id option is required')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_start_still_auto_generates_a_deployment_id_when_omitted(): void
    {
        $this->artisan('deploy-notifier:start')->assertExitCode(0);

        Http::assertSentCount(1);
    }
}
