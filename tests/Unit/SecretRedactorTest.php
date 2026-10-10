<?php

namespace MSahidurr\DeployNotifier\Tests\Unit;

use MSahidurr\DeployNotifier\Services\SecretRedactor;
use MSahidurr\DeployNotifier\Tests\TestCase;

class SecretRedactorTest extends TestCase
{
    public function test_it_redacts_registered_secrets(): void
    {
        $redactor = new SecretRedactor(['super-secret-token']);

        $result = $redactor->redact('The token is super-secret-token, keep it safe.');

        $this->assertStringNotContainsString('super-secret-token', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function test_it_ignores_empty_secrets(): void
    {
        $redactor = new SecretRedactor(['', null, false]);

        $result = $redactor->redact('Nothing sensitive here.');

        $this->assertSame('Nothing sensitive here.', $result);
    }

    public function test_it_masks_telegram_bot_token_shaped_strings_even_if_unregistered(): void
    {
        $redactor = new SecretRedactor([]);

        $result = $redactor->redact('token: 123456789:AAFakeTelegramBotTokenValueForTesting123');

        $this->assertStringNotContainsString('AAFakeTelegramBotTokenValueForTesting123', $result);
    }

    public function test_it_masks_bearer_tokens(): void
    {
        $redactor = new SecretRedactor([]);

        $result = $redactor->redact('Authorization: Bearer abc123.def456');

        $this->assertSame('Authorization: Bearer [REDACTED]', $result);
    }

    public function test_it_redacts_nested_arrays(): void
    {
        $redactor = new SecretRedactor(['leak-me']);

        $result = $redactor->redactArray([
            'top' => 'contains leak-me value',
            'nested' => ['deep' => 'also contains leak-me'],
        ]);

        $this->assertStringNotContainsString('leak-me', $result['top']);
        $this->assertStringNotContainsString('leak-me', $result['nested']['deep']);
    }
}
