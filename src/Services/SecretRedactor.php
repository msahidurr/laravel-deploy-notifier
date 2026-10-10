<?php

namespace MSahidurr\DeployNotifier\Services;

/**
 * Centralized redaction so no code path can accidentally leak a bot
 * token, webhook secret, or other credential into a Telegram message
 * or a log line.
 */
class SecretRedactor
{
    /** @var string[] */
    private array $secrets;

    /**
     * @param  string[]  $secrets  Raw secret values to redact. Empty/null
     *                              entries are ignored automatically.
     */
    public function __construct(array $secrets = [])
    {
        $this->secrets = array_values(array_filter(
            $secrets,
            static fn ($value) => is_string($value) && $value !== ''
        ));
    }

    public function redact(string $text): string
    {
        $redacted = $text;

        foreach ($this->secrets as $secret) {
            $redacted = str_replace($secret, '[REDACTED]', $redacted);
        }

        return $this->stripCommonSecretPatterns($redacted);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function redactArray(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = $this->redact($value);
            } elseif (is_array($value)) {
                $context[$key] = $this->redactArray($value);
            }
        }

        return $context;
    }

    /**
     * Belt-and-suspenders pass that masks values commonly associated
     * with credentials, even if they were never registered explicitly.
     */
    private function stripCommonSecretPatterns(string $text): string
    {
        $patterns = [
            // Telegram bot tokens: 123456789:AA...
            '/\b\d{6,10}:[A-Za-z0-9_-]{30,}\b/' => '[REDACTED]',
            // Bearer tokens / Authorization headers
            '/\bBearer\s+[A-Za-z0-9._-]+\b/i' => 'Bearer [REDACTED]',
            // GitHub HMAC signature header values
            '/\bsha256=[a-f0-9]{64}\b/i' => 'sha256=[REDACTED]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }
}
