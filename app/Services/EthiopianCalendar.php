<?php

namespace App\Services;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Gregorian <-> Ethiopian (Ge'ez) date conversion. Pure arithmetic on Julian
 * Day Numbers: no PHP calendar extension needed, no database, no state.
 *
 * The Ethiopian calendar has 12 months of 30 days plus Pagume (5 days, 6 in a
 * leap year). A year is a leap year when year % 4 == 3. Year 1 started on
 * Meskerem 1 = JDN 1724221 (29 Aug 8 CE, Julian).
 *
 * This is the ONLY place the two calendars are converted. Everything is STORED
 * as Gregorian dates / UTC timestamps; conversion happens only at the edges
 * (showing a date, or reading a date the user typed).
 */
class EthiopianCalendar
{
    private const EPOCH_JDN = 1724221;

    public const MONTHS = [
        'en' => ['Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miazia', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume'],
        'am' => ['መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜን'],
        'ti' => ['መስከረም', 'ጥቅምቲ', 'ሕዳር', 'ታሕሳስ', 'ጥሪ', 'ለካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰነ', 'ሓምለ', 'ነሓሰ', 'ጳጉሜን'],
        'om' => ['Fuulbana', 'Onkoloolessa', 'Sadaasa', 'Muddee', 'Amajjii', 'Guraandhala', 'Bitootessa', 'Ebla', 'Caamsaa', 'Waxabajjii', 'Adoolessa', 'Hagayya', 'Qaammee'],
    ];

    public static function isLeapYear(int $year): bool
    {
        return $year % 4 === 3;
    }

    public static function daysInMonth(int $year, int $month): int
    {
        return $month === 13 ? (self::isLeapYear($year) ? 6 : 5) : 30;
    }

    public static function isValid(int $year, int $month, int $day): bool
    {
        return $year >= 1 && $month >= 1 && $month <= 13 && $day >= 1 && $day <= self::daysInMonth($year, $month);
    }

    /** @return array{year:int, month:int, day:int} */
    public static function fromGregorian(int $year, int $month, int $day): array
    {
        if (! checkdate($month, $day, $year)) {
            throw new InvalidArgumentException("Not a valid Gregorian date: {$year}-{$month}-{$day}");
        }

        // Count days from the start of year 0 (a 365-day year), so each 4-year
        // block is years 0,1,2 (365 days) and 3 (366 days: the leap year).
        $days = self::gregorianToJdn($year, $month, $day) - (self::EPOCH_JDN - 365);
        $r = $days % 1461;
        $n = ($r % 365) + 365 * intdiv($r, 1460);

        return [
            'year' => 4 * intdiv($days, 1461) + intdiv($r, 365) - intdiv($r, 1460),
            'month' => intdiv($n, 30) + 1,
            'day' => ($n % 30) + 1,
        ];
    }

    /** @return array{year:int, month:int, day:int} */
    public static function toGregorian(int $year, int $month, int $day): array
    {
        if (! self::isValid($year, $month, $day)) {
            throw new InvalidArgumentException("Not a valid Ethiopian date: {$year}-{$month}-{$day}");
        }

        $jdn = self::EPOCH_JDN + 365 * ($year - 1) + intdiv($year, 4) + 30 * ($month - 1) + ($day - 1);

        return self::jdnToGregorian($jdn);
    }

    /** ISO 'YYYY-MM-DD' (Gregorian) -> Ethiopian parts. */
    public static function fromIso(string $iso): array
    {
        [$y, $m, $d] = array_map('intval', explode('-', substr($iso, 0, 10)));

        return self::fromGregorian($y, $m, $d);
    }

    /** Ethiopian parts -> ISO 'YYYY-MM-DD' (Gregorian), the form that is stored. */
    public static function toIso(int $year, int $month, int $day): string
    {
        $g = self::toGregorian($year, $month, $day);

        return sprintf('%04d-%02d-%02d', $g['year'], $g['month'], $g['day']);
    }

    public static function monthName(int $month, string $locale = 'en'): string
    {
        return (self::MONTHS[$locale] ?? self::MONTHS['en'])[$month - 1];
    }

    /** e.g. "11 Meskerem 2018" */
    public static function format(CarbonInterface|string $date, string $locale = 'en'): string
    {
        $iso = $date instanceof CarbonInterface ? $date->format('Y-m-d') : substr($date, 0, 10);
        $e = self::fromIso($iso);

        return "{$e['day']} ".self::monthName($e['month'], $locale)." {$e['year']}";
    }

    // ---------------------------------------------------------------- JDN math

    private static function gregorianToJdn(int $year, int $month, int $day): int
    {
        $a = intdiv(14 - $month, 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;

        return $day + intdiv(153 * $m + 2, 5) + 365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) - 32045;
    }

    /** @return array{year:int, month:int, day:int} */
    private static function jdnToGregorian(int $jdn): array
    {
        $a = $jdn + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $d = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $d, 4);
        $m = intdiv(5 * $e + 2, 153);

        return [
            'day' => $e - intdiv(153 * $m + 2, 5) + 1,
            'month' => $m + 3 - 12 * intdiv($m, 10),
            'year' => 100 * $b + $d - 4800 + intdiv($m, 10),
        ];
    }
}
