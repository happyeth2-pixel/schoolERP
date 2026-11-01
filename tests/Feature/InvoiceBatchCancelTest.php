<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * "Create bills" can be undone in one tap, right from the result screen, before a mistaken batch (wrong
 * grade, wrong amount) reaches anyone. Invoices are never deleted through the model (NeverHardDeleted),
 * so this is the one deliberate, narrow exception (InvoiceController::deleteSafely) -- a real delete, not
 * a waiver, because a waived invoice would still block Create Bills from ever generating a correct
 * replacement for that exact (student, year, frequency, period). Blocked per-invoice the moment a real
 * payment exists.
 */
class InvoiceBatchCancelTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices']);
        // Above the school's default waiver_limit_amount (1000 ETB), on purpose -- see the bypass test below.
        FeeStructure::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Tuition', 'amount' => 1500, 'frequency' => 'annual']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function generateBatch(?User $as = null): string
    {
        $this->actingAs($as ?? $this->accountant)
            ->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id])
            ->assertSessionHasNoErrors();

        return session('invoice_run')['batch_id'];
    }

    public function test_a_newly_created_batch_can_be_cancelled_in_one_tap(): void
    {
        $batchId = $this->generateBatch();
        $invoiceId = Invoice::where('generation_batch_id', $batchId)->firstOrFail()->id;

        $this->actingAs($this->accountant)->post("/invoices/batches/{$batchId}/cancel")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertNull(Invoice::find($invoiceId), 'the bill is really gone, not just marked waived');
    }

    public function test_cancelling_frees_the_period_for_a_corrected_re_bill(): void
    {
        $batchId = $this->generateBatch();

        $this->actingAs($this->accountant)->post("/invoices/batches/{$batchId}/cancel")->assertSessionHasNoErrors();

        // the registrar realizes the fee amount was wrong, fixes the fee structure, and re-runs Create
        // Bills for the exact same grade/period -- it must go through clean, not report "already billed"
        FeeStructure::where('branch_id', $this->branchA->id)->update(['amount' => 1800]);
        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id])
            ->assertSessionHasNoErrors();

        $corrected = Invoice::where('student_id', $this->studentA->id)->first();
        $this->assertNotNull($corrected);
        $this->assertEquals(1800, $corrected->totalAmount());
    }

    public function test_an_invoice_already_paid_is_left_alone_when_the_batch_is_cancelled(): void
    {
        $other = $this->makeStudent($this->branchA, $this->sectionA, 'Paidup', 'STU-PD');
        $batchId = $this->generateBatch();

        $paid = Invoice::where('student_id', $other->id)->first();
        Payment::create(['invoice_id' => $paid->id, 'amount' => 500, 'method' => 'cash', 'status' => 'verified', 'submitted_by' => $this->accountant->id, 'verified_by' => $this->accountant->id, 'verified_at' => now(), 'receipt_number' => 'R1']);

        $untouchedId = Invoice::where('student_id', $this->studentA->id)->first()->id;

        $this->actingAs($this->accountant)->post("/invoices/batches/{$batchId}/cancel")
            ->assertSessionHas('warning');

        $this->assertNotNull($paid->fresh(), 'an invoice with a payment on it is never cancelled out from under the family');
        $this->assertNull(Invoice::find($untouchedId));
    }

    public function test_cancelling_only_touches_the_named_batch_not_a_different_one(): void
    {
        $firstBatch = $this->generateBatch();
        $firstInvoiceId = Invoice::where('generation_batch_id', $firstBatch)->firstOrFail()->id;

        // a separate batch (e.g. from an earlier run) that happens to still be in scope
        $other = $this->makeStudent($this->branchA, $this->sectionA, 'Other', 'STU-OT');
        $secondBatchInvoice = Invoice::create(['student_id' => $other->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-SEP', 'frequency' => 'annual', 'period_start' => '2026-09-01', 'generation_batch_id' => (string) \Illuminate\Support\Str::uuid()]);
        $secondBatchInvoice->items()->create(['description' => 'Tuition', 'amount' => 1500, 'item_type' => 'fee']);

        $this->actingAs($this->accountant)->post("/invoices/batches/{$firstBatch}/cancel")->assertSessionHasNoErrors();

        $this->assertNull(Invoice::find($firstInvoiceId));
        $this->assertNotNull($secondBatchInvoice->fresh(), 'a different batch is never touched');
    }

    public function test_only_people_who_manage_invoices_can_cancel_a_batch(): void
    {
        $batchId = $this->generateBatch();
        $invoiceId = Invoice::where('generation_batch_id', $batchId)->firstOrFail()->id;
        $teacher = $this->makeUser('teacher', $this->branchA->id);

        $this->actingAs($teacher)->post("/invoices/batches/{$batchId}/cancel")->assertForbidden();
        $this->assertNotNull(Invoice::find($invoiceId));
    }

    public function test_a_batch_from_another_branch_is_not_found(): void
    {
        $batchId = $this->generateBatch();
        $invoiceId = Invoice::where('generation_batch_id', $batchId)->firstOrFail()->id;
        $otherBranchAccountant = $this->makeUser('accountant', $this->branchB->id, ['manage-invoices']);

        $this->actingAs($otherBranchAccountant)->post("/invoices/batches/{$batchId}/cancel")->assertNotFound();
        $this->assertNotNull(Invoice::find($invoiceId));
    }

    public function test_cancelling_bypasses_the_waiver_limit_approval_gate_since_nothing_was_ever_collected(): void
    {
        // the fee (1500) is set above the school's waiver limit on purpose -- enough to block a plain
        // accountant from waiving a single invoice via toggleWaive() without approve-large-discounts
        $this->school->update(['waiver_limit_amount' => 1000]);
        $batchId = $this->generateBatch();
        $invoiceId = Invoice::where('generation_batch_id', $batchId)->firstOrFail()->id;

        $this->actingAs($this->accountant)->post("/invoices/batches/{$batchId}/cancel")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertNull(Invoice::find($invoiceId));
    }

    public function test_the_cancellation_writes_one_summary_audit_entry_naming_every_bill_it_removed(): void
    {
        $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $batchId = $this->generateBatch();
        AuditLog::query()->delete();

        $this->actingAs($this->accountant)->post("/invoices/batches/{$batchId}/cancel")->assertSessionHasNoErrors();

        $summary = AuditLog::where('action', 'invoices.batch_cancelled')->first();
        $this->assertNotNull($summary);
        $this->assertSame($batchId, $summary->after['batch_id']);
        $this->assertCount(2, $summary->after['invoices']);
    }

    public function test_an_unknown_batch_id_is_not_found(): void
    {
        $this->actingAs($this->accountant)->post('/invoices/batches/'.\Illuminate\Support\Str::uuid().'/cancel')->assertNotFound();
    }

    // ------------------------------------------------------------------ deleting a single, older bill

    public function test_a_single_never_paid_bill_can_be_deleted_any_time_after_creation(): void
    {
        $this->generateBatch();
        $invoice = Invoice::where('student_id', $this->studentA->id)->firstOrFail();

        $this->actingAs($this->accountant)->delete("/invoices/{$invoice->id}")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertNull(Invoice::find($invoice->id));
    }

    public function test_deleting_a_single_bill_frees_its_period_for_a_corrected_re_bill(): void
    {
        $this->generateBatch();
        $invoice = Invoice::where('student_id', $this->studentA->id)->firstOrFail();

        $this->actingAs($this->accountant)->delete("/invoices/{$invoice->id}")->assertSessionHasNoErrors();

        FeeStructure::where('branch_id', $this->branchA->id)->update(['amount' => 2000]);
        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id])->assertSessionHasNoErrors();

        $corrected = Invoice::where('student_id', $this->studentA->id)->first();
        $this->assertNotNull($corrected);
        $this->assertEquals(2000, $corrected->totalAmount());
    }

    public function test_a_bill_with_any_payment_cannot_be_deleted_even_a_rejected_one(): void
    {
        $this->generateBatch();
        $invoice = Invoice::where('student_id', $this->studentA->id)->firstOrFail();
        Payment::create(['invoice_id' => $invoice->id, 'amount' => 500, 'method' => 'cash', 'status' => 'rejected', 'submitted_by' => $this->accountant->id, 'rejection_reason' => 'wrong slip']);

        $this->actingAs($this->accountant)->delete("/invoices/{$invoice->id}")->assertSessionHasErrors();

        $this->assertNotNull($invoice->fresh(), 'a rejected payment is still history; the invoice stays and can be waived instead');
    }

    public function test_owner_and_branch_admin_can_also_delete_a_bill(): void
    {
        $this->generateBatch();
        $owner = $this->makeUser('owner', null);
        $principal = $this->makeUser('branch_admin', $this->branchA->id);

        $a = Invoice::where('student_id', $this->studentA->id)->firstOrFail();
        $this->actingAs($owner)->delete("/invoices/{$a->id}")->assertSessionHasNoErrors();
        $this->assertNull(Invoice::find($a->id));

        $this->generateBatch();
        $b = Invoice::where('student_id', $this->studentA->id)->firstOrFail();
        $this->actingAs($principal)->delete("/invoices/{$b->id}")->assertSessionHasNoErrors();
        $this->assertNull(Invoice::find($b->id));
    }

    public function test_only_people_who_manage_invoices_can_delete_a_single_bill(): void
    {
        $this->generateBatch();
        $invoice = Invoice::where('student_id', $this->studentA->id)->firstOrFail();
        $teacher = $this->makeUser('teacher', $this->branchA->id);

        $this->actingAs($teacher)->delete("/invoices/{$invoice->id}")->assertForbidden();
        $this->assertNotNull(Invoice::find($invoice->id));
    }

    public function test_a_single_bill_from_another_branch_cannot_be_deleted(): void
    {
        $this->generateBatch();
        $invoice = Invoice::where('student_id', $this->studentA->id)->firstOrFail();
        $otherBranchAccountant = $this->makeUser('accountant', $this->branchB->id, ['manage-invoices']);

        $this->actingAs($otherBranchAccountant)->delete("/invoices/{$invoice->id}")->assertForbidden();
        $this->assertNotNull(Invoice::find($invoice->id));
    }

    public function test_deleting_a_single_bill_writes_an_audit_entry(): void
    {
        $this->generateBatch();
        $invoice = Invoice::where('student_id', $this->studentA->id)->firstOrFail();
        AuditLog::query()->delete();

        $this->actingAs($this->accountant)->delete("/invoices/{$invoice->id}")->assertSessionHasNoErrors();

        $entry = AuditLog::where('action', 'invoice.deleted')->first();
        $this->assertNotNull($entry);
        $this->assertCount(1, $entry->after['invoices']);
        $this->assertSame($invoice->invoice_number, $entry->after['invoices'][0]['number']);
    }

    // ------------------------------------------------------------------ deleting several hand-picked bills at once

    public function test_several_hand_picked_bills_can_be_deleted_in_one_go(): void
    {
        $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-SEC');
        $this->makeStudent($this->branchA, $this->sectionA, 'Third', 'STU-THIRD');
        $this->generateBatch();
        $ids = Invoice::where('academic_year_id', $this->year->id)->pluck('id')->all();
        $this->assertGreaterThanOrEqual(3, count($ids));

        $this->actingAs($this->accountant)->delete('/invoices', ['ids' => $ids])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(0, Invoice::whereIn('id', $ids)->count());
    }

    public function test_a_picked_bill_with_a_payment_is_left_alone_the_rest_still_go(): void
    {
        $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-SEC2');
        $this->generateBatch();
        $ids = Invoice::where('academic_year_id', $this->year->id)->pluck('id')->all();
        $paid = Invoice::where('student_id', $this->studentA->id)->first();
        Payment::create(['invoice_id' => $paid->id, 'amount' => 500, 'method' => 'cash', 'status' => 'verified', 'submitted_by' => $this->accountant->id, 'verified_by' => $this->accountant->id, 'verified_at' => now(), 'receipt_number' => 'R-BULK']);

        $this->actingAs($this->accountant)->delete('/invoices', ['ids' => $ids])
            ->assertSessionHas('warning');

        $this->assertNotNull($paid->fresh(), 'the paid one is left alone');
        $this->assertSame(count($ids) - 1, Invoice::whereIn('id', $ids)->count());
    }

    public function test_bulk_delete_ignores_ids_outside_this_users_branches(): void
    {
        $this->generateBatch();
        $mine = Invoice::where('student_id', $this->studentA->id)->firstOrFail();

        $otherStudent = $this->makeStudent($this->branchB, $this->sectionB, 'Other Branch', 'STU-OB');
        $theirs = Invoice::create(['student_id' => $otherStudent->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-OB', 'frequency' => 'annual', 'period_start' => '2026-09-01', 'generation_batch_id' => (string) \Illuminate\Support\Str::uuid()]);

        $this->actingAs($this->accountant)->delete('/invoices', ['ids' => [$mine->id, $theirs->id]])->assertSessionHasNoErrors();

        $this->assertNull(Invoice::find($mine->id));
        $this->assertNotNull(Invoice::find($theirs->id), 'outside this accountant\'s branch, so left untouched');
    }

    public function test_only_people_who_manage_invoices_can_bulk_delete(): void
    {
        $this->generateBatch();
        $ids = Invoice::where('academic_year_id', $this->year->id)->pluck('id')->all();
        $teacher = $this->makeUser('teacher', $this->branchA->id);

        $this->actingAs($teacher)->delete('/invoices', ['ids' => $ids])->assertForbidden();
        $this->assertSame(count($ids), Invoice::whereIn('id', $ids)->count());
    }
}
