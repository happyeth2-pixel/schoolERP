<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubjectController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->can('manage-classes') || abort(403);

        return Inertia::render('Subjects/Index', [
            'subjects' => $request->user()->school->subjects()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-classes') || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
        ]);

        $request->user()->school->subjects()->create($validated);

        return back()->with('success', 'Subject created.');
    }

    public function update(Request $request, Subject $subject): RedirectResponse
    {
        $request->user()->can('manage-classes') || abort(403);
        $subject->school_id === $request->user()->school_id || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
        ]);

        $subject->update($validated);

        return back()->with('success', 'Subject updated.');
    }

    public function destroy(Request $request, Subject $subject): RedirectResponse
    {
        $request->user()->can('manage-classes') || abort(403);
        $subject->school_id === $request->user()->school_id || abort(403);

        $subject->delete();

        return back()->with('success', 'Subject removed.');
    }
}
