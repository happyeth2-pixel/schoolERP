<?php

namespace App\Http\Controllers;

use App\Exceptions\DeletionBlockedException;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewCycle;
use App\Models\User;
use App\Notifications\PerformanceReviewFinalized;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance reviews -- HR Module Phase E. Confirmed via AskUserQuestion: an employee can view their OWN
 * finalized review (read-only, like My Leave), but never authors one themselves -- there is no self-service
 * "author your own" permission, only `performance-manage` for whoever conducts reviews. A reviewer can never
 * author or finalize a review of their OWN employee record, the same self-review-ban spirit already applied
 * to Procurement/Staff Attendance/Leave, checked directly here since (unlike those) there is no separate
 * requester-vs-reviewer split -- the person drafting a review and the person who could finalize it are
 * usually the same step.
 */
class PerformanceReviewController extends Controller
{
    private function assertCanManageFor(User $user, Employee $employee): void
    {
        abort_unless($user->can('performance-manage'), 403);
        abort_unless($employee->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $employee->branch_id === $user->branch_id, 403);
        abort_if($employee->user_id === $user->id, 403, 'You cannot write your own performance review.');
    }

    public function mine(Request $request): Response
    {
        $user = $request->user();
        $employee = Employee::where('school_id', $user->school_id)->where('user_id', $user->id)->first();

        return Inertia::render('Hr/Performance/Mine', [
            'employee' => $employee ? ['id' => $employee->id, 'full_name' => $employee->full_name] : null,
            'reviews' => $employee
                ? $employee->performanceReviews()->where('status', 'finalized')->with('cycle')->get()->map(fn (PerformanceReview $r) => [
                    'id' => $r->id,
                    'cycle' => $r->cycle->name,
                    'rating' => $r->rating,
                    'comments' => $r->comments,
                    'finalized_at' => $r->finalized_at->toDateString(),
                ])->all()
                : [],
        ]);
    }

    private function rules(): array
    {
        return [
            'cycle_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', Rule::in(PerformanceReview::RATINGS)],
            'comments' => ['required', 'string', 'max:2000'],
            'action' => ['required', Rule::in(['draft', 'finalize'])],
        ];
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanManageFor($user, $employee);

        $validated = $request->validate($this->rules());
        $cycle = PerformanceReviewCycle::where('school_id', $user->school_id)->findOrFail($validated['cycle_id']);

        if ($employee->performanceReviews()->where('cycle_id', $cycle->id)->exists()) {
            throw ValidationException::withMessages([
                'cycle_id' => "{$employee->full_name} already has a review for {$cycle->name}. Change the existing one instead.",
            ]);
        }

        $review = $employee->performanceReviews()->create([
            'cycle_id' => $cycle->id,
            'rating' => $validated['rating'],
            'comments' => $validated['comments'],
            'status' => $validated['action'] === 'finalize' ? 'finalized' : 'draft',
            'reviewed_by' => $user->id,
            'finalized_at' => $validated['action'] === 'finalize' ? now() : null,
        ]);

        if ($review->status === 'finalized') {
            $this->notifyEmployee($review);
        }

        return back()->with('success', $validated['action'] === 'finalize' ? 'Review finalized.' : 'Saved as a draft.');
    }

    public function update(Request $request, PerformanceReview $performanceReview): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanManageFor($user, $performanceReview->employee);
        abort_unless($performanceReview->status === 'draft', 422, 'Only a draft can be changed.');

        $validated = $request->validate([
            'rating' => ['required', 'integer', Rule::in(PerformanceReview::RATINGS)],
            'comments' => ['required', 'string', 'max:2000'],
            'action' => ['required', Rule::in(['draft', 'finalize'])],
        ]);

        $performanceReview->update([
            'rating' => $validated['rating'],
            'comments' => $validated['comments'],
            'status' => $validated['action'] === 'finalize' ? 'finalized' : 'draft',
            'finalized_at' => $validated['action'] === 'finalize' ? now() : null,
        ]);

        if ($performanceReview->status === 'finalized') {
            $this->notifyEmployee($performanceReview);
        }

        return back()->with('success', $validated['action'] === 'finalize' ? 'Review finalized.' : 'Draft updated.');
    }

    public function destroy(Request $request, PerformanceReview $performanceReview): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanManageFor($user, $performanceReview->employee);

        try {
            $performanceReview->delete();
        } catch (DeletionBlockedException $e) {
            return back()->withErrors(['performance' => $e->getMessage()]);
        }

        return back()->with('success', 'Draft removed.');
    }

    private function notifyEmployee(PerformanceReview $performanceReview): void
    {
        $employee = $performanceReview->employee;
        if ($employee->user_id) {
            $employee->user->notify(new PerformanceReviewFinalized($performanceReview));
        }
    }
}
