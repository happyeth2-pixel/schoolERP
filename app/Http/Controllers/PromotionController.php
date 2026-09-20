<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Section;
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
            ->with(['grade', 'branch', 'academicYear']);

        if (! $user->isOrgWide()) {
            $sectionsQuery->where('branch_id', $user->branch_id);
        }

        $sections = $sectionsQuery->get()
            ->sortByDesc(fn (Section $s) => $s->academicYear->start_date)
            ->values()
            ->map(fn (Section $s) => [
                'id' => $s->id,
                'label' => "{$s->grade->name} {$s->name} — {$s->branch->name} — {$s->academicYear->name}",
            ]);

        $fromSectionId = $request->query('from');
        $students = [];

        if ($fromSectionId) {
            $fromSection = Section::whereKey($fromSectionId)->firstOrFail();
            $this->assertSectionInScope($user, $fromSection);

            $students = $fromSection->enrollments()
                ->where('status', 'active')
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-students') || abort(403);

        $validated = $request->validate([
            'from_section_id' => ['required', 'integer'],
            'to_section_id' => ['nullable', 'integer'],
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

        $promoted = 0;
        $graduated = 0;
        $skipped = 0;

        DB::transaction(function () use ($validated, $fromSection, $toSection, &$promoted, &$graduated, &$skipped) {
            foreach ($validated['promotions'] as $row) {
                $enrollment = Enrollment::where('section_id', $fromSection->id)
                    ->whereKey($row['enrollment_id'])
                    ->where('status', 'active')
                    ->first();

                if (! $enrollment) {
                    $skipped++;

                    continue;
                }

                $enrollment->update(['status' => 'completed']);

                if ($row['graduate']) {
                    $enrollment->student->update(['status' => 'graduated']);
                    $graduated++;

                    continue;
                }

                $alreadyThere = Enrollment::where('student_id', $enrollment->student_id)
                    ->where('section_id', $toSection->id)
                    ->exists();

                if ($alreadyThere) {
                    $skipped++;

                    continue;
                }

                Enrollment::create([
                    'student_id' => $enrollment->student_id,
                    'section_id' => $toSection->id,
                    'enrolled_on' => now()->toDateString(),
                    'status' => 'active',
                ]);
                $promoted++;
            }
        });

        return redirect()->route('promotions.index')->with('success', "{$promoted} promoted, {$graduated} graduated, {$skipped} skipped (already inactive or already in the destination section).");
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
