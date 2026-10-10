<?php

namespace MSahidurr\DeployNotifier\Transports;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MSahidurr\DeployNotifier\Contracts\NotificationTransport;
use MSahidurr\DeployNotifier\Services\SecretRedactor;
use Throwable;

/**
 * Sends messages through the Telegram Bot API. Delivery problems are
 * always caught and logged locally rather than thrown - a Telegram
 * outage must never be mistaken for, or cause, a deployment failure.
 */
class TelegramTransport implements NotificationTransport
{
    private readonly SecretRedactor $redactor;

    public function __construct(
        private readonly ?string $botToken,
        private readonly int $timeoutSeconds = 5,
        private readonly int $retryTimes = 2,
        private readonly int $retrySleepMs = 250,
    ) {
        // A small, local redactor scoped to this transport's own secret -
        // deliberately not the shared SecretRedactor singleton, so this
        // class keeps working as a plain `new TelegramTransport($token)`
        // with no other dependencies. It still gets the generic patterns
        // (bearer tokens, telegram-token-shaped strings, ...) for free.
        $this->redactor = new SecretRedactor([$this->botToken]);
    }

    public function send(string $destination, string $message, array $context = []): bool
    {
        if (! $this->botToken) {
            Log::warning('deploy-notifier: Telegram bot token is not configured; skipping delivery.', $context);

            return false;
        }

        if ($destination === '') {
            Log::warning('deploy-notifier: empty Telegram destination; skipping delivery.', $context);

            return false;
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->retry($this->retryTimes, $this->retrySleepMs, throw: false)
                ->asForm()
                ->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                    'chat_id' => $destination,
                    'text' => $message,
                    'disable_web_page_preview' => true,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('deploy-notifier: Telegram API responded with an error.', array_merge($context, [
                'status' => $response->status(),
                'body' => $this->redactor->redact($response->body()),
            ]));

            return false;
        } catch (Throwable $exception) {
            // The request URL itself contains the bot token
            // (https://api.telegram.org/bot<token>/sendMessage), and HTTP
            // client exceptions (timeouts, DNS failures, cURL errors)
            // routinely embed the full URL in their message - so the
            // token must be scrubbed before this ever reaches a log.
            Log::error('deploy-notifier: Telegram delivery failed with an exception.', array_merge($context, [
                'exception' => $this->redactor->redact($exception->getMessage()),
            ]));

            return false;
        }
    }
}
