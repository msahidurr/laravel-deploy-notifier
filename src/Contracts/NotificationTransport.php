<?php

namespace MSahidurr\DeployNotifier\Contracts;

interface NotificationTransport
{
    /**
     * Deliver a pre-formatted message to the given destination.
     *
     * Implementations must never throw for ordinary delivery failures
     * (network errors, timeouts, API error responses). They should
     * catch those, log them, and return false instead - a notification
     * failure must never be allowed to affect the caller's own exit
     * status or control flow.
     *
     * @param  string  $destination  Transport-specific destination identifier
     *                                (e.g. a Telegram chat id).
     * @param  string  $message      Fully formatted message body.
     * @param  array<string, mixed>  $context  Extra data for logging/metrics.
     */
    public function send(string $destination, string $message, array $context = []): bool;
}
