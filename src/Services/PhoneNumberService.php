<?php

namespace App\Services;

/**
 * Normalizes and validates Guinean phone numbers (cahier des charges §10).
 * Accepted inputs: 622xxxxxx, +224622xxxxxx, 00224622xxxxxx.
 * Canonical output: +224XXXXXXXXX (9 digits after the country code).
 */
class PhoneNumberService
{
    public static function normalize(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        // 00224XXXXXXXXX -> 224XXXXXXXXX
        if (str_starts_with($digits, '00224')) {
            $digits = substr($digits, 2);
        }

        // 224XXXXXXXXX already has the country code.
        if (str_starts_with($digits, '224') && strlen($digits) === 12) {
            $national = substr($digits, 3);
        } elseif (strlen($digits) === 9) {
            // Local format: 6XXXXXXXX
            $national = $digits;
        } else {
            return null;
        }

        if (!preg_match('/^6\d{8}$/', $national)) {
            return null;
        }

        return '+224' . $national;
    }

    public static function isValid(string $raw): bool
    {
        return self::normalize($raw) !== null;
    }
}
