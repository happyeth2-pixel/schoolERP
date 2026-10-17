<?php

namespace Tests\Feature;

use App\Models\School;
use App\Services\EthiopianCalendar;
use App\Support\SchoolCalendar;
use Tests\TestCase;

/**
 * Keeps tests/fixtures/calendar-conversions.json honest: every row must equal what
 * PHP computes today. `npm run test:calendar` then holds the JavaScript version
 * to the same fixture, so PHP and the browser can never disagree about a date.
 */
class CalendarFixtureTest extends TestCase
{
    public function test_the_shared_fixture_matches_php(): void
    {
        $rows = json_decode(file_get_contents(base_path('tests/fixtures/calendar-conversions.json')), true)['rows'];
        $this->assertGreaterThan(4000, count($rows));

        $both = new School(['calendar_preference' => 'both']);
        $eth = new School(['calendar_preference' => 'ethiopian']);
        $gre = new School(['calendar_preference' => 'gregorian']);

        foreach ($rows as [$iso, $y, $m, $d, $bothEn, $ethAm, $greg]) {
            $e = EthiopianCalendar::fromIso($iso);
            $this->assertSame([$y, $m, $d], [$e['year'], $e['month'], $e['day']], $iso);
            $this->assertSame($iso, EthiopianCalendar::toIso($y, $m, $d), "{$y}-{$m}-{$d}");
            $this->assertSame($bothEn, SchoolCalendar::date($iso, $both, 'en'), $iso);
            $this->assertSame($ethAm, SchoolCalendar::date($iso, $eth, 'am'), $iso);
            $this->assertSame($greg, SchoolCalendar::date($iso, $gre, 'en'), $iso);
        }
    }
}
