<?php

namespace Tests\Unit;

use App\Services\EthiopianCalendar as E;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EthiopianCalendarTest extends TestCase
{
    /** Real, independently known conversions (Gregorian ISO => Ethiopian y-m-d). */
    public static function anchors(): array
    {
        return [
            'Enkutatash 2018 (new year)'            => ['2025-09-11', '2018-01-01'],
            'Enkutatash 2017'                       => ['2024-09-11', '2017-01-01'],
            'Enkutatash 2016 (after a leap year)'   => ['2023-09-12', '2016-01-01'],
            'Pagume 6 of a leap year'               => ['2023-09-11', '2015-13-06'],
            'Adwa Victory Day (Yekatit 23)'         => ['2024-03-02', '2016-06-23'],
            'Timkat (Tir 11)'                       => ['2025-01-19', '2017-05-11'],
            'Ethiopian millennium (Meskerem 1, 2000)' => ['2007-09-12', '2000-01-01'],
            'Gregorian 1 Jan 2000'                  => ['2000-01-01', '1992-04-22'],
            'Gregorian 1 Jan 1970'                  => ['1970-01-01', '1962-04-23'],
        ];
    }

    /** @dataProvider anchors */
    public function test_known_dates_convert_both_ways(string $gregorian, string $ethiopian): void
    {
        [$ey, $em, $ed] = array_map('intval', explode('-', $ethiopian));

        $e = E::fromIso($gregorian);
        $this->assertSame([$ey, $em, $ed], [$e['year'], $e['month'], $e['day']], "{$gregorian} -> Ethiopian");
        $this->assertSame($gregorian, E::toIso($ey, $em, $ed), "{$ethiopian} -> Gregorian");
    }

    public function test_every_day_from_1900_to_2100_round_trips_and_is_a_valid_ethiopian_date(): void
    {
        $day = Carbon::create(1900, 1, 1, 0, 0, 0, 'UTC');
        $end = Carbon::create(2100, 12, 31, 0, 0, 0, 'UTC');
        $previous = null;

        while ($day->lte($end)) {
            $iso = $day->format('Y-m-d');
            $e = E::fromIso($iso);

            $this->assertTrue(E::isValid($e['year'], $e['month'], $e['day']), "{$iso} produced an impossible Ethiopian date");
            $this->assertSame($iso, E::toIso($e['year'], $e['month'], $e['day']), "{$iso} did not round-trip");

            if ($previous) {   // consecutive days are consecutive Ethiopian days
                $expectedNext = $previous['day'] < E::daysInMonth($previous['year'], $previous['month'])
                    ? [$previous['year'], $previous['month'], $previous['day'] + 1]
                    : ($previous['month'] < 13 ? [$previous['year'], $previous['month'] + 1, 1] : [$previous['year'] + 1, 1, 1]);
                $this->assertSame($expectedNext, [$e['year'], $e['month'], $e['day']], "sequence broke at {$iso}");
            }

            $previous = $e;
            $day->addDay();
        }
    }

    public function test_leap_years_and_pagume_length(): void
    {
        $this->assertTrue(E::isLeapYear(2015));
        $this->assertTrue(E::isLeapYear(2019));
        $this->assertFalse(E::isLeapYear(2016));
        $this->assertFalse(E::isLeapYear(2018));

        $this->assertSame(6, E::daysInMonth(2015, 13));
        $this->assertSame(5, E::daysInMonth(2018, 13));
        $this->assertSame(30, E::daysInMonth(2018, 1));
        $this->assertSame(30, E::daysInMonth(2018, 12));
    }

    public function test_invalid_ethiopian_dates_are_rejected(): void
    {
        foreach ([[2018, 13, 6], [2018, 14, 1], [2018, 0, 1], [2018, 1, 31], [2018, 1, 0], [0, 1, 1]] as [$y, $m, $d]) {
            $this->assertFalse(E::isValid($y, $m, $d), "{$y}-{$m}-{$d} should be invalid");
        }
        $this->assertTrue(E::isValid(2019, 13, 6));

        $this->expectException(InvalidArgumentException::class);
        E::toGregorian(2018, 13, 6);
    }

    public function test_invalid_gregorian_dates_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        E::fromGregorian(2026, 2, 30);
    }

    public function test_formatting_uses_the_requested_language(): void
    {
        $this->assertSame('1 Meskerem 2018', E::format('2025-09-11'));
        $this->assertSame('1 Meskerem 2018', E::format(Carbon::create(2025, 9, 11)));
        $this->assertSame('1 መስከረም 2018', E::format('2025-09-11', 'am'));
        $this->assertSame('1 Fuulbana 2018', E::format('2025-09-11', 'om'));
        $this->assertSame('6 Pagume 2015', E::format('2023-09-11'));
        $this->assertSame('6 ጳጉሜን 2015', E::format('2023-09-11', 'am'));
        $this->assertSame('1 Meskerem 2018', E::format('2025-09-11', 'xx'), 'unknown language falls back to English');
        $this->assertSame('23 Yekatit 2016', E::format('2024-03-02 14:30:00'), 'a time part is ignored');
    }
}
