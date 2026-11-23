<?php

namespace App\Http\Controllers;

use App\Exports\SimpleArrayExport;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Section;
use App\Models\TopStudentHighlight;
use App\Services\ReportCardCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The school-wide leaderboard: every section's ranked students, in one list, sorted by overall average --
 * answers "who actually got the top results in the whole school", which no single section's own ranking
 * page can (each is scoped to its own class). Principal/owner-level only: gated on `publish-results` or
 * the owner role, same tier ReportCardController's publish step already trusts with every student's
 * results school-wide.
 */
class TopStudentsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('publish-results') || $user->hasRole('owner') || $user->hasRole('auditor'), 403);

        $year = AcademicYear::where('school_id', $user->school_id)->where('is_current', true)->first();
        $rows = $year ? $this->rankedStudents($user, $year) : collect();

        return Inertia::render('Academics/TopStudents', [
            'academicYear' => $year?->name,
            'students' => $rows->values(),
            'bySection' => $rows->groupBy('section')->map(fn ($g) => $g->take(3))->values(),
            'canPost' => $user->can('manage-school-settings'),
            'highlights' => TopStudentHighlight::where('school_id', $user->school_id)
                ->whereNotNull('published_at')->with('student:id,first_name,father_name,grandfather_name', 'section:id,grade_id,name')
                ->orderBy('rank')->get()->map(fn ($h) => [
                    'id' => $h->id,
                    'student_name' => $h->student->fullName(),
                    'section' => $h->section->grade->name.' '.$h->section->name,
                    'rank' => $h->rank,
                    'overall_average' => $h->overall_average,
                ]),
        ]);
    }

    public function exportExcel(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('publish-results') || $user->hasRole('owner') || $user->hasRole('auditor'), 403);

        $year = AcademicYear::where('school_id', $user->school_id)->where('is_current', true)->first();
        $rows = $year ? $this->rankedStudents($user, $year) : collect();

        $table = [
            [$user->school->name],
            ['School-wide Top Results — '.($year->name ?? 'No current academic year')],
            ['School Rank', 'Student', 'Student ID', 'Class', 'Average (%)'],
        ];

        foreach ($rows as $i => $r) {
            $table[] = [$i + 1, $r['name'], $r['student_id_number'], $r['section'], $r['overall_average']];
        }

        return Excel::download(new SimpleArrayExport($table, 'School Top Results'), 'school-top-results.xlsx');
    }

    /** Post one student to the public homepage's "top students" block. */
    public function post(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('manage-school-settings'), 403);

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'rank' => ['required', 'integer', 'min:1', 'max:3'],
            'overall_average' => ['nullable', 'numeric'],
        ]);

        $highlight = TopStudentHighlight::create($data + [
            'school_id' => $user->school_id,
            'posted_by' => $user->id,
            'published_at' => now(),
        ]);

        AuditLog::record($user, 'top_student_highlight.posted', $highlight, [], $data);

        return back()->with('success', 'Posted to the public homepage.');
    }

    /** Take a highlight back down -- the row stays (NeverHardDeleted), only `published_at` clears. */
    public function unpublish(Request $request, TopStudentHighlight $highlight): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('manage-school-settings'), 403);
        abort_unless($highlight->school_id === $user->school_id, 403);

        $highlight->update(['published_at' => null]);
        AuditLog::record($user, 'top_student_highlight.unpublished', $highlight, ['published_at' => 'set'], ['published_at' => null]);

        return back()->with('success', 'Removed from the public homepage.');
    }

    /** Every eligible (ranked) student across every section in scope, sorted best-average-first. */
    private function rankedStudents($user, AcademicYear $year)
    {
        $sections = Section::where('academic_year_id', $year->id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->with('grade')->get();

        $calc = new ReportCardCalculator();
        $policy = $user->school->missing_mark_policy ?? 'exclude';
        $rows = collect();

        foreach ($sections as $section) {
            $roster = $calc->classRoster($section, $policy);
            foreach ($roster['students'] as $s) {
                if (! $s['complete']) {
                    continue;
                }
                $rows->push($s + [
                    'section' => "{$section->grade->name} {$section->name}",
                    'section_id' => $section->id,
                    'academic_year_id' => $year->id,
                ]);
            }
        }

        return $rows->sortByDesc('overall_average')->values();
    }
}
