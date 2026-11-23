<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public, no-login report-card lookup -- what a class's printed code/QR sheet (see
 * StudentController::classCodes()) points to, and what the public homepage's "Enter your code" box
 * submits to. Deliberately outside the auth middleware group, same as IdCardController::verify(), and
 * built the same way: a two-layer rate limit (a generous route-level `throttle:report-card-lookup`, plus a
 * tight per-IP miss counter here that only unknown/wrong codes count against), and the fewest possible
 * states disclosed to an outsider. Reuses ReportCardController::computeReportCard() so the public page can
 * never show a different number than the parent/staff one does.
 */
class ReportCardLookupController extends Controller
{
    public function form(): Response
    {
        return Inertia::render('ReportCard/Lookup');
    }

    public function show(Request $request, string $code): Response
    {
        $missKey = 'report-card-lookup-miss:'.$request->ip();

        if (RateLimiter::tooManyAttempts($missKey, 6)) {
            abort(429, 'Too many attempts. Please wait a minute and try again.');
        }

        $token = Student::normalizeReportCardToken($code);
        $student = Student::where('report_card_token', $token)->with('school:id,name')->first();

        if (! $student) {
            RateLimiter::hit($missKey, 60);

            return Inertia::render('ReportCard/Lookup', ['state' => 'invalid']);
        }

        // Only an active (or recently completed) enrollment this academic year has anything to show --
        // same reasoning ReportCardController::computeReportCard() already uses for the authenticated view.
        $hasCurrentEnrollment = Enrollment::where('student_id', $student->id)
            ->whereIn('status', ['active', 'completed'])
            ->whereHas('section.academicYear', fn ($q) => $q->where('is_current', true))
            ->exists();

        if (! $hasCurrentEnrollment) {
            return Inertia::render('ReportCard/Lookup', ['state' => 'unavailable', 'school' => $student->school->name]);
        }

        $data = app(ReportCardController::class)->computeReportCard($student);

        return Inertia::render('ReportCard/Lookup', [
            'state' => 'found',
            'school' => $student->school->name,
            'report' => $data,
        ]);
    }
}
