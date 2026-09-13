<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Section;
use App\Notifications\StudentMarkedAbsent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    /**
     * Sections the current user is allowed to take attendance for:
     * a Teacher can only do their own homeroom section(s); an
     * Owner/Branch Admin with mark-attendance explicitly granted can
     * do any section in their scope (covers a Principal filling in for
     * an absent class teacher).
     */
    private function sectionsInScope(Request $request)
    {
        $user = $request->user();
        $query = Section::query()
            ->whereIn('branch_id', $user->school->branches()->pluck('id'))
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->with('grade', 'branch');

        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        if ($user->hasRole('teacher')) {
            $query->where('class_teacher_id', $user->id);
        }

        return $query;
    }

    public function index(Request $request): Response
    {
        $request->user()->can('mark-attendance') || abort(403);

        $sections = $this->sectionsInScope($request)->orderBy('grade_id')->get()->map(fn (Section $s) => [
            'id' => $s->id,
            'label' => "{$s->grade->name} {$s->name}",
        ]);

        return Inertia::render('Attendance/Sections', ['sections' => $sections]);
    }

    public function show(Request $request, Section $section): Response
    {
        $request->user()->can('mark-attendance') || abort(403);
        $this->assertCanMark($request->user(), $section);

        $date = $request->query('date', now()->format('Y-m-d'));

        $enrollments = $section->enrollments()
            ->where('status', 'active')
            ->with(['student', 'attendanceRecords' => fn ($q) => $q->where('date', $date)])
            ->get()
            ->sortBy(fn ($e) => $e->student->first_name)
            ->values();

        return Inertia::render('Attendance/Take', [
            'section' => ['id' => $section->id, 'label' => "{$section->grade->name} {$section->name}"],
            'date' => $date,
            'roster' => $enrollments->map(fn ($e) => [
                'enrollment_id' => $e->id,
                'student_name' => $e->student->fullName(),
                'student_id_number' => $e->student->student_id_number,
                'status' => $e->attendanceRecords->first()->status ?? null,
            ]),
        ]);
    }

    public function store(Request $request, Section $section): RedirectResponse
    {
        $request->user()->can('mark-attendance') || abort(403);
        $this->assertCanMark($request->user(), $section);

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'records' => ['required', 'array'],
            'records.*.enrollment_id' => ['required', 'integer'],
            'records.*.status' => ['required', 'in:present,absent,late,excused,sick'],
        ]);

        $validEnrollmentIds = $section->enrollments()->where('status', 'active')->pluck('id');

        foreach ($validated['records'] as $record) {
            abort_unless($validEnrollmentIds->contains($record['enrollment_id']), 422);

            $existing = AttendanceRecord::where('enrollment_id', $record['enrollment_id'])
                ->where('date', $validated['date'])
                ->first();

            $wasAbsent = $existing && $existing->status === 'absent';

            $attendance = AttendanceRecord::updateOrCreate(
                ['enrollment_id' => $record['enrollment_id'], 'date' => $validated['date']],
                ['status' => $record['status'], 'marked_by' => $request->user()->id]
            );

            if ($record['status'] === 'absent' && ! $wasAbsent) {
                $enrollment = $attendance->enrollment()->with('student.guardians')->first();
                foreach ($enrollment->student->guardians as $guardian) {
                    $guardian->notify(new StudentMarkedAbsent($enrollment->student, Carbon::parse($validated['date'])));
                }
            }
        }

        return back()->with('success', 'Attendance saved.');
    }

    private function assertCanMark($user, Section $section): void
    {
        $branch = $user->school->branches()->whereKey($section->branch_id)->first();
        abort_unless($branch, 403);

        if (! $user->isOrgWide()) {
            abort_unless($user->branch_id === $section->branch_id, 403);
        }

        if ($user->hasRole('teacher')) {
            abort_unless($section->class_teacher_id === $user->id, 403, 'Only this section\'s class teacher can take its attendance.');
        }
    }
}
