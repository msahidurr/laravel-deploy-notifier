<?php

namespace MSahidurr\DeployNotifier\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use MSahidurr\DeployNotifier\Contracts\EventStore;
use MSahidurr\DeployNotifier\DTOs\DeploymentEvent;
use MSahidurr\DeployNotifier\Services\DeploymentManager;

/**
 * Accepts signed GitHub `push` webhook deliveries and turns them into
 * a normalized git.push DeploymentEvent. By the time a request reaches
 * this controller, VerifyGitHubSignature has already confirmed it was
 * genuinely sent by GitHub using the shared secret.
 *
 * This controller only ever reports what GitHub says happened. It
 * never executes anything derived from the request payload.
 */
class GitHubWebhookController extends Controller
{
    public function __construct(
        private readonly DeploymentManager $manager,
        private readonly EventStore $eventStore,
    ) {
    }

    public function handle(Request $request)
    {
        $event = (string) $request->header('X-GitHub-Event', '');

        if ($event !== 'push') {
            // Acknowledge, but ignore - pings and other event types are
            // not actionable here and must not be treated as errors.
            return response()->json(['status' => 'ignored', 'reason' => "event [{$event}] is not handled"], 202);
        }

        $payload = $this->payload($request);

        $repository = $payload['repository']['full_name'] ?? null;
        $ref = $payload['ref'] ?? null;

        if (! is_string($repository) || ! is_string($ref)) {
            return response()->json(['status' => 'rejected', 'reason' => 'missing repository or ref'], 422);
        }

        $allowed = array_filter((array) config('deploy-notifier.github.allowed_repositories', []));

        if ($allowed !== [] && ! in_array($repository, $allowed, true)) {
            Log::warning('deploy-notifier: webhook rejected for a repository outside the allowlist.', [
                'repository' => $repository,
            ]);

            return response()->json(['status' => 'rejected', 'reason' => 'repository not allowed'], 403);
        }

        // Deduplicate only once the payload is known to be valid, so a
        // delivery rejected above can still be fixed and redelivered
        // from GitHub (redeliveries reuse the same delivery ID).
        $deliveryId = (string) $request->header('X-GitHub-Delivery', '');

        if ($deliveryId !== '') {
            $dedupeTtl = (int) config('deploy-notifier.deduplication.ttl', 86400);

            if (config('deploy-notifier.deduplication.enabled', true)
                && ! $this->eventStore->markSeenOnce('github-delivery:'.$deliveryId, $dedupeTtl)) {
                return response()->json(['status' => 'duplicate'], 200);
            }
        }

        $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, strlen('refs/heads/')) : $ref;

        // GitHub reports a branch/tag deletion as a push whose `after` is
        // the all-zero SHA ("0000...0") with `deleted: true` and no
        // head_commit - treat that as "no commit" rather than reporting
        // the all-zero SHA as if it were a real one.
        $isDeleted = (bool) ($payload['deleted'] ?? false);
        $commit = $isDeleted ? null : ($payload['after'] ?? ($payload['head_commit']['id'] ?? null));
        $author = $payload['head_commit']['author']['username']
            ?? $payload['head_commit']['author']['name']
            ?? ($payload['pusher']['name'] ?? null);
        $message = $payload['head_commit']['message'] ?? null;

        $deploymentEvent = new DeploymentEvent(
            type: DeploymentEvent::TYPE_PUSH,
            deploymentId: 'push-'.($deliveryId !== '' ? $deliveryId : (string) now()->getTimestampMs()),
            project: (string) config('deploy-notifier.project'),
            environment: (string) config('deploy-notifier.environment'),
            repository: $repository,
            branch: $branch,
            commit: is_string($commit) ? $commit : null,
            author: is_string($author) ? $author : null,
            commitMessage: is_string($message) ? $message : null,
            extra: ['github_delivery' => $deliveryId],
        );

        $this->manager->record($deploymentEvent);

        return response()->json(['status' => 'accepted'], 200);
    }

    /**
     * GitHub webhooks can use either content type: "application/json"
     * sends the JSON as the raw body, while
     * "application/x-www-form-urlencoded" sends it URL-encoded in a
     * `payload` form field. Accept both.
     */
    private function payload(Request $request): array
    {
        if ($request->isJson()) {
            return $request->json()->all();
        }

        parse_str($request->getContent(), $form);

        $decoded = json_decode((string) ($form['payload'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }
}
