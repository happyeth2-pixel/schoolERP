<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\Student;
use App\Services\ReportCardCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Award certificates for a section's top 1st/2nd/3rd place students -- generated straight from the exact
 * same ranked list ClassRankingController/ReportCardCalculator already produce, so a certificate can never
 * say something the report cards don't agree with. One PDF, one page per top student (never more than the
 * 3 who are actually eligible for that section).
 */
class CertificateController extends Controller
{
    public function forSection(Request $request, Section $section)
    {
        $user = $request->user();
        abort_unless($user->can('publish-results') || $user->hasRole('owner'), 403);
        $this->assertBranchInScope($user, $section);

        $policy = $user->school->missing_mark_policy ?? 'exclude';
        $roster = (new ReportCardCalculator())->classRoster($section, $policy);
        $top = collect($roster['students'])->filter(fn ($s) => $s['complete'] && $s['rank'] <= 3)->sortBy('rank')->values();

        abort_if($top->isEmpty(), 422, 'No ranked students yet for this class -- nothing to certify.');

        $pdf = Pdf::loadView('certificate.pdf', [
            'school' => $user->school,
            'section' => "{$section->grade->name} {$section->name}",
            'academic_year' => $section->academicYear->name,
            'students' => $top,
            'logo' => $this->crestDataUri($user->school),
        ])->setPaper('a4', 'landscape');

        $filename = 'certificates-'.str_replace(' ', '-', strtolower("{$section->grade->name}-{$section->name}")).'.pdf';

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }

    public function forStudent(Request $request, Student $student)
    {
        $user = $request->user();
        \Illuminate\Support\Facades\Gate::authorize('viewRecords', $student);

        $enrollment = $student->enrollments()->whereIn('status', ['active', 'completed'])
            ->whereHas('section.academicYear', fn ($q) => $q->where('is_current', true))
            ->with('section.grade', 'section.academicYear')->first();
        abort_unless($enrollment, 422, 'This student has no current class.');

        $policy = $student->school->missing_mark_policy ?? 'exclude';
        $ranking = (new ReportCardCalculator())->ranking($enrollment->section, $enrollment->id, $policy);
        abort_unless($ranking['rank'] !== null && $ranking['rank'] <= 3, 422, 'This student is not in the top 3 of their class.');

        $pdf = Pdf::loadView('certificate.pdf', [
            'school' => $student->school,
            'section' => "{$enrollment->section->grade->name} {$enrollment->section->name}",
            'academic_year' => $enrollment->section->academicYear->name,
            'students' => collect([['name' => $student->fullName(), 'rank' => $ranking['rank'], 'overall_average' => $ranking['overall']]]),
            'logo' => $this->crestDataUri($student->school),
        ])->setPaper('a4', 'landscape');

        $filename = 'certificate-'.$student->student_id_number.'.pdf';

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }

    private function crestDataUri($school): ?string
    {
        if ($uri = $school->logoDataUri()) {
            return $uri;
        }

        $path = public_path('images/brand/paradise-logo.jpg');
        if (! is_file($path)) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode(file_get_contents($path));
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
