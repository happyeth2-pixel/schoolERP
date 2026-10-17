<?php

namespace App\Support;

/**
 * One canonical form for phone numbers so that search and duplicate detection
 * work no matter how someone typed it.
 *
 * Stored: E.164 with the Ethiopian country code, e.g. "+251911223344".
 * Accepted for Ethiopia: 0911223344, 911223344, 251911223344, +251911223344,
 * 00251911223344, with any spaces, dashes, dots or brackets (also landlines such
 * as 011 123 4567). A number with a different country code (+44...) is kept as
 * international E.164. Shown locally as "0911 22 33 44".
 */
class PhoneNumber
{
    /** The canonical stored form, or null when the value is empty or not a valid number. */
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $international = str_starts_with($value, '+') || str_starts_with(preg_replace('/[^\d+]/', '', $value), '00');
        $digits = preg_replace('/\D+/', '', $value);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '251')) {
            $national = substr($digits, 3);
        } elseif ($international) {
            // some other country's number: keep it, in E.164
            return preg_match('/^[1-9]\d{7,14}$/', $digits) ? '+'.$digits : null;
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } else {
            $national = $digits;
        }

        return preg_match('/^[1-9]\d{8}$/', $national) ? '+251'.$national : null;
    }

    /**
     * The canonical form of an Ethiopian MOBILE number (+2519XXXXXXXX or +2517XXXXXXXX), or null. This is what an account
     * signs in with: 0911..., 0711..., 911..., +251911..., 251911... and 00251911... all become +251911...
     */
    public static function normalizeMobile(?string $value): ?string
    {
        $n = self::normalize($value);

        return $n !== null && preg_match('/^\+251[79]\d{8}$/', $n) ? $n : null;
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }

    /** How to show a stored number to people in Ethiopia: 0911 22 33 44 (other countries stay international). */
    public static function display(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        $normalized = self::normalize($stored);
        if ($normalized === null) {
            return $stored;   // legacy free text we could not parse: show it untouched
        }

        if (! str_starts_with($normalized, '+251')) {
            return $normalized;
        }

        $n = substr($normalized, 4);

        return '0'.substr($n, 0, 3).' '.substr($n, 3, 2).' '.substr($n, 5, 2).' '.substr($n, 7, 2);
    }

    /**
     * The part of a number that is the same however it was typed (the nine
     * digits after 0 / 251), for "contains" matching while somebody is still
     * typing. Returns '' when the input has no digits.
     */
    public static function searchKey(?string $input): string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);
        $digits = preg_replace('/^(00)?251/', '', $digits);

        return ltrim($digits, '0');
    }
}
