<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Support\SchoolCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Regression test for a real bug a user found while reviewing the production-readiness pass: Take Attendance
 * showed "5 of 5 marked" for a section marked today, but the Attendance Report page showed that same section
 * as "0 marked / Not marked" on the same day. Cause: attendance_records.date is a `date`-cast column, but
 * Laravel stores the underlying value with a "00:00:00" time suffix -- so the report's eager-load closure
 * (`where('date', $date)`, an exact string match against "Y-m-d") never matched a stored row, while
 * AttendanceController's own `whereDate('date', $date)` was unaffected by the suffix. Fixed in
 * AttendanceReportController and DashboardController::orgStats() by switching both to whereDate().
 */
class AttendanceReportTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private function markPresentToday(string $date): void
    {
        $this->actingAs($this->owner)->post(route('attendance.store', $this->sectionA), [
            'date' => $date,
            'records' => [[
                'enrollment_id' => Enrollment::where('student_id', $this->studentA->id)->value('id'),
                'status' => 'present',
            ]],
        ])->assertSessionHasNoErrors();
    }

    public function test_the_report_counts_todays_marks_the_same_day_they_were_saved(): void
    {
        $this->buildSchool();
        $today = SchoolCalendar::today()->toDateString();
        $this->markPresentToday($today);

        $page = $this->props($this->actingAs($this->owner)->get(route('attendance-report.index')));
        $row = collect($page['sections'])->firstWhere('id', $this->sectionA->id);

        $this->assertSame(1, $row['marked'], 'the section marked today must show as marked in the report, not 0');
        $this->assertSame(1, $row['counts']['present']);
        $this->assertSame(1, $page['overall']['total_marked']);
    }

    public function test_the_owner_dashboards_today_marked_count_matches_what_was_actually_saved(): void
    {
        $this->buildSchool();
        $today = SchoolCalendar::today()->toDateString();
        $this->markPresentToday($today);

        $page = $this->props($this->actingAs($this->owner)->get(route('dashboard')));

        $this->assertSame(1, $page['stats']['today_marked'], 'the dashboard must count a mark saved today, not show 0');
        $this->assertSame(100.0, $page['stats']['today_attendance_rate']);
    }
}
