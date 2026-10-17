<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AttendanceRecord;
use App\Models\Mark;
use App\Models\Student;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Support\SchoolCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The parent's "My School" pages. Everything is reached through the guardian link:
 * a child that is not the signed-in parent's own is a plain 404 (StudentPolicy).
 */
class FamilyController extends Controller
{
    public const STATUSES = ['present', 'late', 'absent', 'excused', 'sick'];

    /** Attendance history for one child: every recorded day, totals per status, and the attendance percentage. */
    public function attendance(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasRole('parent'), 403);

        $children = $user->children()->orderBy('first_name')->get();

        $student = $request->filled('student')
            ? $children->firstWhere('id', (int) $request->query('student'))
            : $children->first();

        // asking for a child who is not theirs is indistinguishable from one that does not exist
        if ($request->filled('student') && ! $student) {
            Gate::forUser($user)->authorize('viewRecords', Student::findOrFail((int) $request->query('student')));
            abort(404);
        }

        $payload = ['student' => null, 'months' => [], 'records' => [], 'totals' => null, 'rate' => null, 'today' => null];

        if ($student) {
            Gate::forUser($user)->authorize('viewRecords', $student);

            $base = AttendanceRecord::whereIn('enrollment_id', $student->enrollments()->pluck('enrollments.id'));

            // months that have any records, newest first, for the month filter
            $months = (clone $base)->orderByDesc('date')->pluck('date')
                ->map(fn ($d) => $d->format('Y-m'))->unique()->values();

            $period = $request->query('month');
            $query = clone $base;
            if ($period && preg_match('/^\d{4}-\d{2}$/', $period)) {
                $query->whereBetween('date', ["{$period}-01", date('Y-m-t', strtotime("{$period}-01"))]);
            } else {
                $period = null;
            }

            $records = $query->orderByDesc('date')->get();

            $totals = array_fill_keys(self::STATUSES, 0);
            foreach ($records as $r) {
                $totals[$r->status] = ($totals[$r->status] ?? 0) + 1;
            }
            $marked = $records->count();

            $todayString = SchoolCalendar::today()->toDateString();

            $payload = [
                'student' => ['id' => $student->id, 'name' => $student->fullName()],
                // today's mark, whichever month is being looked at (null status = not recorded yet)
                'today' => ['date' => $todayString, 'status' => (clone $base)->whereDate('date', $todayString)->value('status')],
                'months' => $months,
                'period' => $period,
                'records' => $records->map(fn ($r) => [
                    'id' => $r->id,
                    'date' => $r->date->toDateString(),
                    'status' => $r->status,
                    'remarks' => $r->remarks,
                ])->values(),
                'totals' => $totals + ['marked' => $marked],
                // same formula as the school's attendance report: present + late, out of all recorded days
                'rate' => $marked > 0 ? round(($totals['present'] + $totals['late']) / $marked * 100, 1) : null,
            ];
        }

        return Inertia::render('Family/Attendance', $payload + [
            'children' => $children->map(fn ($c) => ['id' => $c->id, 'name' => $c->fullName(), 'first_name' => $c->first_name])->values(),
        ]);
    }

    /** A child's published homework for their current class -- never a draft, which isn't visible
     * yet. "Parents/students should only see assignments through authorized portal functionality"
     * (the brief's own words) -- this IS that portal functionality. */
    public function homework(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasRole('parent'), 403);

        $children = $user->children()->orderBy('first_name')->get();

        $student = $request->filled('student')
            ? $children->firstWhere('id', (int) $request->query('student'))
            : $children->first();

        if ($request->filled('student') && ! $student) {
            Gate::forUser($user)->authorize('viewRecords', Student::findOrFail((int) $request->query('student')));
            abort(404);
        }

        $payload = ['student' => null, 'section' => null, 'assignments' => []];

        if ($student && $student->currentEnrollment) {
            Gate::forUser($user)->authorize('viewRecords', $student);

            $section = $student->currentEnrollment->section;

            $payload = [
                'student' => ['id' => $student->id, 'name' => $student->fullName()],
                'section' => $section->auditLabel(),
                'assignments' => Assignment::where('section_id', $section->id)->published()
                    ->with(['subject', 'teacher'])
                    ->orderByDesc('due_date')
                    ->get()
                    ->map(fn (Assignment $a) => [
                        'id' => $a->id,
                        'subject_name' => $a->subject->name,
                        'teacher_name' => $a->teacher->name,
                        'title' => $a->title,
                        'description' => $a->description,
                        'due_date' => $a->due_date->toDateString(),
                        'has_attachment' => $a->attachment_path !== null,
                    ]),
            ];
        }

        return Inertia::render('Family/Homework', $payload + [
            'children' => $children->map(fn ($c) => ['id' => $c->id, 'name' => $c->fullName(), 'first_name' => $c->first_name])->values(),
        ]);
    }

    /** A child's current weekly class schedule -- academic expansion, Phase 9. Deferred from Phase 1
     * on purpose (see `TimetableChanged`'s own comment): the parent-facing view mirrors the EXISTING
     * teacher self-service `TimetableController::mine()` exactly, just scoped to the child's current
     * section instead of a teacher's own slots. */
    public function timetable(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasRole('parent'), 403);

        $children = $user->children()->orderBy('first_name')->get();

        $student = $request->filled('student')
            ? $children->firstWhere('id', (int) $request->query('student'))
            : $children->first();

        if ($request->filled('student') && ! $student) {
            Gate::forUser($user)->authorize('viewRecords', Student::findOrFail((int) $request->query('student')));
            abort(404);
        }

        $payload = ['student' => null, 'termName' => null, 'slots' => []];

        if ($student && $student->currentEnrollment) {
            Gate::forUser($user)->authorize('viewRecords', $student);

            $section = $student->currentEnrollment->section;
            $term = Term::whereHas('academicYear', fn ($q) => $q->where('school_id', $user->school_id)->where('is_current', true))
                ->orderBy('start_date')->first();

            $slots = $term ? TimetableSlot::where('section_id', $section->id)->where('term_id', $term->id)
                ->with(['subject', 'room', 'period', 'teacher'])->get()->map(fn (TimetableSlot $s) => [
                    'id' => $s->id,
                    'subject' => $s->subject->name,
                    'teacher' => $s->teacher->name,
                    'room' => $s->room?->name,
                    'period_label' => $s->period->label(),
                    'day_of_week' => $s->day_of_week,
                ]) : collect();

            $payload = [
                'student' => ['id' => $student->id, 'name' => $student->fullName()],
                'termName' => $term?->name,
                'slots' => $slots,
            ];
        }

        return Inertia::render('Family/Timetable', $payload + [
            'children' => $children->map(fn ($c) => ['id' => $c->id, 'name' => $c->fullName(), 'first_name' => $c->first_name])->values(),
        ]);
    }

    /** Every published exam score for a child, across every term and year they have ever been
     * enrolled -- never an unpublished/draft mark, same visibility rule as the report card itself.
     * Deliberately built straight from `Mark`/`MarkBatch` rather than `ReportCardSnapshot` (which only
     * exists for a term a PDF happened to be printed for) so the history is always complete. */
    public function results(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasRole('parent'), 403);

        $children = $user->children()->orderBy('first_name')->get();

        $student = $request->filled('student')
            ? $children->firstWhere('id', (int) $request->query('student'))
            : $children->first();

        if ($request->filled('student') && ! $student) {
            Gate::forUser($user)->authorize('viewRecords', Student::findOrFail((int) $request->query('student')));
            abort(404);
        }

        $payload = ['student' => null, 'terms' => []];

        if ($student) {
            Gate::forUser($user)->authorize('viewRecords', $student);

            $enrollmentIds = $student->enrollments()->pluck('id');

            $marks = Mark::whereIn('enrollment_id', $enrollmentIds)
                ->whereHas('batch', fn ($q) => $q->where('status', 'published'))
                ->with(['batch.exam.term.academicYear', 'batch.subject'])
                ->get();

            $terms = $marks->groupBy(fn (Mark $m) => $m->batch->exam->term_id)
                ->map(function ($group) {
                    $term = $group->first()->batch->exam->term;

                    return [
                        'sort' => $term->start_date->toDateString(),
                        'term_name' => $term->name,
                        'year_name' => $term->academicYear->name,
                        'exams' => $group->map(fn (Mark $m) => [
                            'subject' => $m->batch->subject->name,
                            'exam_name' => $m->batch->exam->name,
                            'score' => $m->score,
                            'max_score' => $m->max_score,
                            'percentage' => $m->percentage(),
                        ])->sortBy('subject')->values(),
                    ];
                })
                ->sortByDesc('sort')
                ->values()
                ->map(fn ($t) => collect($t)->except('sort')->all());

            $payload = [
                'student' => ['id' => $student->id, 'name' => $student->fullName()],
                'terms' => $terms,
            ];
        }

        return Inertia::render('Family/Results', $payload + [
            'children' => $children->map(fn ($c) => ['id' => $c->id, 'name' => $c->fullName(), 'first_name' => $c->first_name])->values(),
        ]);
    }

    /** A hub linking each of the parent's children to their report card. */
    public function reportCards(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasRole('parent'), 403);

        return Inertia::render('Family/ReportCards', [
            'children' => $user->children()->orderBy('first_name')->get()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->fullName(),
                'section' => $c->currentEnrollment
                    ? "{$c->currentEnrollment->section->grade->name} {$c->currentEnrollment->section->name}"
                    : null,
            ]),
        ]);
    }
}
