<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Term;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class TermController extends Controller
{
    public function store(Request $request, AcademicYear $academicYear): RedirectResponse
    {
        $request->user()->can('manage-school-settings') || abort(403);
        $academicYear->school_id === $request->user()->school_id || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        $this->assertDatesAllowed($academicYear, $validated['start_date'], $validated['end_date']);

        $academicYear->terms()->create($validated);

        return back()->with('success', 'Term added.');
    }

    public function update(Request $request, Term $term): RedirectResponse
    {
        $request->user()->can('manage-school-settings') || abort(403);
        $term->academicYear->school_id === $request->user()->school_id || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        $this->assertDatesAllowed($term->academicYear, $validated['start_date'], $validated['end_date'], $term);

        $term->update($validated);

        return back()->with('success', 'Term updated.');
    }

    public function destroy(Request $request, Term $term): RedirectResponse
    {
        $request->user()->can('manage-school-settings') || abort(403);
        $term->academicYear->school_id === $request->user()->school_id || abort(403);

        $term->delete();

        return back()->with('success', 'Term removed.');
    }

    /**
     * A term must sit inside its academic year, and (unless the school has chosen to allow it) not
     * overlap another term of that year. Start and end days are inclusive, so a term that starts the
     * day another ends overlaps it.
     */
    private function assertDatesAllowed(AcademicYear $year, string $start, string $end, ?Term $ignore = null): void
    {
        $startDay = Carbon::parse($start)->toDateString();
        $endDay = Carbon::parse($end)->toDateString();
        $errors = [];

        if ($startDay < $year->start_date->toDateString()) {
            $errors['start_date'] = "A term cannot start before its academic year, which begins {$year->start_date->toDateString()}.";
        }
        if ($endDay > $year->end_date->toDateString()) {
            $errors['end_date'] = "A term cannot end after its academic year, which ends {$year->end_date->toDateString()}.";
        }
        if ($startDay >= $endDay) {
            $errors['end_date'] = 'A term must end after it starts.';
        }

        if (! $errors && ! $year->school->allow_overlapping_terms) {
            $clash = $year->terms()
                ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
                ->whereDate('start_date', '<=', $endDay)
                ->whereDate('end_date', '>=', $startDay)
                ->first();

            if ($clash) {
                $errors['start_date'] = "These dates overlap {$clash->name} ({$clash->start_date->toDateString()} to {$clash->end_date->toDateString()}). Terms of one year cannot overlap.";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
