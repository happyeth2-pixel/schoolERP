<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\GradeBand;
use App\Models\Student;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportCardController extends Controller
{
    public function show(Request $request, Student $student): Response
    {
        $this->authorizeView($request->user(), $student);

        return Inertia::render('ReportCard/Show', $this->computeReportCard($student));
    }

    public function pdf(Request $request, Student $student)
    {
        $this->authorizeView($request->user(), $student);

        $data = $this->computeReportCard($student);
        $pdf = Pdf::loadView('reportcard.pdf', $data);

        return $pdf->download("report-card-{$student->student_id_number}.pdf");
    }

    private function authorizeView($user, Student $student): void
    {
        abort_unless($student->school_id === $user->school_id, 403);

        if ($user->hasRole('parent')) {
            abort_unless($user->children()->whereKey($student->id)->exists(), 403);

            return;
        }

        if ($user->hasRole('auditor')) {
            return;
        }

        abort_unless($user->can('manage-students'), 403);

        if (! $user->isOrgWide()) {
            abort_unless($student->branch_id === $user->branch_id, 403);
        }
    }

    /**
     * Weighted subject averages, overall average, letter grade and
     * class rank — computed strictly from PUBLISHED mark batches. A
     * batch still in draft/submitted/approved never affects what a
     * parent or student sees, by design.
     */
    private function computeReportCard(Student $student): array
    {
        $enrollment = Enrollment::where('student_id', $student->id)
            ->where('status', 'active')
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
        $sectionmates = Enrollment::where('section_id', $section->id)->where('status', 'active')->pluck('id');

        $subjectAverages = $this->subjectAveragesFor($enrollment->id, $section->id);

        $overall = count($subjectAverages) > 0
            ? round(array_sum(array_column($subjectAverages, 'average')) / count($subjectAverages), 2)
            : null;

        $peerAverages = [];
        foreach ($sectionmates as $enrollmentId) {
            $subs = $this->subjectAveragesFor($enrollmentId, $section->id);
            if (count($subs) > 0) {
                $peerAverages[$enrollmentId] = round(array_sum(array_column($subs, 'average')) / count($subs), 2);
            }
        }

        $rank = null;
        if ($overall !== null && isset($peerAverages[$enrollment->id])) {
            $sorted = collect($peerAverages)->sortDesc()->values();
            $rank = $sorted->search(fn ($v) => $v === $overall) + 1;
        }

        return [
            'student' => ['id' => $student->id, 'name' => $student->fullName(), 'student_id_number' => $student->student_id_number],
            'section' => "{$section->grade->name} {$section->name}",
            'academic_year' => $section->academicYear->name,
            'subjects' => $subjectAverages,
            'overall_average' => $overall,
            'overall_letter' => $overall !== null ? GradeBand::letterFor($student->school_id, $overall) : null,
            'rank' => $rank,
            'class_size' => count($peerAverages),
        ];
    }

    private function subjectAveragesFor(int $enrollmentId, int $sectionId): array
    {
        $marks = \App\Models\Mark::where('enrollment_id', $enrollmentId)
            ->whereNotNull('score')
            ->whereHas('batch', fn ($q) => $q->where('status', 'published')->where('section_id', $sectionId))
            ->with('batch.exam', 'batch.subject')
            ->get()
            ->groupBy(fn ($m) => $m->batch->subject_id);

        $result = [];
        foreach ($marks as $subjectId => $subjectMarks) {
            $weightedSum = 0;
            $weightTotal = 0;
            foreach ($subjectMarks as $mark) {
                $weight = (float) $mark->batch->exam->weight_percent;
                $weightedSum += $mark->percentage() * $weight;
                $weightTotal += $weight;
            }

            if ($weightTotal > 0) {
                $average = round($weightedSum / $weightTotal, 2);
                $result[] = [
                    'subject' => $subjectMarks->first()->batch->subject->name,
                    'average' => $average,
                    'letter' => GradeBand::letterFor($subjectMarks->first()->batch->subject->school_id, $average),
                ];
            }
        }

        return $result;
    }
}
