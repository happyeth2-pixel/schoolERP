<?php

namespace App\Http\Controllers;

use App\Exports\SimpleArrayExport;
use App\Models\AuditLog;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Services\ReportCardCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The homeroom teacher's and the principal's shared view of one class's ranking: who's complete, who
 * isn't and why, and (once everyone required is filled in and published) the full ranked list -- the
 * same ReportCardCalculator rules every individual report card uses, just all of them on one screen
 * instead of discovered one student at a time. A teacher sees only their own homeroom section(s); anyone
 * holding `approve-results`/`publish-results`, the owner, or an auditor sees every section in scope.
 */
class ClassRankingController extends Controller
{
    public function show(Request $request, Section $section): Response
    {
        $user = $request->user();
        $this->assertCanView($user, $section);

        $policy = $user->school->missing_mark_policy ?? 'exclude';
        $calc = new ReportCardCalculator();
        $roster = $calc->classRoster($section, $policy);

        $requiredIds = $section->requiredSubjects()->pluck('subjects.id');
        // Only subjects actually taught in THIS section, plus anything already picked (so a subject whose
        // teaching assignment was later removed doesn't just vanish from the list and silently drop out of
        // "required" without anyone noticing) -- never every subject the whole school offers, which would
        // let a required pick include something this class was never going to be graded on at all.
        $taughtIds = TeachingAssignment::where('section_id', $section->id)->pluck('subject_id');
        $pickableIds = $taughtIds->merge($requiredIds)->unique();

        return Inertia::render('Academics/ClassRanking', [
            'section' => [
                'id' => $section->id,
                'name' => "{$section->grade->name} {$section->name}",
                'academic_year' => $section->academicYear->name,
                'class_teacher' => $section->classTeacher?->name,
            ],
            'roster' => $roster,
            'requiredSubjects' => $section->requiredSubjects()->get(['subjects.id', 'subjects.name'])->values(),
            'allSubjects' => Subject::whereIn('id', $pickableIds)->orderBy('name')->get(['id', 'name']),
            'canManageRequiredSubjects' => $section->canManageRequiredSubjectsBy($user),
            'canPublish' => $user->can('publish-results'),
            'canExport' => $user->can('approve-results') || $user->can('publish-results') || $user->hasRole('owner'),
            'classCodesUrl' => route('sections.class-codes', $section),
        ]);
    }

    public function setRequiredSubjects(Request $request, Section $section): RedirectResponse
    {
        $user = $request->user();
        abort_unless($section->canManageRequiredSubjectsBy($user), 403);
        $this->assertBranchInScope($user, $section);

        $data = $request->validate([
            'subject_ids' => ['present', 'array'],
            'subject_ids.*' => ['integer', 'exists:subjects,id'],
        ]);

        $before = $section->requiredSubjects()->pluck('subjects.name')->all();

        $section->requiredSubjects()->sync(
            collect($data['subject_ids'])->mapWithKeys(fn ($id) => [$id => ['set_by' => $user->id]])->all()
        );

        $after = $section->requiredSubjects()->pluck('subjects.name')->all();
        AuditLog::record($user, 'section.required_subjects_set', $section, ['required_subjects' => $before], ['required_subjects' => $after]);

        return back()->with('success', 'Required subjects updated.');
    }

    public function exportExcel(Request $request, Section $section)
    {
        $user = $request->user();
        $this->assertCanView($user, $section);
        abort_unless($user->can('approve-results') || $user->can('publish-results') || $user->hasRole('owner') || $user->hasRole('auditor'), 403);

        $policy = $user->school->missing_mark_policy ?? 'exclude';
        $roster = (new ReportCardCalculator())->classRoster($section, $policy);
        $label = "{$section->grade->name} {$section->name}";

        $rows = [
            [$user->school->name],
            ["Class Ranking — {$label} — {$section->academicYear->name}"],
            ['Rank', 'Student', 'Student ID', 'Average (%)', 'Status', 'Missing / Notes'],
        ];

        foreach ($roster['students'] as $s) {
            $rows[] = [
                $s['rank'] ?? '—',
                $s['name'],
                $s['student_id_number'],
                $s['overall_average'] ?? '—',
                $s['complete'] ? 'Ranked' : 'Not ranked — incomplete',
                implode('; ', $s['problems']),
            ];
        }

        $filename = str_replace(' ', '-', strtolower("{$label}-ranking")).'.xlsx';

        return Excel::download(new SimpleArrayExport($rows, "Ranking — {$label}"), $filename);
    }

    /** A printable sheet of every student's own report-card lookup code + QR, generated (or regenerated,
     *  one at a time, never in bulk silently) the first time this is opened for each student. */
    public function classCodes(Request $request, Section $section): Response
    {
        $user = $request->user();
        abort_unless($section->canManageRequiredSubjectsBy($user) || $user->can('publish-results') || $user->hasRole('owner'), 403);
        $this->assertBranchInScope($user, $section);

        $students = Student::whereHas('enrollments', fn ($q) => $q->where('section_id', $section->id)->whereIn('status', ['active', 'completed']))
            ->orderBy('first_name')->get();

        foreach ($students as $student) {
            if (! $student->report_card_token) {
                $student->update(['report_card_token' => Student::newReportCardToken()]);
            }
        }

        return Inertia::render('Students/ClassCodes', [
            'section' => ['id' => $section->id, 'name' => "{$section->grade->name} {$section->name}", 'academic_year' => $section->academicYear->name],
            'school' => $user->school->name,
            'students' => $students->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->fullName(),
                'student_id_number' => $s->student_id_number,
                'code' => Student::formatReportCardToken($s->report_card_token),
                'lookup_url' => route('report-card.lookup.show', $s->report_card_token),
            ])->values(),
        ]);
    }

    private function assertCanView($user, Section $section): void
    {
        $this->assertBranchInScope($user, $section);

        $allowed = $user->id === $section->class_teacher_id
            || $user->can('approve-results') || $user->can('publish-results')
            || $user->hasRole('owner') || $user->hasRole('auditor');

        abort_unless($allowed, 403);
    }

    private function assertBranchInScope($user, Section $section): void
    {
        $branch = $user->school->branches()->whereKey($section->branch_id)->first();
        abort_unless($branch, 403);

        if (! $user->isOrgWide()) {
            abort_unless($user->branch_id === $section->branch_id, 403);
        }
    }
}
