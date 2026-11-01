<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\Invoice;
use App\Models\MarkBatch;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\Term;
use App\Notifications\InvoiceOverdue;
use App\Notifications\MarkBatchPublished;
use App\Notifications\MarkBatchSubmitted;
use App\Support\NotificationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Two requested additions: (1) a once-per-invoice overdue reminder to whoever manages invoices in that
 * branch (never the family -- confirmed via AskUserQuestion), with the grace period an owner sets in
 * School Settings (payment_due_days), plus a parent-facing page listing every invoice across every
 * child so a family can pay any of them whenever they're ready; and (2) result-workflow notifications --
 * the branch's own approvers are told a batch is awaiting approval, and each guardian is told once their
 * own child's result is actually published.
 */
class BillingAndResultNotificationsTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function invoice(string $periodStart, float $amount = 1000, ?Student $student = null): Invoice
    {
        $i = Invoice::create([
            'student_id' => ($student ?? $this->studentA)->id,
            'academic_year_id' => $this->year->id,
            'invoice_number' => 'INV-'.random_int(100000, 999999),
            'frequency' => 'monthly',
            'period_start' => $periodStart,
        ]);
        $i->items()->create(['description' => 'Tuition', 'item_type' => 'fee', 'unit_amount' => $amount, 'quantity' => 1, 'amount' => $amount]);

        return $i->fresh();
    }

    private function pay(Invoice $i, float $amount): Payment
    {
        return Payment::create(['invoice_id' => $i->id, 'amount' => $amount, 'method' => 'cash', 'status' => 'verified', 'submitted_by' => $this->owner->id, 'verified_by' => $this->owner->id, 'verified_at' => now(), 'receipt_number' => 'R'.random_int(1000, 9999)]);
    }

    // ------------------------------------------------------------------ overdue invoices

    public function test_an_unpaid_invoice_past_its_due_date_notifies_branch_invoice_managers_only(): void
    {
        Notification::fake();
        $manager = $this->makeUser('branch_admin', $this->branchA->id, ['manage-invoices']);
        $elsewhere = $this->makeUser('branch_admin', $this->branchB->id, ['manage-invoices']);
        $noPermission = $this->makeUser('branch_admin', $this->branchA->id, []);
        $this->invoice(now()->subDays(20)->toDateString());

        $this->artisan('invoices:check-overdue')->assertSuccessful();

        Notification::assertSentTo($manager, InvoiceOverdue::class);
        Notification::assertSentTo($this->owner, InvoiceOverdue::class);
        Notification::assertNotSentTo($elsewhere, InvoiceOverdue::class);
        Notification::assertNotSentTo($noPermission, InvoiceOverdue::class);
    }

    public function test_the_overdue_flag_and_notification_only_ever_fire_once_per_invoice(): void
    {
        $manager = $this->makeUser('branch_admin', $this->branchA->id, ['manage-invoices']);
        $invoice = $this->invoice(now()->subDays(20)->toDateString());

        $this->artisan('invoices:check-overdue');
        $this->assertNotNull($invoice->fresh()->overdue_notified_at);

        $this->artisan('invoices:check-overdue');

        $this->assertSame(1, $manager->notifications()->count());
    }

    public function test_a_longer_payment_due_days_setting_delays_when_an_invoice_counts_as_overdue(): void
    {
        $this->school->update(['payment_due_days' => 30]);
        $manager = $this->makeUser('branch_admin', $this->branchA->id, ['manage-invoices']);
        $invoice = $this->invoice(now()->subDays(20)->toDateString());

        $this->artisan('invoices:check-overdue');

        $this->assertNull($invoice->fresh()->overdue_notified_at);
        $this->assertSame(0, $manager->notifications()->count());
    }

    public function test_a_paid_or_waived_invoice_is_never_flagged_overdue(): void
    {
        $manager = $this->makeUser('branch_admin', $this->branchA->id, ['manage-invoices']);
        $paid = $this->invoice(now()->subDays(20)->toDateString());
        $this->pay($paid, 1000);
        $waived = $this->invoice(now()->subDays(50)->toDateString());
        $waived->update(['is_waived' => true, 'waiver_reason' => 'test', 'waived_by' => $this->owner->id, 'waived_at' => now()]);

        $this->artisan('invoices:check-overdue');

        $this->assertNull($paid->fresh()->overdue_notified_at);
        $this->assertNull($waived->fresh()->overdue_notified_at);
        $this->assertSame(0, $manager->notifications()->count());
    }

    public function test_the_invoice_overdue_notification_links_straight_to_that_invoice(): void
    {
        $manager = $this->makeUser('branch_admin', $this->branchA->id, ['manage-invoices']);
        $invoice = $this->invoice(now()->subDays(20)->toDateString());

        $this->artisan('invoices:check-overdue');

        $presented = NotificationPresenter::present($manager->notifications()->first());
        $this->assertSame(route('invoices.show', $invoice->id), $presented['action_url']);
        $this->actingAs($manager)->get($presented['action_url'])->assertOk();
    }

    // ------------------------------------------------------------------ the parent's own invoice list

    public function test_a_parent_sees_only_their_own_childrens_invoices(): void
    {
        $parent = $this->makeParent($this->studentA);
        $mine = $this->invoice(now()->toDateString(), 500, $this->studentA);
        $theirs = $this->invoice(now()->toDateString(), 700, $this->studentB);

        $page = $this->props($this->actingAs($parent)->get(route('family.invoices'))->assertOk());

        $ids = collect($page['invoices'])->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_the_status_filter_narrows_the_parents_list_to_matching_invoices(): void
    {
        $parent = $this->makeParent($this->studentA);
        $unpaid = $this->invoice(now()->toDateString(), 500, $this->studentA);
        $paidInvoice = $this->invoice(now()->subMonth()->toDateString(), 300, $this->studentA);
        $this->pay($paidInvoice, 300);

        $page = $this->props($this->actingAs($parent)->get(route('family.invoices', ['status' => 'paid']))->assertOk());

        $this->assertSame([$paidInvoice->id], collect($page['invoices'])->pluck('id')->all());
    }

    public function test_an_overdue_invoice_shows_as_overdue_on_the_parents_own_list(): void
    {
        $parent = $this->makeParent($this->studentA);
        $invoice = $this->invoice(now()->subDays(20)->toDateString());

        $page = $this->props($this->actingAs($parent)->get(route('family.invoices'))->assertOk());

        $row = collect($page['invoices'])->firstWhere('id', $invoice->id);
        $this->assertTrue($row['is_overdue']);
    }

    // ------------------------------------------------------------------ the grace-period setting

    public function test_an_owner_can_set_how_many_days_a_family_has_to_pay(): void
    {
        $payload = [
            'name' => $this->school->name, 'calendar_preference' => 'both', 'missing_mark_policy' => 'exclude',
            'discount_limit_percent' => 10, 'waiver_limit_amount' => 1000, 'payment_due_days' => 21,
            'allow_overlapping_terms' => false, 'enabled_languages' => ['en', 'am'],
        ];

        $this->actingAs($this->owner)->patch(route('school-settings.update'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(21, $this->school->fresh()->payment_due_days);
    }

    public function test_a_negative_payment_due_days_is_refused(): void
    {
        $payload = [
            'name' => $this->school->name, 'calendar_preference' => 'both', 'missing_mark_policy' => 'exclude',
            'discount_limit_percent' => 10, 'waiver_limit_amount' => 1000, 'payment_due_days' => -5,
            'allow_overlapping_terms' => false, 'enabled_languages' => ['en', 'am'],
        ];

        $this->actingAs($this->owner)->patch(route('school-settings.update'), $payload)->assertSessionHasErrors('payment_due_days');
    }

    // ------------------------------------------------------------------ result-workflow notifications

    private function setUpMarkBatch(): array
    {
        $subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Math']);
        $term = Term::create(['academic_year_id' => $this->year->id, 'name' => 'T1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-20']);
        $exam = Exam::create(['school_id' => $this->school->id, 'term_id' => $term->id, 'name' => 'Mid-term', 'weight_percent' => 100]);
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['enter-marks']);
        TeachingAssignment::create(['section_id' => $this->sectionA->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id]);

        $this->actingAs($teacher)->get("/marks/{$exam->id}/{$this->sectionA->id}/{$subject->id}");
        $batch = MarkBatch::firstOrFail();

        return compact('subject', 'exam', 'teacher', 'batch');
    }

    public function test_submitting_a_batch_notifies_the_branchs_own_approvers_but_not_the_teacher_or_other_branches(): void
    {
        Notification::fake();
        ['teacher' => $teacher, 'batch' => $batch] = $this->setUpMarkBatch();
        $approver = $this->makeUser('branch_admin', $this->branchA->id, ['approve-results']);
        $elsewhere = $this->makeUser('branch_admin', $this->branchB->id, ['approve-results']);
        $noPermission = $this->makeUser('branch_admin', $this->branchA->id, []);

        $this->actingAs($teacher)->post("/mark-batches/{$batch->id}/submit")->assertSessionHasNoErrors();

        Notification::assertSentTo($approver, MarkBatchSubmitted::class);
        Notification::assertSentTo($this->owner, MarkBatchSubmitted::class);
        Notification::assertNotSentTo($teacher, MarkBatchSubmitted::class);
        Notification::assertNotSentTo($elsewhere, MarkBatchSubmitted::class);
        Notification::assertNotSentTo($noPermission, MarkBatchSubmitted::class);
    }

    public function test_publishing_a_batch_notifies_each_enrolled_students_own_guardians_only(): void
    {
        Notification::fake();
        ['teacher' => $teacher, 'batch' => $batch] = $this->setUpMarkBatch();
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $parentA = $this->makeParent($this->studentA);
        $parentSecond = $this->makeParent($second);
        $approver = $this->makeUser('branch_admin', $this->branchA->id, ['approve-results', 'publish-results']);

        $this->actingAs($teacher)->post("/mark-batches/{$batch->id}/submit");
        $this->actingAs($approver)->post("/mark-batches/{$batch->id}/approve");
        $this->actingAs($approver)->post("/mark-batches/{$batch->id}/publish")->assertSessionHasNoErrors();

        Notification::assertSentTo($parentA, MarkBatchPublished::class, fn ($n) => $n->student->is($this->studentA));
        Notification::assertSentTo($parentSecond, MarkBatchPublished::class, fn ($n) => $n->student->is($second));
    }

    public function test_the_results_submitted_notification_links_the_approver_to_the_approvals_queue(): void
    {
        ['teacher' => $teacher, 'batch' => $batch] = $this->setUpMarkBatch();
        $approver = $this->makeUser('branch_admin', $this->branchA->id, ['approve-results']);

        $this->actingAs($teacher)->post("/mark-batches/{$batch->id}/submit");

        $presented = NotificationPresenter::present($approver->notifications()->first());
        $this->assertSame(route('mark-batches.approvals'), $presented['action_url']);
        $this->actingAs($approver)->get($presented['action_url'])->assertOk();
    }

    public function test_the_result_published_notification_links_the_guardian_straight_to_their_own_child(): void
    {
        ['teacher' => $teacher, 'batch' => $batch] = $this->setUpMarkBatch();
        $approver = $this->makeUser('branch_admin', $this->branchA->id, ['approve-results', 'publish-results']);
        $parent = $this->makeParent($this->studentA);

        $this->actingAs($teacher)->post("/mark-batches/{$batch->id}/submit");
        $this->actingAs($approver)->post("/mark-batches/{$batch->id}/approve");
        $this->actingAs($approver)->post("/mark-batches/{$batch->id}/publish");

        $presented = NotificationPresenter::present($parent->notifications()->first());
        $this->assertSame(route('family.results', ['student' => $this->studentA->id]), $presented['action_url']);
        $this->actingAs($parent)->get($presented['action_url'])->assertOk();
    }
}
