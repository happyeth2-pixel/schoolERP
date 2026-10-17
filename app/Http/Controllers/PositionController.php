<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** "Job Title" in the UI -- see App\Models\Position's own docblock for why this is a real entity, kept
 * deliberately distinct from a Spatie system role. */
class PositionController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('employee-view') || $user->can('employee-manage') || $user->hasRole('auditor');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        return Inertia::render('Hr/Positions/Index', [
            'positions' => Position::where('school_id', $user->school_id)->with('department')->withCount('employees')
                ->orderBy('title')->get()->map(fn (Position $p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'department' => $p->department?->name,
                    'description' => $p->description,
                    'employees_count' => $p->employees_count,
                ]),
            'departments' => Department::where('school_id', $user->school_id)->orderBy('name')->get(['id', 'name']),
            'canManage' => $user->can('employee-manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('employee-manage'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('school_id', $user->school_id)],
            'description' => ['nullable', 'string'],
        ]);

        Position::create([
            'school_id' => $user->school_id,
            'department_id' => $validated['department_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
        ]);

        return back()->with('success', 'Job title added.');
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('employee-manage'), 403);
        abort_unless($position->school_id === $user->school_id, 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('school_id', $user->school_id)],
            'description' => ['nullable', 'string'],
        ]);

        $position->update($validated);

        return back()->with('success', 'Job title updated.');
    }

    public function destroy(Request $request, Position $position): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('employee-manage'), 403);
        abort_unless($position->school_id === $user->school_id, 403);

        try {
            $position->delete();
        } catch (\App\Exceptions\DeletionBlockedException $e) {
            return back()->withErrors(['title' => $e->getMessage()]);
        }

        return back()->with('success', 'Job title removed.');
    }
}
