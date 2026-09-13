<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GradeController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->can('manage-classes') || abort(403);

        return Inertia::render('Grades/Index', [
            'grades' => $request->user()->school->grades()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-classes') || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $request->user()->school->grades()->create($validated);

        return back()->with('success', 'Grade created.');
    }

    public function update(Request $request, Grade $grade): RedirectResponse
    {
        $request->user()->can('manage-classes') || abort(403);
        $grade->school_id === $request->user()->school_id || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $grade->update($validated);

        return back()->with('success', 'Grade updated.');
    }

    public function destroy(Request $request, Grade $grade): RedirectResponse
    {
        $request->user()->can('manage-classes') || abort(403);
        $grade->school_id === $request->user()->school_id || abort(403);

        $grade->delete();

        return back()->with('success', 'Grade removed.');
    }
}
