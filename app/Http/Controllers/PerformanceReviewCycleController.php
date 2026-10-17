<?php

namespace App\Http\Controllers;

use App\Models\PerformanceReviewCycle;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** A school's own named review periods -- see PerformanceReviewCycle's own docblock. Managing cycles rides
 * on `performance-manage`, the same way LeaveType management rides on `leave-approve`. */
class PerformanceReviewCycleController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('performance-view') || $user->can('performance-manage') || $user->hasRole('auditor');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        return Inertia::render('Hr/PerformanceCycles/Index', [
            'cycles' => PerformanceReviewCycle::where('school_id', $user->school_id)->withCount('reviews')
                ->orderByDesc('start_date')->get()->map(fn (PerformanceReviewCycle $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'start_date' => $c->start_date->toDateString(),
                    'end_date' => $c->end_date->toDateString(),
                    'notes' => $c->notes,
                    'reviews_count' => $c->reviews_count,
                ]),
            'canManage' => $user->can('performance-manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('performance-manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('performance_review_cycles')->where('school_id', $user->school_id)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        PerformanceReviewCycle::create(['school_id' => $user->school_id, ...$validated]);

        return back()->with('success', 'Review cycle added.');
    }

    public function update(Request $request, PerformanceReviewCycle $cycle): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('performance-manage'), 403);
        abort_unless($cycle->school_id === $user->school_id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('performance_review_cycles')->where('school_id', $user->school_id)->ignore($cycle->id)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $cycle->update($validated);

        return back()->with('success', 'Review cycle updated.');
    }

    public function destroy(Request $request, PerformanceReviewCycle $cycle): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('performance-manage'), 403);
        abort_unless($cycle->school_id === $user->school_id, 403);

        try {
            $cycle->delete();
        } catch (\App\Exceptions\DeletionBlockedException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('success', 'Review cycle removed.');
    }
}
