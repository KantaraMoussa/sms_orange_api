<?php

namespace App\Services;

/**
 * Calcule le nombre réel de SMS nécessaires pour un message (cahier des
 * charges §18/§26) : détecte l'encodage (GSM 7 bits vs UCS-2) réellement
 * utilisé par l'opérateur et applique les seuils de segmentation corrects
 * pour ne jamais sous-estimer le nombre de SMS facturés.
 */
class SmsCounterService
{
    // Alphabet GSM 03.38 de base (1 caractère = 7 bits).
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\x1BÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    // Caractères étendus GSM : nécessitent un caractère d'échappement, donc comptent double.
    private const GSM_EXTENDED = "^{}\\[~]|€";

    /**
     * @return array{length:int, encoding:string, per_segment:int, segments:int}
     */
    public static function analyze(string $message): array
    {
        $chars = self::splitChars($message);
        $length = count($chars);

        $isGsm = true;
        $gsmUnits = 0;
        foreach ($chars as $c) {
            if (str_contains(self::GSM_BASIC, $c)) {
                $gsmUnits += 1;
            } elseif (str_contains(self::GSM_EXTENDED, $c)) {
                $gsmUnits += 2;
            } else {
                $isGsm = false;
                break;
            }
        }

        if ($isGsm) {
            $encoding = 'GSM-7';
            $singleLimit = 160;
            $multiLimit = 153;
            $units = $gsmUnits;
        } else {
            $encoding = 'UCS-2';
            $singleLimit = 70;
            $multiLimit = 67;
            $units = $length;
        }

        if ($units === 0) {
            $segments = 0;
        } elseif ($units <= $singleLimit) {
            $segments = 1;
        } else {
            $segments = (int) ceil($units / $multiLimit);
        }

        return [
            'length' => $length,
            'encoding' => $encoding,
            'per_segment' => $segments <= 1 ? $singleLimit : $multiLimit,
            'segments' => $segments,
        ];
    }

    /**
     * Découpe en caractères en respectant les points de code multi-octets (accents, emoji…),
     * sinon un simple strlen() couperait un caractère UTF-8 en plusieurs octets.
     *
     * @return list<string>
     */
    private static function splitChars(string $message): array
    {
        $result = preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY);

        return $result !== false ? $result : str_split($message);
    }
}
