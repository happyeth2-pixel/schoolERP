<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewCycle;
use App\Models\User;
use App\Notifications\PerformanceReviewFinalized;
use App\Support\NotificationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** HR module Phase E: performance reviews. Confirmed via AskUserQuestion -- an employee can view their OWN
 * finalized review (read-only), but never authors one; only `performance-manage` holders write/finalize, and
 * a reviewer can never do either for their own employee record. One overall rating (1-5) + comments per
 * cycle, confirmed as the simplest structure that does the job. */
class PerformanceReviewTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function cycle(array $over = []): PerformanceReviewCycle
    {
        return PerformanceReviewCycle::create(array_merge([
            'school_id' => $this->school->id, 'name' => 'Mid-Year 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-06-30',
        ], $over));
    }

    private function employeeFor(User $user, ?int $branchId = null): Employee
    {
        return Employee::create([
            'school_id' => $this->school->id, 'branch_id' => $branchId ?? $user->branch_id, 'user_id' => $user->id,
            'employee_number' => 'EMP-2026-'.$user->id, 'full_name' => $user->name, 'phone' => '0911000002',
            'hire_date' => '2026-01-01', 'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $this->owner->id,
        ]);
    }

    public function test_hr_can_write_a_draft_review_and_then_finalize_it(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['performance-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $cycle = $this->cycle();

        $this->actingAs($hr)->post("/employees/{$employee->id}/performance-reviews", [
            'cycle_id' => $cycle->id, 'rating' => 4, 'comments' => 'Strong term overall.', 'action' => 'draft',
        ])->assertSessionHasNoErrors();

        $review = PerformanceReview::firstOrFail();
        $this->assertSame('draft', $review->status);
        $this->assertNull($review->finalized_at);

        $this->actingAs($hr)->put("/performance-reviews/{$review->id}", [
            'rating' => 5, 'comments' => 'Revised: outstanding term.', 'action' => 'finalize',
        ])->assertSessionHasNoErrors();

        $review->refresh();
        $this->assertSame('finalized', $review->status);
        $this->assertSame(5, $review->rating);
        $this->assertNotNull($review->finalized_at);
    }

    public function test_finalizing_notifies_the_employee(): void
    {
        Notification::fake();
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['performance-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $cycle = $this->cycle();

        $this->actingAs($hr)->post("/employees/{$employee->id}/performance-reviews", [
            'cycle_id' => $cycle->id, 'rating' => 3, 'comments' => 'Meets expectations.', 'action' => 'finalize',
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo($teacher, PerformanceReviewFinalized::class);
    }

    public function test_a_reviewer_cannot_write_or_finalize_their_own_review(): void
    {
        $hrWhoIsAlsoAnEmployee = $this->makeUser('branch_admin', $this->branchA->id, ['performance-manage']);
        $employee = $this->employeeFor($hrWhoIsAlsoAnEmployee, $this->branchA->id);
        $cycle = $this->cycle();

        $this->actingAs($hrWhoIsAlsoAnEmployee)->post("/employees/{$employee->id}/performance-reviews", [
            'cycle_id' => $cycle->id, 'rating' => 5, 'comments' => 'Self-written, should be refused.', 'action' => 'draft',
        ])->assertForbidden();

        $this->assertSame(0, PerformanceReview::count());
    }

    public function test_the_add_review_action_never_shows_on_your_own_employee_page(): void
    {
        $hrWhoIsAlsoAnEmployee = $this->makeUser('branch_admin', $this->branchA->id, ['employee-view', 'performance-manage']);
        $employee = $this->employeeFor($hrWhoIsAlsoAnEmployee, $this->branchA->id);

        $response = $this->actingAs($hrWhoIsAlsoAnEmployee)->get("/employees/{$employee->id}");
        $response->assertInertia(fn ($page) => $page
            ->where('canViewPerformance', true)
            ->where('canManagePerformance', false)
        );
    }

    public function test_a_draft_can_be_edited_and_removed_but_a_finalized_one_cannot(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['performance-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $cycle = $this->cycle();
        $draft = PerformanceReview::create(['employee_id' => $employee->id, 'cycle_id' => $cycle->id, 'rating' => 3, 'comments' => 'x', 'status' => 'draft', 'reviewed_by' => $hr->id]);
        $finalized = PerformanceReview::create(['employee_id' => $employee->id, 'cycle_id' => $this->cycle(['name' => 'Annual 2026'])->id, 'rating' => 4, 'comments' => 'y', 'status' => 'finalized', 'reviewed_by' => $hr->id, 'finalized_at' => now()]);

        $this->actingAs($hr)->put("/performance-reviews/{$draft->id}", ['rating' => 2, 'comments' => 'Edited draft.', 'action' => 'draft'])->assertSessionHasNoErrors();
        $this->assertSame(2, $draft->fresh()->rating);

        $this->actingAs($hr)->put("/performance-reviews/{$finalized->id}", ['rating' => 1, 'comments' => 'Should not apply.', 'action' => 'draft'])->assertStatus(422);
        $this->assertSame(4, $finalized->fresh()->rating);

        $this->actingAs($hr)->delete("/performance-reviews/{$draft->id}")->assertSessionHasNoErrors();
        $this->assertNull(PerformanceReview::find($draft->id));

        $this->actingAs($hr)->delete("/performance-reviews/{$finalized->id}")->assertSessionHasErrors();
        $this->assertNotNull(PerformanceReview::find($finalized->id));
    }

    public function test_only_one_review_per_employee_per_cycle(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['performance-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $cycle = $this->cycle();
        PerformanceReview::create(['employee_id' => $employee->id, 'cycle_id' => $cycle->id, 'rating' => 3, 'comments' => 'x', 'status' => 'draft', 'reviewed_by' => $hr->id]);

        $this->actingAs($hr)->post("/employees/{$employee->id}/performance-reviews", [
            'cycle_id' => $cycle->id, 'rating' => 5, 'comments' => 'Second attempt for the same cycle.', 'action' => 'draft',
        ])->assertSessionHasErrors('cycle_id');

        $this->assertSame(1, PerformanceReview::count());
    }

    public function test_the_employee_can_view_their_own_finalized_review_but_not_a_draft(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['performance-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        PerformanceReview::create(['employee_id' => $employee->id, 'cycle_id' => $this->cycle()->id, 'rating' => 2, 'comments' => 'Draft, not visible yet.', 'status' => 'draft', 'reviewed_by' => $hr->id]);
        PerformanceReview::create(['employee_id' => $employee->id, 'cycle_id' => $this->cycle(['name' => 'Annual 2026'])->id, 'rating' => 4, 'comments' => 'Finalized, visible.', 'status' => 'finalized', 'reviewed_by' => $hr->id, 'finalized_at' => now()]);

        $response = $this->actingAs($teacher)->get('/my-performance');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('employee.id', $employee->id)
            ->has('reviews', 1)
            ->where('reviews.0.comments', 'Finalized, visible.')
        );
    }

    public function test_someone_without_performance_manage_is_refused(): void
    {
        $bystander = $this->makeUser('teacher', $this->branchA->id, []);
        $employee = $this->employeeFor($this->makeUser('teacher', $this->branchA->id, []), $this->branchA->id);
        $cycle = $this->cycle();

        $this->actingAs($bystander)->post("/employees/{$employee->id}/performance-reviews", [
            'cycle_id' => $cycle->id, 'rating' => 3, 'comments' => 'x', 'action' => 'draft',
        ])->assertForbidden();
        $this->actingAs($bystander)->get('/performance-cycles')->assertForbidden();
    }

    public function test_a_cycle_cannot_be_removed_while_in_use(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['performance-manage']);
        $cycle = $this->cycle();
        $employee = $this->employeeFor($this->makeUser('teacher', $this->branchA->id, []), $this->branchA->id);
        PerformanceReview::create(['employee_id' => $employee->id, 'cycle_id' => $cycle->id, 'rating' => 3, 'comments' => 'x', 'status' => 'draft', 'reviewed_by' => $hr->id]);

        $this->actingAs($hr)->delete("/performance-cycles/{$cycle->id}")->assertSessionHasErrors();
        $this->assertNotNull(PerformanceReviewCycle::find($cycle->id));
    }

    public function test_branch_scoping_refuses_a_review_outside_the_actors_branch(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['performance-manage']);
        $employeeInOtherBranch = $this->employeeFor($this->makeUser('teacher', $this->branchB->id, []), $this->branchB->id);
        $cycle = $this->cycle();

        $this->actingAs($hr)->post("/employees/{$employeeInOtherBranch->id}/performance-reviews", [
            'cycle_id' => $cycle->id, 'rating' => 3, 'comments' => 'x', 'action' => 'draft',
        ])->assertForbidden();
    }

    public function test_rating_outside_one_to_five_is_rejected(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['performance-manage']);
        $employee = $this->employeeFor($this->makeUser('teacher', $this->branchA->id, []), $this->branchA->id);
        $cycle = $this->cycle();

        $this->actingAs($hr)->post("/employees/{$employee->id}/performance-reviews", [
            'cycle_id' => $cycle->id, 'rating' => 6, 'comments' => 'x', 'action' => 'draft',
        ])->assertSessionHasErrors('rating');
        $this->assertSame(0, PerformanceReview::count());
    }

    public function test_the_finalized_notification_links_the_employee_to_their_own_page(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['performance-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $review = PerformanceReview::create(['employee_id' => $employee->id, 'cycle_id' => $this->cycle()->id, 'rating' => 4, 'comments' => 'x', 'status' => 'finalized', 'reviewed_by' => $hr->id, 'finalized_at' => now()]);

        $teacher->notify(new PerformanceReviewFinalized($review));
        $presented = NotificationPresenter::present($teacher->notifications()->first());

        $this->assertSame(route('performance-reviews.mine'), $presented['action_url']);
        $this->actingAs($teacher)->get($presented['action_url'])->assertOk();
    }
}
