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
}
