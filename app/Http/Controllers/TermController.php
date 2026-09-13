<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Term;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
}
