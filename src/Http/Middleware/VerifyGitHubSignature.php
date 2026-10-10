<?php

namespace MSahidurr\DeployNotifier\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the `X-Hub-Signature-256` header GitHub sends on every
 * webhook delivery, computed as an HMAC-SHA256 of the exact raw
 * request body using the shared webhook secret. Requests that fail
 * verification are rejected before any payload parsing happens, and
 * the comparison is constant-time to avoid timing side channels.
 */
class VerifyGitHubSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('deploy-notifier.github.webhook_secret', '');

        if ($secret === '') {
            Log::warning('deploy-notifier: webhook received but no webhook_secret is configured; rejecting.');

            return response('Webhook is not configured.', 503);
        }

        $maxBytes = (int) config('deploy-notifier.github.max_payload_bytes', 1_000_000);
        $rawBody = $request->getContent();

        if (strlen($rawBody) > $maxBytes) {
            Log::warning('deploy-notifier: webhook payload exceeded the configured size limit.', [
                'bytes' => strlen($rawBody),
            ]);

            return response('Payload too large.', 413);
        }

        $signatureHeader = (string) $request->header('X-Hub-Signature-256', '');

        if ($signatureHeader === '' || ! str_starts_with($signatureHeader, 'sha256=')) {
            Log::warning('deploy-notifier: webhook request is missing a valid signature header.');

            return response('Missing signature.', 401);
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        if (! hash_equals($expected, $signatureHeader)) {
            Log::warning('deploy-notifier: webhook signature verification failed.');

            return response('Invalid signature.', 401);
        }

        return $next($request);
    }
}
