<?php

namespace App\Services;

use Exception;

/**
 * Pure classification logic extracted from CampaignQueueService so it can be
 * unit-tested without a database or a live Orange connection (cahier des
 * charges §8-§9, §59). Maps an SDK/network exception to one of the required
 * error categories and decides whether a retry is worth attempting.
 */
class SmsErrorClassifier
{
    public const RETRYABLE_CODES = ['API_ERROR', 'TIMEOUT', 'RATE_LIMIT', 'UNKNOWN_ERROR'];

    /**
     * @return array{0:string,1:bool} [error code, is retryable]
     */
    public static function classify(Exception $e): array
    {
        return self::classifyMessage($e->getMessage());
    }

    /**
     * @return array{0:string,1:bool}
     */
    public static function classifyMessage(string $message): array
    {
        $msg = strtolower($message);

        if (str_contains($msg, 'invalid') && str_contains($msg, 'phone')) {
            return ['INVALID_PHONE', false];
        }
        if (str_contains($msg, 'auth_error') || str_contains($msg, 'unauthorized') || str_contains($msg, '401')) {
            return ['AUTH_ERROR', false];
        }
        if (str_contains($msg, 'insufficient') || str_contains($msg, 'balance') || str_contains($msg, 'quota')) {
            return ['INSUFFICIENT_BALANCE', false];
        }
        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return ['TIMEOUT', true];
        }
        if (str_contains($msg, 'rate limit') || str_contains($msg, '429')) {
            return ['RATE_LIMIT', true];
        }
        if (str_contains($msg, 'api_error')) {
            return ['API_ERROR', true];
        }

        return ['UNKNOWN_ERROR', true];
    }

    public static function isRetryable(string $code): bool
    {
        return in_array($code, self::RETRYABLE_CODES, true);
    }
}
