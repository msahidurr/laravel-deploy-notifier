<?php

namespace MSahidurr\DeployNotifier\Transports;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MSahidurr\DeployNotifier\Contracts\NotificationTransport;
use MSahidurr\DeployNotifier\Services\SecretRedactor;
use Throwable;

/**
 * Sends messages to a Slack "Incoming Webhook" URL. Same safety contract
 * as TelegramTransport: delivery problems are always caught and logged
 * locally, never thrown - this transport can run alongside Telegram
 * without either one being able to affect a deployment's own outcome.
 *
 * Here $destination (see NotificationTransport::send()) is the full
 * incoming-webhook URL for the target Slack channel, not a channel id -
 * that is simply what a Slack incoming webhook identifies by.
 */
class SlackTransport implements NotificationTransport
{
    public function __construct(
        private readonly int $timeoutSeconds = 5,
        private readonly int $retryTimes = 2,
        private readonly int $retrySleepMs = 250,
    ) {
    }

    public function send(string $destination, string $message, array $context = []): bool
    {
        if ($destination === '') {
            Log::warning('deploy-notifier: empty Slack webhook URL; skipping delivery.', $context);

            return false;
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->retry($this->retryTimes, $this->retrySleepMs, throw: false)
                ->post($destination, ['text' => $message]);

            if ($response->successful()) {
                return true;
            }

            Log::error('deploy-notifier: Slack webhook responded with an error.', array_merge($context, [
                'status' => $response->status(),
                'body' => $this->redactor($destination)->redact($response->body()),
            ]));

            return false;
        } catch (Throwable $exception) {
            // The webhook URL itself is the secret (Slack has no
            // separate token) and HTTP client exceptions routinely
            // embed the full request URL in their message, so it must
            // be scrubbed before this ever reaches a log - exactly the
            // same reasoning as TelegramTransport redacting its token.
            Log::error('deploy-notifier: Slack delivery failed with an exception.', array_merge($context, [
                'exception' => $this->redactor($destination)->redact($exception->getMessage()),
            ]));

            return false;
        }
    }

    /**
     * Built per-call (the webhook URL is only known once send() is
     * called, unlike Telegram's bot token at construction time), but
     * still gets the generic patterns (bearer tokens, telegram-token-
     * shaped strings, ...) for free alongside the URL itself.
     */
    private function redactor(string $destination): SecretRedactor
    {
        return new SecretRedactor([$destination]);
    }
}
