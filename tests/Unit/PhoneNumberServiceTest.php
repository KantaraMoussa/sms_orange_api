<?php

namespace Tests\Unit;

use App\Services\PhoneNumberService;
use PHPUnit\Framework\TestCase;

/**
 * Covers cahier des charges §10 (normalisation) and §59 ("validation téléphone").
 */
class PhoneNumberServiceTest extends TestCase
{
    /** @dataProvider validNumbers */
    public function testNormalizesValidGuineanNumbers(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumberService::normalize($input));
    }

    public static function validNumbers(): array
    {
        return [
            'local format' => ['622112233', '+224622112233'],
            'already E.164' => ['+224622112233', '+224622112233'],
            'international prefix 00' => ['00224622112233', '+224622112233'],
            'with separators/spaces' => ['622-11-22-33', '+224622112233'],
            'bare country code, no plus' => ['224622112233', '+224622112233'],
            'with surrounding whitespace' => [' 622112233 ', '+224622112233'],
        ];
    }

    /** @dataProvider invalidNumbers */
    public function testRejectsInvalidNumbers(string $input): void
    {
        $this->assertNull(PhoneNumberService::normalize($input));
        $this->assertFalse(PhoneNumberService::isValid($input));
    }

    public static function invalidNumbers(): array
    {
        return [
            'too short' => ['12345'],
            'wrong country code' => ['+225622112233'],
            'wrong leading digit (not 6)' => ['722112233'],
            'empty string' => [''],
            'letters only' => ['abcdefghi'],
            'one digit missing' => ['62211223'],
            'one digit extra' => ['6221122334'],
        ];
    }

    public function testIsValidMatchesNormalize(): void
    {
        $this->assertTrue(PhoneNumberService::isValid('622112233'));
        $this->assertFalse(PhoneNumberService::isValid('12345'));
    }
}
