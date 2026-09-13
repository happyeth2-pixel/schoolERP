<?php

namespace App\Http\Controllers;

use App\Models\Section;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceReportController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->can('view-attendance-reports') || abort(403);
        $user = $request->user();

        $date = $request->query('date', now()->format('Y-m-d'));

        $sectionsQuery = Section::query()
            ->whereIn('branch_id', $user->school->branches()->pluck('id'))
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->with(['grade', 'branch', 'enrollments' => fn ($q) => $q->where('status', 'active')])
            ->with(['enrollments.attendanceRecords' => fn ($q) => $q->where('date', $date)]);

        if (! $user->isOrgWide()) {
            $sectionsQuery->where('branch_id', $user->branch_id);
        }

        $sections = $sectionsQuery->orderBy('grade_id')->get()->map(function (Section $s) {
            $total = $s->enrollments->count();
            $counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'sick' => 0];
            $marked = 0;

            foreach ($s->enrollments as $enrollment) {
                $record = $enrollment->attendanceRecords->first();
                if ($record) {
                    $counts[$record->status]++;
                    $marked++;
                }
            }

            return [
                'id' => $s->id,
                'label' => "{$s->grade->name} {$s->name}",
                'branch' => $s->branch->name,
                'total' => $total,
                'marked' => $marked,
                'counts' => $counts,
                'attendance_rate' => $marked > 0 ? round(($counts['present'] + $counts['late']) / $marked * 100, 1) : null,
            ];
        });

        $overall = [
            'total_students' => $sections->sum('total'),
            'total_marked' => $sections->sum('marked'),
            'total_present' => $sections->sum(fn ($s) => $s['counts']['present']),
            'total_absent' => $sections->sum(fn ($s) => $s['counts']['absent']),
        ];

        return Inertia::render('Attendance/Report', [
            'date' => $date,
            'sections' => $sections,
            'overall' => $overall,
        ]);
    }
}
