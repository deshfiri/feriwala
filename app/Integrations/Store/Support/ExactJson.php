<?php

namespace App\Integrations\Store\Support;

use JsonException;

/**
 * JSON decoding that never turns a number into a float (D26).
 *
 * A store sends `"grand": 910.5`. `json_decode()` would make that a PHP float
 * before any code could stop it, and money must never pass through one. So every
 * number outside a string is quoted first, exactly as written, and decoded as
 * text: `"910.5"`. The amount parser then reads it exactly or refuses it.
 *
 * The scanner tracks whether it is inside a string (and escapes within one), so
 * digits in a customer's address are left exactly as they were.
 */
final class ExactJson
{
    /**
     * @throws JsonException
     */
    public static function decode(string $json): mixed
    {
        $quoted = '';
        $length = strlen($json);
        $inString = false;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $character = $json[$i];

            if ($inString) {
                $quoted .= $character;

                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;
                $quoted .= $character;

                continue;
            }

            if ($character === '-' || ctype_digit($character)) {
                $end = $i;

                while ($end < $length && str_contains('0123456789+-.eE', $json[$end])) {
                    $end++;
                }

                $quoted .= '"'.substr($json, $i, $end - $i).'"';
                $i = $end - 1;

                continue;
            }

            $quoted .= $character;
        }

        return json_decode($quoted, true, 64, JSON_THROW_ON_ERROR);
    }
}
