<?php

namespace App\Http\Controllers;

use App\Exports\SimpleArrayExport;
use App\Models\AttendanceRecord;
use App\Models\Section;
use App\Notifications\AttendanceCorrected;
use App\Notifications\StudentMarkedAbsent;
use App\Support\SchoolCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class AttendanceController extends Controller
{
    /**
     * ATTENDANCE IS A DAILY HOMEROOM RESPONSIBILITY (one record per student
     * per day -- attendance_records has no subject or lesson on it). So:
     *  - a Teacher marks only the section(s) they are CLASS TEACHER of;
     *    being assigned to teach a subject there does not grant it, because
     *    a subject teacher's mark would overwrite the homeroom teacher's for
     *    the same day;
     *  - anyone else holding mark-attendance (Owner, Branch Admin) can mark
     *    any section in their scope, which is how an absent class teacher is
     *    covered. Every record stores who marked it, and that is shown.
     * Lesson-by-lesson attendance would need a lesson/subject on each record
     * and is deliberately not part of this design.
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

    public function index(Request $request): Response|RedirectResponse
    {
        $request->user()->can('mark-attendance') || abort(403);

        $today = SchoolCalendar::today()->toDateString();

        // A teacher's classes are the ones they are class teacher of. With exactly one, there is nothing to choose.
        if ($request->user()->hasRole('teacher')) {
            $mine = $this->sectionsInScope($request)->get();
            if ($mine->count() === 1 && $mine->first()->enrollments()->active()->exists()) {
                return redirect()->route('attendance.show', ['section' => $mine->first()->id, 'date' => $today]);
            }
        }

        $sections = $this->sectionsInScope($request)->orderBy('grade_id')->get()->map(function (Section $s) use ($today) {
            $rosterCount = $s->enrollments()->active()->count();
            $markedCount = AttendanceRecord::whereDate('date', $today)
                ->whereHas('enrollment', fn ($q) => $q->where('section_id', $s->id))
                ->count();

            $last = AttendanceRecord::whereDate('date', $today)
                ->whereHas('enrollment', fn ($q) => $q->where('section_id', $s->id))
                ->with('markedBy')->latest('updated_at')->first();

            return [
                'id' => $s->id,
                'label' => "{$s->grade->name} {$s->name}",
                'last_marked_by' => $last?->markedBy?->name,
                'last_marked_at' => $last?->updated_at?->diffForHumans(),
                'roster_count' => $rosterCount,
                'marked_count' => $markedCount,
                'attendance_status' => $rosterCount === 0
                    ? 'empty'
                    : ($markedCount === 0 ? 'none' : ($markedCount < $rosterCount ? 'partial' : 'complete')),
            ];
        });

        // A teacher who only TEACHES a class (no homeroom) gets a plain explanation, not a dead end.
        $taughtOnly = collect();
        if ($request->user()->hasRole('teacher')) {
            $taughtOnly = $request->user()->teachingAssignments()->with('section.grade')->get()
                ->map(fn ($a) => $a->section)
                ->filter(fn ($sec) => $sec && $sec->class_teacher_id !== $request->user()->id)
                ->unique('id')
                ->map(fn ($sec) => "{$sec->grade->name} {$sec->name}")
                ->values();
        }

        return Inertia::render('Attendance/Sections', ['sections' => $sections, 'date' => $today, 'taughtOnly' => $taughtOnly]);
    }

    public function show(Request $request, Section $section): Response
    {
        $request->user()->can('mark-attendance') || abort(403);
        $this->assertCanMark($request->user(), $section);

        $date = $request->query('date', SchoolCalendar::today()->toDateString());

        $enrollments = $section->enrollments()
            ->active()
            ->with(['student.guardians', 'attendanceRecords' => fn ($q) => $q->whereDate('date', $date)->with('markedBy')])
            ->get()
            ->sortBy(fn ($e) => $e->student->first_name)
            ->values();

        // how many classes this person could mark: a teacher with just one never sees a list of classes at all
        $classCount = $this->sectionsInScope($request)->count();

        return Inertia::render('Attendance/Take', [
            'section' => ['id' => $section->id, 'label' => "{$section->grade->name} {$section->name}", 'grade' => $section->grade->name, 'name' => $section->name],
            'classCount' => $classCount,
            'singleClass' => $request->user()->hasRole('teacher') && $classCount === 1,
            'date' => $date,
            'today' => SchoolCalendar::today()->toDateString(),
            'last_saved_by' => AttendanceRecord::whereDate('date', $date)
                ->whereHas('enrollment', fn ($q) => $q->where('section_id', $section->id))
                ->with('markedBy')->latest('updated_at')->first()?->markedBy?->name,
            'roster' => $enrollments->map(fn ($e) => [
                'enrollment_id' => $e->id,
                'student_name' => $e->student->fullName(),
                'student_id_number' => $e->student->student_id_number,
                'status' => $e->attendanceRecords->first()->status ?? null,
                // who saved this mark, and how many linked parents an absence (or its correction) would notify -- a count only
                'marked_by' => $e->attendanceRecords->first()?->markedBy?->name,
                'parent_count' => $e->student->guardians->count(),
            ]),
        ]);
    }

    /**
     * Exports a section's roster for a date -- if attendance hasn't
     * been taken yet, the Status column is blank, so this doubles as a
     * printable paper roster for a teacher without a device in class.
     * If attendance HAS been recorded, it exports the actual result.
     */
    public function exportRoster(Request $request, Section $section)
    {
        $user = $request->user();
        $canMark = $user->can('mark-attendance');
        $canView = $user->can('view-attendance-reports');
        abort_unless($canMark || $canView, 403);

        if ($canMark) {
            $this->assertCanMark($user, $section);
        } else {
            $branch = $user->school->branches()->whereKey($section->branch_id)->first();
            abort_unless($branch, 403);
            if (! $user->isOrgWide()) {
                abort_unless($user->branch_id === $section->branch_id, 403);
            }
        }

        $date = $request->query('date', SchoolCalendar::today()->toDateString());
        $section->load('grade');

        $enrollments = $section->enrollments()
            ->active()
            ->with(['student', 'attendanceRecords' => fn ($q) => $q->whereDate('date', $date)])
            ->get()
            ->sortBy(fn ($e) => $e->student->first_name)
            ->values();

        $rows = [
            [$user->school->name],
            ["Attendance Roster — {$section->grade->name} {$section->name} — {$date}"],
            ['Student ID', 'Name', 'Status'],
        ];

        foreach ($enrollments as $e) {
            $rows[] = [
                $e->student->student_id_number,
                $e->student->fullName(),
                $e->attendanceRecords->first()->status ?? '',
            ];
        }

        return Excel::download(
            new SimpleArrayExport($rows, 'Attendance'),
            "attendance-{$section->grade->name}-{$section->name}-{$date}.xlsx"
        );
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

        $validEnrollmentIds = $section->enrollments()->active()->pluck('id');

        // §26 hardening: this loop used to run with no transaction at all -- a request with 30 students
        // where, say, the 15th enrollment_id turned out invalid (abort_unless) would leave the first 14
        // saved (and their guardians already notified) while the rest silently never got marked, with no
        // indication anything was incomplete. All notifications in this app are database-channel only, so
        // wrapping notify() calls in the same transaction is safe: a rollback removes the notification row
        // right along with everything else, never "sent" something the DB doesn't actually reflect.
        DB::transaction(function () use ($validated, $validEnrollmentIds, $request) {
            foreach ($validated['records'] as $record) {
                abort_unless($validEnrollmentIds->contains($record['enrollment_id']), 422);

                $existing = AttendanceRecord::where('enrollment_id', $record['enrollment_id'])
                    ->whereDate('date', $validated['date'])
                    ->first();

                $wasAbsent = $existing && $existing->status === 'absent';
                $previous = $existing?->status;

                if ($existing) {
                    $existing->update(['status' => $record['status'], 'marked_by' => $request->user()->id]);
                    $attendance = $existing;
                } else {
                    $attendance = AttendanceRecord::create(['enrollment_id' => $record['enrollment_id'], 'date' => $validated['date'], 'status' => $record['status'], 'marked_by' => $request->user()->id]);
                }

                // An absence the parent was already told about has been changed: tell them it was corrected.
                if ($wasAbsent && $record['status'] !== 'absent') {
                    $enrollment = $attendance->enrollment()->with('student.guardians')->first();
                    foreach ($enrollment->student->guardians as $guardian) {
                        $guardian->notify(new AttendanceCorrected($enrollment->student, Carbon::parse($validated['date']), $previous, $record['status']));
                    }
                }

                if ($record['status'] === 'absent' && ! $wasAbsent) {
                    $enrollment = $attendance->enrollment()->with('student.guardians')->first();
                    foreach ($enrollment->student->guardians as $guardian) {
                        $guardian->notify(new StudentMarkedAbsent($enrollment->student, Carbon::parse($validated['date'])));
                    }
                }
            }
        });

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
            abort_unless($section->class_teacher_id === $user->id, 403, 'Attendance is taken once a day by the class (homeroom) teacher. Ask your branch admin to cover this class or to make you its class teacher.');
        }
    }
}
