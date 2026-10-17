<?php

namespace Tests\Unit;

use App\Support\Backup\BackupNaming;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class BackupNamingTest extends TestCase
{
    private function naming(): BackupNaming
    {
        return new BackupNaming(['daily_days' => 14, 'weekly_days' => 60, 'monthly_days' => 400, 'weekly_on' => 0]);
    }

    public function test_an_ordinary_weekday_goes_only_into_daily(): void
    {
        // 2026-09-23 is a Wednesday
        $this->assertSame(['daily'], $this->naming()->foldersFor(CarbonImmutable::parse('2026-09-23')));
    }

    public function test_a_sunday_also_goes_into_weekly(): void
    {
        // 2026-09-20 is a Sunday
        $this->assertSame(['daily', 'weekly'], $this->naming()->foldersFor(CarbonImmutable::parse('2026-09-20')));
    }

    public function test_the_first_of_the_month_also_goes_into_monthly(): void
    {
        // 2026-09-01 is a Tuesday, so this is a "1st that isn't also a Sunday"
        $this->assertSame(['daily', 'monthly'], $this->naming()->foldersFor(CarbonImmutable::parse('2026-09-01')));
    }

    public function test_a_sunday_that_is_also_the_first_goes_into_all_three(): void
    {
        // 2026-11-01 is a Sunday
        $this->assertSame(['daily', 'weekly', 'monthly'], $this->naming()->foldersFor(CarbonImmutable::parse('2026-11-01')));
    }

    public function test_stem_is_a_plain_sortable_date(): void
    {
        $this->assertSame('2026-09-23', $this->naming()->stemFor(CarbonImmutable::parse('2026-09-23 14:30:00')));
    }

    public function test_expiry_is_exclusive_of_the_retention_boundary_itself(): void
    {
        $naming = $this->naming();
        $now = CarbonImmutable::parse('2026-09-23');

        $this->assertFalse($naming->isExpired(CarbonImmutable::parse('2026-09-10'), 'daily', $now), 'exactly 13 days old, within a 14-day window');
        $this->assertFalse($naming->isExpired(CarbonImmutable::parse('2026-09-09'), 'daily', $now), 'exactly 14 days old, still within the window');
        $this->assertTrue($naming->isExpired(CarbonImmutable::parse('2026-09-08'), 'daily', $now), '15 days old, outside a 14-day window');
    }

    public function test_each_folder_uses_its_own_retention(): void
    {
        $naming = $this->naming();
        $now = CarbonImmutable::parse('2026-09-23');
        $twentyDaysAgo = CarbonImmutable::parse('2026-09-03');

        $this->assertTrue($naming->isExpired($twentyDaysAgo, 'daily', $now), '20 days is past the 14-day daily window');
        $this->assertFalse($naming->isExpired($twentyDaysAgo, 'weekly', $now), '20 days is within the 60-day weekly window');
        $this->assertFalse($naming->isExpired($twentyDaysAgo, 'monthly', $now), '20 days is well within the 400-day monthly window');
    }

    public function test_an_unrecognized_folder_is_never_reported_expired(): void
    {
        $this->assertFalse($this->naming()->isExpired(CarbonImmutable::parse('2000-01-01'), 'yearly', CarbonImmutable::now()));
    }

    public function test_date_is_parsed_back_out_of_a_filename(): void
    {
        $naming = $this->naming();
        $this->assertSame('2026-09-23', $naming->dateFromFilename('2026-09-23.dump.gpg')->toDateString());
        $this->assertSame('2026-09-23', $naming->dateFromFilename('daily/2026-09-23.storage.tar.gz.gpg')->toDateString());
        $this->assertNull($naming->dateFromFilename('manifest.json'));
        $this->assertNull($naming->dateFromFilename('not-a-date-here.txt'));
    }
}
