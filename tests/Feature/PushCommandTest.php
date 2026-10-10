<?php

namespace MSahidurr\DeployNotifier\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use MSahidurr\DeployNotifier\Tests\TestCase;

class PushCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    public function test_it_reports_a_push_using_explicit_options(): void
    {
        Process::fake();

        $this->artisan('deploy-notifier:push', [
            '--branch' => 'main',
            '--commit' => 'a81d23fabc123',
            '--repository' => 'organization/example-app',
            '--author' => 'developer',
            '--message' => 'Improve API performance',
        ])
            ->expectsOutputToContain('only reports what you tell it')
            ->expectsOutputToContain('Push event recorded.')
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            $text = $request['text'] ?? '';

            return str_contains($text, 'GIT PUSH')
                && str_contains($text, 'organization/example-app')
                && str_contains($text, 'main')
                && str_contains($text, 'developer')
                && str_contains($text, 'Improve API performance');
        });

        // With every option supplied, GitMetadataService should never
        // have needed to shell out to git at all.
        Process::assertNothingRan();
    }

    public function test_it_falls_back_to_git_metadata_when_options_are_omitted(): void
    {
        Process::fake(['*' => Process::result(output: "detected-value\n")]);

        $this->artisan('deploy-notifier:push')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'detected-value'));
    }

    public function test_each_push_gets_its_own_unique_deployment_id_and_is_never_deduplicated(): void
    {
        Process::fake(['*' => Process::result(output: "a81d23fabc123\n")]);

        $this->artisan('deploy-notifier:push', ['--commit' => 'a81d23fabc123'])->assertExitCode(0);
        $this->artisan('deploy-notifier:push', ['--commit' => 'a81d23fabc123'])->assertExitCode(0);

        Http::assertSentCount(2);
    }
}
