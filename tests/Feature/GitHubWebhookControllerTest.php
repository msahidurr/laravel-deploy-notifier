<?php

namespace MSahidurr\DeployNotifier\Tests\Feature;

use Illuminate\Support\Facades\Http;
use MSahidurr\DeployNotifier\Tests\TestCase;

class GitHubWebhookControllerTest extends TestCase
{
    private const SECRET = 'test-webhook-secret';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('deploy-notifier.github.webhook_secret', self::SECRET);
        $app['config']->set('deploy-notifier.github.webhook_path', 'webhooks/deploy-notifier/github');
        $app['config']->set('deploy-notifier.github.allowed_repositories', ['organization/example-app']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    private function pushPayload(array $overrides = []): array
    {
        return array_merge([
            'ref' => 'refs/heads/main',
            'after' => 'a81d23fabc123',
            'repository' => ['full_name' => 'organization/example-app'],
            'pusher' => ['name' => 'developer'],
            'head_commit' => [
                'id' => 'a81d23fabc123',
                'message' => 'Improve API performance',
                'author' => ['name' => 'developer'],
            ],
        ], $overrides);
    }

    private function signedHeaders(array $payload, string $delivery = 'delivery-1'): array
    {
        $body = json_encode($payload);

        return [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => $delivery,
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ];
    }

    public function test_a_validly_signed_push_is_accepted_and_forwarded_to_telegram(): void
    {
        $payload = $this->pushPayload();

        $response = $this->postJson('/webhooks/deploy-notifier/github', $payload, $this->signedHeaders($payload));

        $response->assertOk();
        $response->assertJson(['status' => 'accepted']);

        Http::assertSent(function ($request) {
            return str_contains($request['text'] ?? '', 'GIT PUSH')
                && str_contains($request['text'] ?? '', 'organization/example-app');
        });
    }

    public function test_a_branch_deletion_push_does_not_report_the_all_zero_sha_as_a_real_commit(): void
    {
        // GitHub reports a branch/tag deletion as a push with after =
        // the all-zero SHA, deleted: true, and head_commit: null.
        $payload = $this->pushPayload([
            'after' => '0000000000000000000000000000000000000000',
            'deleted' => true,
            'head_commit' => null,
        ]);

        $response = $this->postJson('/webhooks/deploy-notifier/github', $payload, $this->signedHeaders($payload));

        $response->assertOk();
        $response->assertJson(['status' => 'accepted']);

        Http::assertSent(function ($request) {
            $text = $request['text'] ?? '';

            return str_contains($text, 'GIT PUSH') && ! str_contains($text, '0000000');
        });
    }

    public function test_an_invalid_signature_is_rejected(): void
    {
        $payload = $this->pushPayload();

        $response = $this->postJson('/webhooks/deploy-notifier/github', $payload, [
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', 'not-the-real-body', self::SECRET),
        ]);

        $response->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_a_missing_signature_is_rejected(): void
    {
        $payload = $this->pushPayload();

        $response = $this->postJson('/webhooks/deploy-notifier/github', $payload, [
            'X-GitHub-Event' => 'push',
        ]);

        $response->assertStatus(401);
    }

    public function test_non_push_events_are_acknowledged_but_ignored(): void
    {
        $payload = $this->pushPayload();
        $headers = $this->signedHeaders($payload);
        $headers['X-GitHub-Event'] = 'ping';

        $response = $this->postJson('/webhooks/deploy-notifier/github', $payload, $headers);

        $response->assertStatus(202);
        Http::assertNothingSent();
    }

    public function test_a_repository_outside_the_allowlist_is_rejected(): void
    {
        $payload = $this->pushPayload(['repository' => ['full_name' => 'someone-else/unrelated-repo']]);

        $response = $this->postJson('/webhooks/deploy-notifier/github', $payload, $this->signedHeaders($payload));

        $response->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_duplicate_deliveries_are_suppressed(): void
    {
        $payload = $this->pushPayload();
        $headers = $this->signedHeaders($payload, 'delivery-dup');

        $this->postJson('/webhooks/deploy-notifier/github', $payload, $headers)->assertOk();
        $this->postJson('/webhooks/deploy-notifier/github', $payload, $headers)->assertOk();

        Http::assertSentCount(1);
    }

    public function test_a_payload_over_the_configured_size_limit_is_rejected(): void
    {
        config(['deploy-notifier.github.max_payload_bytes' => 100]);

        $oversizedBody = json_encode($this->pushPayload(['extra_padding' => str_repeat('x', 500)]));
        $this->assertGreaterThan(100, strlen($oversizedBody));

        $response = $this->call('POST', '/webhooks/deploy-notifier/github', [], [], [], [
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_GITHUB_DELIVERY' => 'delivery-oversized',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $oversizedBody, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $oversizedBody);

        $response->assertStatus(413);
        Http::assertNothingSent();
    }

    public function test_a_malformed_json_body_is_rejected_without_a_server_error(): void
    {
        $malformedBody = '{"ref": "refs/heads/main", "repository": {"full_name": "organization/example-app"'; // truncated / invalid JSON

        $response = $this->call('POST', '/webhooks/deploy-notifier/github', [], [], [], [
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_GITHUB_DELIVERY' => 'delivery-malformed',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $malformedBody, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $malformedBody);

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_body_tampered_with_after_signing_is_rejected(): void
    {
        $payload = $this->pushPayload();
        $headers = $this->signedHeaders($payload);

        // Sign one payload but send a different one - simulates a proxy
        // or an attacker altering the body in transit after a legitimate
        // signature was computed over the original bytes.
        $tamperedPayload = $this->pushPayload(['repository' => ['full_name' => 'someone-else/unrelated-repo']]);

        $response = $this->postJson('/webhooks/deploy-notifier/github', $tamperedPayload, $headers);

        $response->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_a_form_encoded_push_is_accepted(): void
    {
        // GitHub's "application/x-www-form-urlencoded" content type sends
        // the JSON in a `payload` field; the signature covers the raw body.
        $body = http_build_query(['payload' => json_encode($this->pushPayload())]);

        $response = $this->call('POST', '/webhooks/deploy-notifier/github', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_GITHUB_DELIVERY' => 'delivery-form',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);

        $response->assertOk();
        $response->assertJson(['status' => 'accepted']);
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'organization/example-app'));
    }

    public function test_a_rejected_delivery_can_be_redelivered(): void
    {
        $invalid = ['ref' => 'refs/heads/main'];

        $this->postJson('/webhooks/deploy-notifier/github', $invalid, $this->signedHeaders($invalid, 'delivery-redo'))
            ->assertStatus(422);

        $payload = $this->pushPayload();

        $this->postJson('/webhooks/deploy-notifier/github', $payload, $this->signedHeaders($payload, 'delivery-redo'))
            ->assertOk()
            ->assertJson(['status' => 'accepted']);
    }

    public function test_the_link_points_to_the_commit_for_a_single_commit_push(): void
    {
        $payload = $this->pushPayload([
            'compare' => 'https://github.com/organization/example-app/compare/aaa...bbb',
            'commits' => [['id' => 'a81d23fabc123']],
        ]);
        $payload['head_commit']['url'] = 'https://github.com/organization/example-app/commit/a81d23fabc123';

        $this->postJson('/webhooks/deploy-notifier/github', $payload, $this->signedHeaders($payload))->assertOk();

        Http::assertSent(fn ($request) => str_contains(
            $request['text'] ?? '',
            'Link: https://github.com/organization/example-app/commit/a81d23fabc123'
        ));
    }

    public function test_the_link_points_to_the_compare_view_for_a_multi_commit_push(): void
    {
        $payload = $this->pushPayload([
            'compare' => 'https://github.com/organization/example-app/compare/aaa...bbb',
            'commits' => [['id' => 'one'], ['id' => 'two']],
        ]);
        $payload['head_commit']['url'] = 'https://github.com/organization/example-app/commit/a81d23fabc123';

        $this->postJson('/webhooks/deploy-notifier/github', $payload, $this->signedHeaders($payload))->assertOk();

        Http::assertSent(fn ($request) => str_contains(
            $request['text'] ?? '',
            'Link: https://github.com/organization/example-app/compare/aaa...bbb'
        ));
    }

    public function test_a_non_https_link_is_dropped(): void
    {
        $payload = $this->pushPayload(['compare' => 'javascript:alert(1)']);

        $this->postJson('/webhooks/deploy-notifier/github', $payload, $this->signedHeaders($payload))->assertOk();

        Http::assertSent(fn ($request) => ! str_contains($request['text'] ?? '', 'Link:'));
    }
}
