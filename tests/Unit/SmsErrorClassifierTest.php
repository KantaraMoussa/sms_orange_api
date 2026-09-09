<?php

namespace Tests\Unit;

use App\Services\SmsErrorClassifier;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Covers cahier des charges §8 (catégories d'erreur) and §9 (stratégie de retry).
 */
class SmsErrorClassifierTest extends TestCase
{
    /** @dataProvider messages */
    public function testClassifiesMessageToExpectedCodeAndRetryability(string $message, string $expectedCode, bool $expectedRetryable): void
    {
        [$code, $retryable] = SmsErrorClassifier::classify(new Exception($message));

        $this->assertSame($expectedCode, $code);
        $this->assertSame($expectedRetryable, $retryable);
    }

    public static function messages(): array
    {
        return [
            'invalid phone number' => ['Invalid phone number format', 'INVALID_PHONE', false],
            'unauthorized' => ['401 Unauthorized: bad credentials', 'AUTH_ERROR', false],
            'auth error keyword' => ['AUTH_ERROR: token expired', 'AUTH_ERROR', false],
            'insufficient balance' => ['Insufficient balance for this operation', 'INSUFFICIENT_BALANCE', false],
            'quota exceeded' => ['Monthly quota exceeded', 'INSUFFICIENT_BALANCE', false],
            'timeout' => ['cURL error 28: Operation timed out', 'TIMEOUT', true],
            'rate limited' => ['429 Too Many Requests: rate limit exceeded', 'RATE_LIMIT', true],
            'generic api error' => ['API_ERROR: Orange did not return an access token.', 'API_ERROR', true],
            'unrecognized error' => ['Something exploded unexpectedly', 'UNKNOWN_ERROR', true],
        ];
    }

    public function testNonRetryableCodesAreNeverRetryable(): void
    {
        foreach (['INVALID_PHONE', 'AUTH_ERROR', 'INSUFFICIENT_BALANCE'] as $code) {
            $this->assertFalse(SmsErrorClassifier::isRetryable($code));
        }
    }

    public function testRetryableCodesMatchTheDocumentedList(): void
    {
        foreach (['API_ERROR', 'TIMEOUT', 'RATE_LIMIT', 'UNKNOWN_ERROR'] as $code) {
            $this->assertTrue(SmsErrorClassifier::isRetryable($code));
        }
    }
}
