<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Combined Grades + Sections view. Grades and Sections used to be two
 * separate pages, but a Section only ever makes sense in the context of
 * its Grade -- testers correctly flagged that as a needless split.
 *
 * The actual create/update/destroy actions still go through
 * GradeController and SectionController unchanged; this controller only
 * assembles the combined read view.
 */
class AcademicStructureController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->canViewOrManage('manage-classes') || abort(403);
        $user = $request->user();
        $school = $user->school;

        $sectionsQuery = \App\Models\Section::query()
            ->whereIn('branch_id', $school->branches()->pluck('id'))
            ->with(['branch', 'academicYear']);

        if (! $user->isOrgWide()) {
            $sectionsQuery->where('branch_id', $user->branch_id);
        }

        $sectionsByGrade = $sectionsQuery->orderBy('name')->get()->groupBy('grade_id');

        $grades = $school->grades()->orderBy('sort_order')->get()->map(fn (Grade $grade) => [
            'id' => $grade->id,
            'name' => $grade->name,
            'sort_order' => $grade->sort_order,
            'sections' => ($sectionsByGrade->get($grade->id) ?? collect())->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'capacity' => $s->capacity,
                'branch' => $s->branch->name,
                'academic_year' => $s->academicYear->name,
            ])->values(),
        ]);

        return Inertia::render('AcademicStructure/Index', [
            'grades' => $grades,
            'branches' => $user->isOrgWide()
                ? $school->branches()->orderBy('name')->get(['id', 'name'])
                : $school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
            'academicYears' => $school->academicYears()->orderByDesc('start_date')->get(['id', 'name', 'is_current']),
            'canManage' => $user->can('manage-classes'),
        ]);
    }
}
