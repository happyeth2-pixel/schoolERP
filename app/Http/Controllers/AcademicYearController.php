<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
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
                ->withCount('terms')
                ->orderByDesc('start_date')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-school-settings') || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_current' => ['boolean'],
        ]);

        $school = $request->user()->school;

        if ($validated['is_current'] ?? false) {
            $school->academicYears()->update(['is_current' => false]);
        }

        $school->academicYears()->create($validated);

        return back()->with('success', 'Academic year created.');
    }

    public function update(Request $request, AcademicYear $academicYear): RedirectResponse
    {
        $request->user()->can('manage-school-settings') || abort(403);
        $academicYear->school_id === $request->user()->school_id || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_current' => ['boolean'],
        ]);

        if ($validated['is_current'] ?? false) {
            $academicYear->school->academicYears()->where('id', '!=', $academicYear->id)->update(['is_current' => false]);
        }

        $academicYear->update($validated);

        return back()->with('success', 'Academic year updated.');
    }
}
