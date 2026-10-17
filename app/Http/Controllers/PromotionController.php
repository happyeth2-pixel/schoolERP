<?php

namespace App\Http\Controllers;

use App\Services\SectionCapacity;
use App\Services\SectionRecommendation;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Support\SchoolCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PromotionController extends Controller
{
    /**
     * Moves a batch of students from one section to another -- the
     * missing link between academic years. Enrollment is normally only
     * ever created once, at initial registration (StudentController);
     * this is the only place a CONTINUING student gets a new one, so a
     * class can actually advance from "Grade 3 A, 2026/2027" into
     * "Grade 4 A, 2027/2028" without re-registering from scratch.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $user->can('manage-students') || abort(403);

        $sectionsQuery = Section::whereIn('branch_id', $user->school->branches()->pluck('id'))
            ->with(['grade', 'branch', 'academicYear', 'classTeacher']);

        if (! $user->isOrgWide()) {
            $sectionsQuery->where('branch_id', $user->branch_id);
        }

        $sections = SectionRecommendation::annotate($sectionsQuery->get()
            ->sortByDesc(fn (Section $s) => $s->academicYear->start_date)
            ->values()
            ->map(fn (Section $s) => [
                'id' => $s->id,
                'label' => "{$s->grade->name} {$s->name} — {$s->branch->name} — {$s->academicYear->name}",
                'grade' => $s->grade->name,
                'grade_order' => $s->grade->sort_order,
                'name' => $s->name,
                'class_name' => trim("{$s->grade->name} {$s->name}"),
                'branch' => $s->branch->name,
                'branch_id' => $s->branch_id,
                'academic_year' => $s->academicYear->name,
                'academic_year_id' => $s->academic_year_id,
                'is_current_year' => (bool) $s->academicYear->is_current,
                'teacher' => $s->classTeacher?->name,
            ] + SectionCapacity::describe($s)));

        $fromSectionId = $request->query('from');
        $students = [];

        if ($fromSectionId) {
            $fromSection = Section::whereKey($fromSectionId)->firstOrFail();
            $this->assertSectionInScope($user, $fromSection);

            $students = $fromSection->enrollments()
                ->active()
                ->with('student')
                ->get()
                ->sortBy(fn ($e) => $e->student->first_name)
                ->values()
                ->map(fn (Enrollment $e) => [
                    'enrollment_id' => $e->id,
                    'name' => $e->student->fullName(),
                    'student_id_number' => $e->student->student_id_number,
                ]);
        }

        return Inertia::render('Promotions/Index', [
            'sections' => $sections,
            'fromSectionId' => $fromSectionId ? (int) $fromSectionId : null,
            'students' => $students,
            'canOverrideCapacity' => $user->can('override-section-capacity'),
            'branchCount' => $user->school->branches()->count(),
            'result' => session('promotion_result'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-students') || abort(403);

        $validated = $request->validate([
            'from_section_id' => ['required', 'integer'],
            'to_section_id' => ['nullable', 'integer'],
            'override_capacity' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'promotions' => ['required', 'array', 'min:1'],
            'promotions.*.enrollment_id' => ['required', 'integer'],
            'promotions.*.graduate' => ['required', 'boolean'],
        ]);

        $fromSection = Section::whereKey($validated['from_section_id'])->firstOrFail();
        $this->assertSectionInScope($user, $fromSection);

        $toSection = null;
        $hasNonGraduate = collect($validated['promotions'])->contains(fn ($p) => ! $p['graduate']);

        if ($hasNonGraduate) {
            if (empty($validated['to_section_id'])) {
                throw ValidationException::withMessages(['to_section_id' => 'Choose a destination section, or mark every selected student as graduating.']);
            }

            $toSection = Section::whereKey($validated['to_section_id'])->firstOrFail();
            $this->assertSectionInScope($user, $toSection);

            if ($toSection->id === $fromSection->id) {
                throw ValidationException::withMessages(['to_section_id' => 'Destination must be a different section than the source.']);
            }
        }

        $ids = collect($validated['promotions'])->pluck('graduate', 'enrollment_id')->all();   // one row per enrollment, however many times it was sent
        $override = (bool) ($validated['override_capacity'] ?? false);
        $overrideReason = $validated['override_reason'] ?? null;
        $counts = ['promoted' => 0, 'graduated' => 0, 'already_processed' => 0, 'already_in_destination' => 0, 'not_eligible' => 0, 'not_found' => 0, 'no_room' => 0];

        // Everything below happens inside one transaction, and the rows being
        // decided on are LOCKED first. A second submit of the same batch (a
        // double-click, a second tab, a retry) therefore waits for the first to
        // commit, then sees the students as already processed instead of
        // promoting them twice. The (student_id, section_id) unique key is the
        // last backstop.
        $actor = $user;
        $fromLabel = trim($fromSection->grade->name.' '.$fromSection->name).' '.$fromSection->academicYear->name;
        $toLabel = $toSection ? trim($toSection->grade->name.' '.$toSection->name).' '.$toSection->academicYear->name : null;

        AuditLog::suspended(function () use ($ids, $fromSection, $toSection, &$counts, $actor, $fromLabel, $toLabel, $override, $overrideReason) {
            DB::transaction(function () use ($ids, $fromSection, $toSection, &$counts, $actor, $fromLabel, $toLabel, $override, $overrideReason) {
            $enrollments = Enrollment::whereIn('id', array_keys($ids))
                ->where('section_id', $fromSection->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $students = Student::whereIn('id', $enrollments->pluck('student_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $existingDestination = $toSection
                ? Enrollment::where('section_id', $toSection->id)->whereIn('student_id', $enrollments->pluck('student_id'))->lockForUpdate()->get()->keyBy('student_id')
                : collect();

            foreach ($ids as $enrollmentId => $graduate) {
                $enrollment = $enrollments->get($enrollmentId);

                if (! $enrollment) {
                    $counts['not_found']++;

                    continue;
                }

                $student = $students->get($enrollment->student_id);

                // Already carried through by an earlier run (or closed some other way).
                if ($enrollment->status !== 'active') {
                    $counts['already_processed']++;

                    continue;
                }

                if (! in_array($student->status, Student::ENROLLED_STATUSES, true)) {
                    $counts['not_eligible']++;

                    continue;
                }

                if ($graduate) {
                    $student->changeStatus('graduated');   // closes the open enrollment as completed
                    $counts['graduated']++;
                    AuditLog::record($actor, 'student.graduated', $student, ['section' => $fromLabel, 'status' => 'active'], ['status' => 'graduated']);

                    continue;
                }

                $there = $existingDestination->get($student->id);

                if ($there) {
                    // Already placed in the destination: don't create a duplicate. If that placement is live,
                    // just close the source enrollment; if it is a closed one, leave everything for a human.
                    if ($there->status === 'active') {
                        $enrollment->update(['status' => 'completed']);
                        AuditLog::record($actor, 'student.promotion_reconciled', $student, ['section' => $fromLabel], ['section' => $toLabel], 'Already placed in the destination; source enrollment closed');
                        $counts['already_in_destination']++;
                    } else {
                        $counts['not_eligible']++;
                    }

                    continue;
                }

                // one place in the destination for this student, or stop putting people there
                if (SectionCapacity::grant($toSection, 1, $actor, $override, $overrideReason) < 1) {
                    $counts['no_room']++;

                    continue;
                }

                $enrollment->update(['status' => 'completed']);
                Enrollment::create([
                    'student_id' => $student->id,
                    'section_id' => $toSection->id,
                    'enrolled_on' => SchoolCalendar::today()->toDateString(),
                    'status' => 'active',
                ]);
                $counts['promoted']++;
                AuditLog::record($actor, 'student.promoted', $student, ['section' => $fromLabel], ['section' => $toLabel]);
            }
        }, 3);
        });

        AuditLog::record($actor, 'promotion.executed', $fromSection, null, ['to_section' => $toLabel] + $counts);

        return redirect()->route('promotions.index')->with('success', $this->summary($counts))->with('promotion_result', [
            'counts' => $counts,
            'from' => $fromLabel,
            'to' => $toLabel,
            'summary' => $this->summary($counts),
        ]);
    }

    private function summary(array $c): string
    {
        $done = $c['promoted'] + $c['graduated'];
        $parts = [];
        if ($c['promoted']) {
            $parts[] = "{$c['promoted']} promoted";
        }
        if ($c['graduated']) {
            $parts[] = "{$c['graduated']} graduated";
        }
        if ($c['already_processed'] + $c['already_in_destination']) {
            $parts[] = ($c['already_processed'] + $c['already_in_destination']).' already processed (no change made)';
        }
        if ($c['not_eligible']) {
            $parts[] = "{$c['not_eligible']} skipped (not currently enrolled, or the destination placement is not active)";
        }
        if ($c['no_room']) {
            $parts[] = "{$c['no_room']} not promoted because the destination section is full";
        }
        if ($c['not_found']) {
            $parts[] = "{$c['not_found']} not found in this section";
        }

        return ($done === 0 ? 'Nothing to do — ' : '').implode(', ', $parts).'.';
    }

    private function assertSectionInScope($user, Section $section): void
    {
        $branchIds = $user->school->branches()->pluck('id');
        abort_unless($branchIds->contains($section->branch_id), 403);

        if (! $user->isOrgWide()) {
            abort_unless($section->branch_id === $user->branch_id, 403);
        }
    }
}
