<?php

namespace App\Http\Controllers;

use App\Models\PeriodAttendanceRecord;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Support\SchoolCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance for a single class period -- academic expansion, Phase 2. Deliberately mirrors the EXISTING
 * daily/homeroom `AttendanceController`'s own access rule almost exactly ("restricted to the assigned
 * teacher or admins," the brief's own words): a Teacher may only mark the ONE `TimetableSlot` they are
 * actually the teacher of (never any other teacher's subject, even in the same class); anyone else holding
 * `mark-attendance` (Owner, Branch Admin) can mark any slot in their scope, covering an absent teacher --
 * reusing the EXISTING permission rather than adding a new one, since this is the same underlying action
 * (marking attendance) at finer granularity. No `sick` status here (unlike daily attendance) and no guardian
 * notification on a period absence -- the brief's own "do not spam" instruction: a student can have several
 * periods a day, and the one daily/homeroom absence notice already reaches the family.
 */
class PeriodAttendanceController extends Controller
{
    private function slotsInScope(Request $request, string $date)
    {
        $user = $request->user();
        $dayOfWeek = Carbon::parse($date)->dayOfWeekIso;

        $query = TimetableSlot::where('day_of_week', $dayOfWeek)
            ->whereHas('term', fn ($q) => $q->where('start_date', '<=', $date)->where('end_date', '>=', $date))
            ->with(['section.grade', 'subject', 'period', 'teacher']);

        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        if ($user->hasRole('teacher')) {
            $query->where('teacher_id', $user->id);
        } elseif ($request->filled('teacher_id')) {
            $query->where('teacher_id', $request->integer('teacher_id'));
        }

        return $query;
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('mark-attendance'), 403);

        $date = $request->query('date', SchoolCalendar::today()->toDateString());

        $teachers = collect();
        if (! $user->hasRole('teacher')) {
            $branchIds = $user->isOrgWide() ? $user->school->branches()->pluck('id') : collect([$user->branch_id]);
            $teachers = User::where('school_id', $user->school_id)->whereIn('branch_id', $branchIds)
                ->whereHas('roles', fn ($q) => $q->where('name', 'teacher'))->orderBy('name')->get(['id', 'name']);
        }
        $teacherId = $request->integer('teacher_id') ?: null;

        $slots = (! $user->hasRole('teacher') && ! $teacherId)
            ? collect()
            : $this->slotsInScope($request, $date)->orderBy('period_id')->get()->map(function (TimetableSlot $s) use ($date) {
                $rosterCount = $s->section->enrollments()->active()->count();
                $markedCount = PeriodAttendanceRecord::where('timetable_slot_id', $s->id)->whereDate('date', $date)->count();

                return [
                    'id' => $s->id,
                    'period_label' => $s->period->label(),
                    'section' => $s->section->auditLabel(),
                    'subject' => $s->subject->name,
                    'teacher' => $s->teacher->name,
                    'roster_count' => $rosterCount,
                    'marked_count' => $markedCount,
                    'status' => $rosterCount === 0
                        ? 'empty'
                        : ($markedCount === 0 ? 'none' : ($markedCount < $rosterCount ? 'partial' : 'complete')),
                ];
            });

        return Inertia::render('Academics/PeriodAttendance/Index', [
            'date' => $date,
            'today' => SchoolCalendar::today()->toDateString(),
            'teachers' => $teachers,
            'teacherId' => $teacherId,
            'slots' => $slots,
        ]);
    }

    private function assertCanMark(User $user, TimetableSlot $slot): void
    {
        abort_unless($user->can('mark-attendance'), 403);
        abort_unless($slot->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $slot->branch_id === $user->branch_id, 403);

        if ($user->hasRole('teacher')) {
            abort_unless($slot->teacher_id === $user->id, 403, 'Only the teacher assigned to this class period can take its attendance. Ask your branch admin to cover it.');
        }
    }

    public function show(Request $request, TimetableSlot $timetableSlot): Response
    {
        $this->assertCanMark($request->user(), $timetableSlot);

        $date = $request->query('date', SchoolCalendar::today()->toDateString());
        abort_unless(Carbon::parse($date)->dayOfWeekIso === $timetableSlot->day_of_week, 404);

        $timetableSlot->load(['section.grade', 'subject', 'period', 'teacher']);

        $enrollments = $timetableSlot->section->enrollments()
            ->active()
            ->with(['student', 'periodAttendanceRecords' => fn ($q) => $q->where('timetable_slot_id', $timetableSlot->id)->whereDate('date', $date)->with('markedBy')])
            ->get()
            ->sortBy(fn ($e) => $e->student->first_name)
            ->values();

        return Inertia::render('Academics/PeriodAttendance/Take', [
            'slot' => [
                'id' => $timetableSlot->id,
                'period_label' => $timetableSlot->period->label(),
                'section' => $timetableSlot->section->auditLabel(),
                'subject' => $timetableSlot->subject->name,
                'teacher' => $timetableSlot->teacher->name,
            ],
            'date' => $date,
            'today' => SchoolCalendar::today()->toDateString(),
            'last_saved_by' => PeriodAttendanceRecord::where('timetable_slot_id', $timetableSlot->id)
                ->whereDate('date', $date)->with('markedBy')->latest('updated_at')->first()?->markedBy?->name,
            'roster' => $enrollments->map(fn ($e) => [
                'enrollment_id' => $e->id,
                'student_name' => $e->student->fullName(),
                'student_id_number' => $e->student->student_id_number,
                'status' => $e->periodAttendanceRecords->first()->status ?? null,
                'marked_by' => $e->periodAttendanceRecords->first()?->markedBy?->name,
            ]),
        ]);
    }

    public function store(Request $request, TimetableSlot $timetableSlot): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanMark($user, $timetableSlot);

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'records' => ['required', 'array'],
            'records.*.enrollment_id' => ['required', 'integer'],
            'records.*.status' => ['required', 'in:present,absent,late,excused'],
        ]);

        abort_unless(Carbon::parse($validated['date'])->dayOfWeekIso === $timetableSlot->day_of_week, 404);

        $validEnrollmentIds = $timetableSlot->section->enrollments()->active()->pluck('id');

        foreach ($validated['records'] as $record) {
            abort_unless($validEnrollmentIds->contains($record['enrollment_id']), 422);

            $existing = PeriodAttendanceRecord::where('enrollment_id', $record['enrollment_id'])
                ->where('timetable_slot_id', $timetableSlot->id)
                ->whereDate('date', $validated['date'])
                ->first();

            if ($existing) {
                $existing->update(['status' => $record['status'], 'marked_by' => $user->id]);
            } else {
                PeriodAttendanceRecord::create([
                    'timetable_slot_id' => $timetableSlot->id,
                    'enrollment_id' => $record['enrollment_id'],
                    'date' => $validated['date'],
                    'status' => $record['status'],
                    'marked_by' => $user->id,
                ]);
            }
        }

        return back()->with('success', 'Attendance saved.');
    }
}
