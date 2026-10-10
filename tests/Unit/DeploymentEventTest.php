<?php

namespace MSahidurr\DeployNotifier\Tests\Unit;

use MSahidurr\DeployNotifier\DTOs\DeploymentEvent;
use MSahidurr\DeployNotifier\Tests\TestCase;

class DeploymentEventTest extends TestCase
{
    public function test_it_rejects_unknown_event_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DeploymentEvent(
            type: 'not-a-real-type',
            deploymentId: 'deploy-1',
            project: 'Test',
            environment: 'production',
        );
    }

    public function test_it_rejects_an_empty_deployment_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DeploymentEvent(
            type: DeploymentEvent::TYPE_STARTED,
            deploymentId: '   ',
            project: 'Test',
            environment: 'production',
        );
    }

    public function test_dedupe_key_combines_type_and_deployment_id(): void
    {
        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_STARTED,
            deploymentId: 'deploy-1',
            project: 'Test',
            environment: 'production',
        );

        $this->assertSame('deployment.started:deploy-1', $event->dedupeKey());
    }

    public function test_terminal_states_are_identified_correctly(): void
    {
        $started = new DeploymentEvent(DeploymentEvent::TYPE_STARTED, 'd1', 'Test', 'production');
        $succeeded = new DeploymentEvent(DeploymentEvent::TYPE_SUCCEEDED, 'd1', 'Test', 'production');
        $failed = new DeploymentEvent(DeploymentEvent::TYPE_FAILED, 'd1', 'Test', 'production');

        $this->assertFalse($started->isTerminal());
        $this->assertTrue($succeeded->isTerminal());
        $this->assertTrue($failed->isTerminal());
    }

    public function test_with_extra_merges_rather_than_overwrites(): void
    {
        $event = (new DeploymentEvent(DeploymentEvent::TYPE_STARTED, 'd1', 'Test', 'production', extra: ['a' => 1]))
            ->withExtra(['b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $event->extra);
    }

    public function test_occurred_at_defaults_to_now_when_not_supplied(): void
    {
        $event = new DeploymentEvent(DeploymentEvent::TYPE_STARTED, 'd1', 'Test', 'production');

        $this->assertLessThanOrEqual(2, abs(time() - $event->occurredAt->getTimestamp()));
    }

    public function test_to_array_exposes_every_field_under_a_stable_snake_case_key(): void
    {
        // Documented as the public shape of DeploymentEventReceived's
        // payload (see README "Extending") for listeners that persist or
        // forward it, so the keys and their mapping to properties are a
        // contract worth locking down explicitly.
        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_FAILED,
            deploymentId: 'deploy-1',
            project: 'Test',
            environment: 'production',
            server: 'web-01',
            repository: 'org/repo',
            branch: 'main',
            commit: 'abc123',
            previousCommit: 'def456',
            author: 'developer',
            commitMessage: 'fix bug',
            step: 'composer install',
            exitCode: 2,
            newCommitCount: 3,
            extra: ['foo' => 'bar'],
        );

        $array = $event->toArray();

        $this->assertSame([
            'type' => DeploymentEvent::TYPE_FAILED,
            'deployment_id' => 'deploy-1',
            'project' => 'Test',
            'environment' => 'production',
            'server' => 'web-01',
            'repository' => 'org/repo',
            'branch' => 'main',
            'commit' => 'abc123',
            'previous_commit' => 'def456',
            'author' => 'developer',
            'commit_message' => 'fix bug',
            'step' => 'composer install',
            'exit_code' => 2,
            'new_commit_count' => 3,
            'occurred_at' => $event->occurredAt->format(\DateTimeInterface::ATOM),
            'extra' => ['foo' => 'bar'],
        ], $array);
    }

    public function test_to_array_uses_null_for_every_unset_optional_field(): void
    {
        $event = new DeploymentEvent(DeploymentEvent::TYPE_STARTED, 'd1', 'Test', 'production');

        $array = $event->toArray();

        foreach (['server', 'repository', 'branch', 'commit', 'previous_commit', 'author', 'commit_message', 'step', 'exit_code', 'new_commit_count'] as $key) {
            $this->assertNull($array[$key], "Expected [{$key}] to be null.");
        }
    }
}
