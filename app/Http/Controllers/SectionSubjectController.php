<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Section;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Which subjects a SECTION teaches (1A and 1B are not the same curriculum) -- separate from the subject
 * catalog itself (SubjectController, which just names every subject the school could ever use) and from
 * Section::requiredSubjects() (what counts toward THIS class's ranking). See Section::subjects() for the
 * "empty means not configured yet, stay permissive" fallback the Teaching Assignments page relies on.
 */
class SectionSubjectController extends Controller
{
    public function update(Request $request, Section $section): RedirectResponse
    {
        $user = $request->user();
        abort_unless($section->canManageSubjectsBy($user), 403);
        $this->assertInScope($user, $section);

        $data = $request->validate([
            'subject_ids' => ['present', 'array'],
            'subject_ids.*' => ['integer', 'exists:subjects,id'],
            // Picking a class's subjects one section at a time is tedious when a school's sections mostly
            // share one curriculum -- this lets the same list be applied to every section of the grade in
            // one go, while still leaving each section free to be set separately afterward.
            'apply_to_grade' => ['nullable', 'boolean'],
        ]);

        // Every id must be THIS school's own subject -- exists:subjects,id alone would let a stray id
        // from another school's catalog through.
        $validIds = $user->school->subjects()->whereIn('id', $data['subject_ids'])->pluck('id');
        abort_unless($validIds->count() === count($data['subject_ids']), 422, 'One of those subjects is not in your school.');

        $targets = Section::where('grade_id', $section->grade_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when(! ($data['apply_to_grade'] ?? false), fn ($q) => $q->whereKey($section->id))
            ->get();

        $syncValues = $validIds->mapWithKeys(fn ($id) => [$id => ['set_by' => $user->id]])->all();
        $before = $section->subjects()->pluck('subjects.name')->all();

        foreach ($targets as $target) {
            $target->subjects()->sync($syncValues);
        }

        $after = $section->subjects()->pluck('subjects.name')->all();
        AuditLog::record($user, 'section.subjects_set', $section, ['subjects' => $before], [
            'subjects' => $after,
            'applied_to_sections' => $targets->pluck('id')->all(),
        ]);

        $message = $targets->count() > 1 ? "Subjects updated for {$targets->count()} sections of {$section->grade->name}." : 'Subjects updated.';

        return back()->with('success', $message);
    }

    private function assertInScope($user, Section $section): void
    {
        abort_unless($section->branch->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $user->branch_id === $section->branch_id, 403);
    }
}
