<?php

namespace MSahidurr\DeployNotifier\Tests\Unit;

use MSahidurr\DeployNotifier\DTOs\DeploymentEvent;
use MSahidurr\DeployNotifier\Services\MessageFormatter;
use MSahidurr\DeployNotifier\Services\SecretRedactor;
use MSahidurr\DeployNotifier\Tests\TestCase;

class MessageFormatterTest extends TestCase
{
    private MessageFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatter = new MessageFormatter(new SecretRedactor());
    }

    public function test_push_message_includes_key_fields(): void
    {
        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_PUSH,
            deploymentId: 'push-1',
            project: 'Example App',
            environment: 'production',
            repository: 'organization/example-app',
            branch: 'main',
            commit: 'a81d23fabc123',
            author: 'developer',
            commitMessage: 'Improve API performance',
        );

        $message = $this->formatter->format($event);

        $this->assertStringContainsString('GIT PUSH', $message);
        $this->assertStringContainsString('organization/example-app', $message);
        $this->assertStringContainsString('main', $message);
        $this->assertStringContainsString('a81d23f', $message);
        $this->assertStringContainsString('developer', $message);
        $this->assertStringContainsString('Improve API performance', $message);
    }

    public function test_push_message_includes_the_link_when_present(): void
    {
        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_PUSH,
            deploymentId: 'push-1',
            project: 'Example App',
            environment: 'production',
            repository: 'organization/example-app',
            branch: 'main',
            commit: 'a81d23fabc123',
            extra: ['url' => 'https://github.com/organization/example-app/commit/a81d23fabc123'],
        );

        $this->assertStringContainsString(
            'Link: https://github.com/organization/example-app/commit/a81d23fabc123',
            $this->formatter->format($event)
        );
    }

    public function test_pull_message_reports_new_commit_count(): void
    {
        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_PULL,
            deploymentId: 'deploy-1',
            project: 'Example App',
            environment: 'production',
            previousCommit: '92b41f0abc',
            commit: 'a81d23fabc',
            newCommitCount: 3,
        );

        $message = $this->formatter->format($event);

        $this->assertStringContainsString('GIT PULL COMPLETED', $message);
        $this->assertStringContainsString('New commits: 3', $message);
        $this->assertStringContainsString('Status: SUCCESS', $message);
    }

    public function test_failed_deployment_message_includes_step_and_exit_code(): void
    {
        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_FAILED,
            deploymentId: 'deploy-1',
            project: 'Example App',
            environment: 'production',
            step: 'composer install',
            exitCode: 2,
        );

        $message = $this->formatter->format($event);

        $this->assertStringContainsString('DEPLOYMENT FAILED', $message);
        $this->assertStringContainsString('Failed step: composer install', $message);
        $this->assertStringContainsString('Exit code: 2', $message);
    }

    public function test_messages_never_leak_a_registered_secret(): void
    {
        $formatter = new MessageFormatter(new SecretRedactor(['top-secret-value']));

        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_FAILED,
            deploymentId: 'deploy-1',
            project: 'Example App',
            environment: 'production',
            step: 'top-secret-value leaked into a step name',
        );

        $message = $formatter->format($event);

        $this->assertStringNotContainsString('top-secret-value', $message);
    }

    public function test_long_commit_message_is_truncated(): void
    {
        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_PUSH,
            deploymentId: 'push-1',
            project: 'Example App',
            environment: 'production',
            commitMessage: str_repeat('x', 500),
        );

        $message = $this->formatter->format($event);
        $line = collect(explode("\n", $message))->first(fn ($l) => str_starts_with($l, 'Message:'));

        $this->assertLessThan(200, strlen($line));
    }

    public function test_a_branch_or_repository_name_with_embedded_newlines_cannot_inject_extra_lines(): void
    {
        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_PUSH,
            deploymentId: 'push-1',
            project: 'Example App',
            environment: 'production',
            repository: "organization/example-app\nFAKE LINE: forged",
            branch: "main\nEnvironment: production\nStatus: SUCCESS",
        );

        $message = $this->formatter->format($event);

        $this->assertStringNotContainsString("\n\n\n", $message);
        $this->assertStringContainsString(
            'Repository: organization/example-app FAKE LINE: forged',
            $message,
            'A newline in an attacker-influenced field (the webhook repository/branch) must be flattened onto one line, not split the message into forged extra lines.'
        );
        $this->assertStringContainsString('Branch: main Environment: production Status: SUCCESS', $message);
    }

    public function test_a_server_or_step_name_with_embedded_newlines_is_flattened(): void
    {
        $event = new DeploymentEvent(
            type: DeploymentEvent::TYPE_FAILED,
            deploymentId: 'deploy-1',
            project: 'Example App',
            environment: 'production',
            server: "web-prod-01\nDeployment ID: forged-id",
            step: "composer install\nStatus: SUCCESS",
        );

        $message = $this->formatter->format($event);

        $this->assertStringContainsString('Server: web-prod-01 Deployment ID: forged-id', $message);
        $this->assertStringContainsString('Failed step: composer install Status: SUCCESS', $message);
    }
}
