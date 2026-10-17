<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Invoice;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AcademicYearController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->can('manage-school-settings') || abort(403);

        return Inertia::render('AcademicYears/Index', [
            'academicYears' => $request->user()->school->academicYears()
                ->withCount(['terms', 'sections'])
                ->orderByDesc('start_date')
                ->get()
                ->map(fn (AcademicYear $y) => $y->toArray() + [
                    'invoices_count' => Invoice::where('academic_year_id', $y->id)->count(),
                    'active_enrollments' => Enrollment::active()->whereHas('section', fn ($q) => $q->where('academic_year_id', $y->id))->count(),
                ]),
            'canSwitchYear' => $request->user()->isOrgWide(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-school-settings') || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        $school = $request->user()->school;

        // Creating a year never changes which year is current -- that is a separate, protected
        // action (makeCurrent). The school's very first year simply becomes current.
        $isFirst = ! $school->academicYears()->exists();
        $school->academicYears()->create($validated + ['is_current' => $isFirst]);

        return back()->with('success', $isFirst
            ? 'Academic year created and set as the current year.'
            : 'Academic year created. Use "Make current" when the school is ready to switch to it.');
    }

    public function update(Request $request, AcademicYear $academicYear): RedirectResponse
    {
        $request->user()->can('manage-school-settings') || abort(403);
        $academicYear->school_id === $request->user()->school_id || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        // The year's dates must still contain every one of its terms.
        foreach ($academicYear->terms as $term) {
            if ($term->start_date->toDateString() < $validated['start_date'] || $term->end_date->toDateString() > $validated['end_date']) {
                throw ValidationException::withMessages(['start_date' => "{$term->name} runs {$term->start_date->toDateString()} to {$term->end_date->toDateString()}, outside these dates. Change the term first."]);
            }
        }

        $academicYear->update($validated);

        return back()->with('success', 'Academic year updated.');
    }

    /**
     * Switching the current academic year is protected: only org-level accounts, the year's name
     * must be typed to confirm, and a reason is required and audited. It changes what the
     * dashboards, invoice list, new invoices and enrolment use as "this year" -- nothing already
     * recorded is changed.
     */
    public function makeCurrent(Request $request, AcademicYear $academicYear): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-school-settings') || abort(403);
        $user->isOrgWide() || abort(403, 'Only an owner-level account can change the current academic year.');
        $academicYear->school_id === $user->school_id || abort(403);

        $validated = $request->validate([
            'confirm_name' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($academicYear->is_current) {
            return back()->withErrors(['confirm_name' => "{$academicYear->name} is already the current year."]);
        }

        if (trim($validated['confirm_name']) !== $academicYear->name) {
            return back()->withErrors(['confirm_name' => 'Type the academic year name exactly to confirm.']);
        }

        $academicYear->makeCurrent($user, $validated['reason']);

        return back()->with('success', "{$academicYear->name} is now the current academic year. Nothing already recorded was changed.");
    }
}
