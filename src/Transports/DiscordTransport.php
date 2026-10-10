<?php

namespace MSahidurr\DeployNotifier\Transports;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MSahidurr\DeployNotifier\Contracts\NotificationTransport;
use MSahidurr\DeployNotifier\Services\SecretRedactor;
use Throwable;

/**
 * Sends messages to a Discord "Incoming Webhook" URL. Same safety
 * contract as TelegramTransport/SlackTransport: delivery problems are
 * always caught and logged locally, never thrown.
 *
 * Here $destination (see NotificationTransport::send()) is the full
 * incoming-webhook URL for the target Discord channel, not a channel
 * id - that is simply what a Discord incoming webhook identifies by,
 * same as Slack's.
 */
class DiscordTransport implements NotificationTransport
{
    /** Discord's hard limit on a message's `content` field. */
    private const MAX_CONTENT_LENGTH = 2000;

    public function __construct(
        private readonly int $timeoutSeconds = 5,
        private readonly int $retryTimes = 2,
        private readonly int $retrySleepMs = 250,
    ) {
    }

    public function send(string $destination, string $message, array $context = []): bool
    {
        if ($destination === '') {
            Log::warning('deploy-notifier: empty Discord webhook URL; skipping delivery.', $context);

            return false;
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->retry($this->retryTimes, $this->retrySleepMs, throw: false)
                ->post($destination, ['content' => $this->truncate($message)]);

            if ($response->successful()) {
                return true;
            }

            Log::error('deploy-notifier: Discord webhook responded with an error.', array_merge($context, [
                'status' => $response->status(),
                'body' => $this->redactor($destination)->redact($response->body()),
            ]));

            return false;
        } catch (Throwable $exception) {
            // The webhook URL itself is the secret (Discord has no
            // separate token) and HTTP client exceptions routinely
            // embed the full request URL in their message, so it must
            // be scrubbed before this ever reaches a log - exactly the
            // same reasoning as SlackTransport redacting its URL.
            Log::error('deploy-notifier: Discord delivery failed with an exception.', array_merge($context, [
                'exception' => $this->redactor($destination)->redact($exception->getMessage()),
            ]));

            return false;
        }
    }

    private function truncate(string $message): string
    {
        return mb_strimwidth($message, 0, self::MAX_CONTENT_LENGTH, '…');
    }

    /**
     * Built per-call (the webhook URL is only known once send() is
     * called), but still gets the generic patterns (bearer tokens,
     * telegram-token-shaped strings, ...) for free alongside the URL.
     */
    private function redactor(string $destination): SecretRedactor
    {
        return new SecretRedactor([$destination]);
    }
}
