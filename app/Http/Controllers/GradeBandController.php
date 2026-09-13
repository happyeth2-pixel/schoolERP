<?php

namespace App\Http\Controllers;

use App\Models\GradeBand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GradeBandController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->can('manage-grading-scale') || abort(403);

        return Inertia::render('GradeBands/Index', [
            'bands' => $request->user()->school->gradeBands()->orderByDesc('min_score')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-grading-scale') || abort(403);

        $validated = $request->validate([
            'min_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_score' => ['required', 'numeric', 'min:0', 'max:100', 'gte:min_score'],
            'letter' => ['required', 'string', 'max:10'],
        ]);

        $request->user()->school->gradeBands()->create($validated);

        return back()->with('success', 'Grade band added.');
    }

    public function update(Request $request, GradeBand $gradeBand): RedirectResponse
    {
        $request->user()->can('manage-grading-scale') || abort(403);
        $gradeBand->school_id === $request->user()->school_id || abort(403);

        $validated = $request->validate([
            'min_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_score' => ['required', 'numeric', 'min:0', 'max:100', 'gte:min_score'],
            'letter' => ['required', 'string', 'max:10'],
        ]);

        $gradeBand->update($validated);

        return back()->with('success', 'Grade band updated.');
    }

    public function destroy(Request $request, GradeBand $gradeBand): RedirectResponse
    {
        $request->user()->can('manage-grading-scale') || abort(403);
        $gradeBand->school_id === $request->user()->school_id || abort(403);

        $gradeBand->delete();

        return back()->with('success', 'Grade band removed.');
    }
}
