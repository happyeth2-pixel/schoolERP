<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\GradeBand;
use App\Models\ReportCardSnapshot;
use App\Models\School;
use App\Models\Term;
use App\Models\Student;
use App\Services\ReportCardCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReportCardController extends Controller
{
    public function show(Request $request, Student $student): Response
    {
        Gate::authorize('viewRecords', $student);

        return Inertia::render('ReportCard/Show', $this->computeReportCard($student));
    }

    public function pdf(Request $request, Student $student)
    {
        Gate::authorize('viewRecords', $student);

        $data = $this->computeReportCard($student);

        // The PDF is the issued document: keep a record of exactly what it said.
        if ($data['section'] !== null) {
            ReportCardSnapshot::create([
                'student_id' => $student->id,
                'section_id' => $data['section_id'],
                'overall_average' => $data['overall_average'],
                'rank' => $data['rank'],
                'ranked_count' => $data['class_size'] ?? 0,
                'class_size' => $data['rank_basis']['class_size'] ?? 0,
                'rules_version' => $data['rank_basis']['rules_version'],
                'missing_mark_policy' => $data['missing_mark_policy'],
                'basis' => $data['rank_basis'] + ['not_ranked_reasons' => $data['rank_reasons']],
                'subjects' => $data['subjects'],
                'generated_by' => $request->user()->id,
            ]);
        }

        $pdf = Pdf::loadView('reportcard.pdf', $data + ['school' => $student->school]);

        return $pdf->download("report-card-{$student->student_id_number}.pdf");
    }

    /**
     * Weighted subject averages, overall average, letter grade and
     * class rank. THE RULES (one policy, applied everywhere):
     *
     *  1. Only PUBLISHED mark batches take part. Draft/submitted/approved
     *     results never affect what a parent or student sees, and an exam
     *     with no published batch yet simply isn't counted (term still running).
     *  2. A subject average = sum(percentage x exam weight) / sum(weights)
     *     over the assessments that take part.
     *  3. If a published exam has NO SCORE for the student (absent, not
     *     recorded), the school's `missing_mark_policy` decides:
     *       - exclude (default): it is left out and the weights of the
     *         remaining assessments are re-normalized, so nothing is
     *         inflated by a phantom weight and nothing is zeroed;
     *       - zero: it counts as 0%.
     *  4. A student who was not on the class list when that batch's marks
     *     were saved (e.g. joined the section later) has no mark row at all,
     *     so the exam is "not applicable" to them under BOTH policies —
     *     nobody is ever zeroed for an exam held before they arrived.
     *  5. A subject with nothing that counts shows no average and does not
     *     feed the overall average; the overall average is the plain mean of
     *     the subject averages that exist.
     *  6. Class rank follows the formal rules documented on
     *     ReportCardCalculator (who is in the class, who is eligible, ties).
     *  Each subject reports how many assessments were counted out of how many
     *  were published, so a partial average is visible, never silent.
     */
    private function computeReportCard(Student $student): array
    {
        $enrollment = Enrollment::where('student_id', $student->id)
            ->active()
            ->whereHas('section.academicYear', fn ($q) => $q->where('is_current', true))
            ->with('section.grade', 'section.academicYear')
            ->first();

        if (! $enrollment) {
            return [
                'student' => ['id' => $student->id, 'name' => $student->fullName(), 'student_id_number' => $student->student_id_number],
                'section' => null,
                'subjects' => [],
                'overall_average' => null,
                'overall_letter' => null,
                'rank' => null,
                'class_size' => null,
            ];
        }

        $section = $enrollment->section;
        $policy = $student->school->missing_mark_policy ?? 'exclude';

        // Never calculate from a grading scheme that doesn't total 100%.
        // Publishing is blocked in that case, so this only catches legacy or
        // hand-edited data -- and it says so instead of silently computing.
        $badTerms = Exam::incompleteTermIds($student->school_id);
        $weightWarnings = Term::whereIn('id', $badTerms)
            ->whereHas('exams.markBatches', fn ($q) => $q->where('status', 'published')->where('section_id', $section->id))
            ->get()
            ->map(fn ($t) => "{$t->name}: exam weights total ".Exam::termWeightTotal($t->id).'%, not 100% — its results are not included until this is fixed.')
            ->values()->all();

        $calc = new ReportCardCalculator();

        $subjectAverages = $calc->subjectAverages($calc->publishedMarks([$enrollment->id], $section->id, $badTerms), $policy);
        $overall = $calc->overallOf($subjectAverages);
        $ranking = $calc->ranking($section, $enrollment->id, $policy, $badTerms);

        return [
            'student' => ['id' => $student->id, 'name' => $student->fullName(), 'student_id_number' => $student->student_id_number],
            'section' => "{$section->grade->name} {$section->name}",
            'academic_year' => $section->academicYear->name,
            'subjects' => $subjectAverages,
            'overall_average' => $overall,
            'overall_letter' => $overall !== null ? GradeBand::letterFor($student->school_id, $overall) : null,
            'rank' => $ranking['rank'],
            'class_size' => $ranking['ranked_count'],
            'rank_reasons' => $ranking['not_ranked_reasons'],
            'rank_basis' => $ranking['basis'],
            'section_id' => $section->id,
            'weight_warnings' => $weightWarnings,
            'missing_mark_policy' => $policy,
            'policy_note' => School::MISSING_MARK_POLICIES[$policy],
        ];
    }
}
