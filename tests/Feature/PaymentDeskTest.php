<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Taking a payment at the desk, end to end: partial then full, the notes, the bank/mobile-money reference that was used
 * before, what the confirmation shows, and the safeguards around it (verification, rejection, refund, authorization,
 * branches, audit). The money rules themselves (locks, balance, receipt numbers) are pinned in PaymentWorkflowTest and
 * StaffAssistedServiceTest; this adds the parts the desk flow put on top.
 */
class PaymentDeskTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $accountant;
    private User $parent;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices', 'verify-payments', 'view-financial-reports']);
        $this->parent = $this->makeParent($this->studentA);
    }

    private function invoice(float $amount = 1000, $student = null): Invoice
    {
        $i = Invoice::create(['student_id' => ($student ?? $this->studentA)->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-D'.(++$this->n), 'total_amount' => $amount, 'status' => 'pending', 'frequency' => 'annual', 'period_label' => 'Term '.$this->n, 'period_start' => now()->addDays($this->n)->toDateString()]);
        $i->items()->create(['description' => 'Tuition', 'amount' => $amount, 'item_type' => 'fee']);

        return $i->fresh();
    }

    private function record(Invoice $i, array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->accountant)->post("/invoices/{$i->id}/record-payment", $over + ['amount' => 400, 'method' => 'cash', 'paid_by_name' => 'Almaz Tesfaye']);
    }

    private function review(Payment $p, ?User $as = null): array
    {
        return json_decode(json_encode($this->props($this->actingAs($as ?? $this->accountant)->get("/payments/{$p->id}")->assertOk())), true);
    }

    // ================================================================== partial then full

    public function test_a_partial_payment_then_the_rest_settles_the_bill_and_every_step_shows_what_is_left(): void
    {
        $bill = $this->invoice(1000);

        $form = json_decode(json_encode($this->props($this->actingAs($this->accountant)->get("/invoices/{$bill->id}/record-payment")->assertOk())), true);
        $this->assertEquals(1000, $form['invoice']['total']);
        $this->assertEquals(0, $form['invoice']['collected']);
        $this->assertEquals(1000, $form['invoice']['balance']);
        $this->assertSame('G1 A', $form['student']['class']);

        $this->record($bill, ['amount' => 400])->assertSessionHasNoErrors();
        $first = Payment::firstOrFail();
        $r = $this->review($first);
        $this->assertEquals(600, $r['invoice']['balance'], 'the confirmation shows what is left');
        $this->assertSame('counter', $r['payment']['channel']);

        $form = json_decode(json_encode($this->props($this->actingAs($this->accountant)->get("/invoices/{$bill->id}/record-payment")->assertOk())), true);
        $this->assertEquals(400, $form['invoice']['collected'], 'previously paid');
        $this->assertEquals(600, $form['invoice']['balance']);

        $this->record($bill, ['amount' => 600, 'paid_by_name' => 'Ato Tesfaye'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $bill->fresh()->figures()['status']);
        $this->assertEquals(0, $bill->fresh()->figures()['outstanding']);
        $this->assertCount(2, Payment::pluck('receipt_number')->unique());
        $this->actingAs($this->accountant)->get("/invoices/{$bill->id}/record-payment")->assertRedirect(route('invoices.show', $bill));
    }

    public function test_a_payment_of_the_whole_balance_at_once_is_full_and_nothing_more_can_be_taken(): void
    {
        $bill = $this->invoice(750);
        $this->record($bill, ['amount' => 750])->assertSessionHasNoErrors();

        $this->assertSame('paid', $bill->fresh()->figures()['status']);
        $this->record($bill, ['amount' => 1])->assertSessionHasErrors('amount');
        $this->assertSame(1, Payment::count());
    }

    // ================================================================== notes

    public function test_the_note_is_kept_with_the_payment_and_in_the_audit_entry(): void
    {
        $bill = $this->invoice();
        $this->record($bill, ['note' => 'Brought the cash in an envelope'])->assertSessionHasNoErrors();

        $p = Payment::firstOrFail();
        $this->assertSame('Brought the cash in an envelope', $p->note);
        $this->assertSame('Brought the cash in an envelope', $this->review($p)['payment']['note']);
        $this->assertSame('Brought the cash in an envelope', AuditLog::where('action', 'payment.recorded')->firstOrFail()->after['note']);
    }

    public function test_a_note_is_required_when_the_method_is_something_else_and_optional_otherwise(): void
    {
        $bill = $this->invoice();

        $this->record($bill, ['method' => 'other', 'reference_number' => 'CHQ-1'])->assertSessionHasErrors('note');
        $this->assertSame(0, Payment::count());

        $this->record($bill, ['method' => 'other', 'reference_number' => 'CHQ-1', 'note' => 'Cheque, cleared today'])->assertSessionHasNoErrors();
        $this->record($bill, ['amount' => 100])->assertSessionHasNoErrors();   // cash, no note
        $this->assertNull(Payment::latest('id')->first()->note);
    }

    public function test_a_very_long_note_is_refused(): void
    {
        $this->record($this->invoice(), ['note' => str_repeat('x', 501)])->assertSessionHasErrors('note');
    }

    // ================================================================== a reference that was used before

    public function test_a_reference_already_used_is_stopped_with_the_details_until_it_is_confirmed(): void
    {
        $one = $this->invoice(1000);
        $two = $this->invoice(500);
        $this->record($one, ['method' => 'bank_transfer', 'reference_number' => 'FT26-1', 'amount' => 300])->assertSessionHasNoErrors();

        $this->record($two, ['method' => 'bank_transfer', 'reference_number' => 'ft26-1 ', 'amount' => 200])->assertSessionHasErrors('duplicate_reference');
        $this->assertSame(1, Payment::count(), 'nothing was recorded');
        $message = session('errors')->first('duplicate_reference');
        $this->assertStringContainsString($one->invoice_number, $message);
        $this->assertStringContainsString('300.00', $message);
    }

    public function test_confirming_that_it_is_a_different_payment_records_it_and_says_so_in_the_audit_trail(): void
    {
        $one = $this->invoice(1000);
        $two = $this->invoice(500);
        $this->record($one, ['method' => 'bank_transfer', 'reference_number' => 'FT26-2', 'amount' => 300]);

        $this->record($two, ['method' => 'bank_transfer', 'reference_number' => 'FT26-2', 'amount' => 200, 'confirm_duplicate' => 1])->assertSessionHasNoErrors();

        $this->assertSame(2, Payment::count());
        $logs = AuditLog::where('action', 'payment.recorded')->orderBy('id')->get();
        $this->assertFalse($logs[0]->after['duplicate_reference_confirmed']);
        $this->assertTrue($logs[1]->after['duplicate_reference_confirmed']);
    }

    public function test_a_reference_waiting_to_be_checked_from_a_parent_counts_too(): void
    {
        $bill = $this->invoice(1000);
        Payment::create(['invoice_id' => $bill->id, 'amount' => 300, 'method' => 'bank_transfer', 'reference_number' => 'FT26-3', 'submitted_by' => $this->parent->id, 'status' => 'pending']);

        $this->record($bill, ['method' => 'bank_transfer', 'reference_number' => 'FT26-3', 'amount' => 300])->assertSessionHasErrors('duplicate_reference');
        $this->assertSame(1, Payment::count());
    }

    public function test_a_rejected_payment_does_not_block_its_reference(): void
    {
        $bill = $this->invoice(1000);
        Payment::create(['invoice_id' => $bill->id, 'amount' => 300, 'method' => 'bank_transfer', 'reference_number' => 'FT26-4', 'submitted_by' => $this->parent->id, 'status' => 'rejected', 'rejection_reason' => 'blurry']);

        $this->record($bill, ['method' => 'bank_transfer', 'reference_number' => 'FT26-4', 'amount' => 300])->assertSessionHasNoErrors();
    }

    public function test_a_reference_used_in_another_branch_is_stopped_without_saying_whose(): void
    {
        $eastBill = $this->invoice(900, $this->studentB);
        $this->record($eastBill, ['method' => 'mobile_money', 'reference_number' => 'TB-9', 'amount' => 900], $this->owner)->assertSessionHasNoErrors();
        $mine = $this->invoice(500);

        $this->record($mine, ['method' => 'mobile_money', 'reference_number' => 'TB-9', 'amount' => 500])->assertSessionHasErrors('duplicate_reference');
        $message = session('errors')->first('duplicate_reference');
        $this->assertStringNotContainsString($this->studentB->first_name, $message);
        $this->assertStringNotContainsString($eastBill->invoice_number, $message);
        $this->assertSame(1, Payment::count());
    }

    public function test_cash_has_no_reference_and_never_triggers_the_check(): void
    {
        $bill = $this->invoice(1000);
        $this->record($bill, ['amount' => 100])->assertSessionHasNoErrors();
        $this->record($bill, ['amount' => 200])->assertSessionHasNoErrors();
        $this->assertSame(2, Payment::count());
    }

    // ================================================================== the confirmation page

    public function test_the_confirmation_gives_the_amount_the_balance_the_reference_and_where_done_goes(): void
    {
        $bill = $this->invoice(1000);
        $this->record($bill, ['method' => 'mobile_money', 'reference_number' => 'TB-77', 'amount' => 250]);
        $p = Payment::firstOrFail();

        $r = $this->review($p);
        $this->assertEquals(250, $r['payment']['amount']);
        $this->assertEquals(750, $r['invoice']['balance']);
        $this->assertSame('TB-77', $r['payment']['reference_number']);
        $this->assertNotNull($r['payment']['receipt_url']);
        $this->assertSame(route('students.desk', $this->studentA->id), $r['doneUrl']);
    }

    public function test_done_goes_to_the_payments_list_for_someone_who_cannot_open_students(): void
    {
        $bill = $this->invoice();
        $cashier = $this->makeUser('accountant', $this->branchA->id, ['verify-payments']);
        $this->record($bill, [], $cashier)->assertSessionHasNoErrors();

        $this->assertSame(route('payments.pending'), $this->review(Payment::firstOrFail(), $cashier)['doneUrl']);
    }

    // ================================================================== verification, rejection, refund, authorization

    public function test_a_parents_proof_is_verified_or_rejected_with_a_reason_and_both_are_audited(): void
    {
        Storage::fake('local');
        $bill = $this->invoice(1000);
        $this->actingAs($this->parent)->post("/invoices/{$bill->id}/payments", ['amount' => 300, 'method' => 'bank_transfer', 'reference_number' => 'FT-P1', 'proof' => UploadedFile::fake()->image('p.png')])->assertSessionHasNoErrors();
        $this->actingAs($this->parent)->post("/invoices/{$bill->id}/payments", ['amount' => 200, 'method' => 'bank_transfer', 'reference_number' => 'FT-P2', 'proof' => UploadedFile::fake()->image('q.png')])->assertSessionHasNoErrors();
        [$a, $b] = Payment::orderBy('id')->get()->all();

        $this->actingAs($this->accountant)->post("/payments/{$b->id}/reject", ['rejection_reason' => ''])->assertSessionHasErrors('rejection_reason');
        $this->assertSame('pending', $b->fresh()->status, 'a reason is required to reject');

        $this->actingAs($this->accountant)->post("/payments/{$a->id}/verify")->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post("/payments/{$b->id}/reject", ['rejection_reason' => 'The slip is for a different amount'])->assertSessionHasNoErrors();

        $this->assertSame(700.0, (float) $bill->fresh()->figures()['outstanding']);
        $this->assertNotNull(AuditLog::where('action', 'payment.verified')->first());
        $this->assertSame('The slip is for a different amount', AuditLog::where('action', 'payment.rejected')->firstOrFail()->note);
    }

    public function test_a_refund_of_a_desk_payment_needs_refund_payments_and_is_a_new_record_not_an_edit(): void
    {
        $bill = $this->invoice(1000);
        $this->record($bill, ['amount' => 500]);
        $p = Payment::firstOrFail();

        $this->actingAs($this->accountant)->post("/payments/{$p->id}/refund", ['amount' => 100, 'kind' => 'refund', 'reason' => 'overpaid'])->assertForbidden();

        $this->actingAs($this->owner)->post("/payments/{$p->id}/refund", ['amount' => 100, 'kind' => 'refund', 'reason' => 'Paid twice at the desk'])->assertSessionHasNoErrors();
        $this->assertEquals(600, $bill->fresh()->figures()['outstanding']);
        $this->assertEquals(500, (float) $p->fresh()->amount, 'the payment itself is never edited');
        $this->assertSame(1, $p->refunds()->count());
        $this->assertSame(1, AuditLog::where('action', 'payment_refund.created')->count());
    }

    public function test_payments_cannot_be_edited_or_deleted_through_any_route(): void
    {
        $bill = $this->invoice();
        $this->record($bill);
        $p = Payment::firstOrFail();

        foreach (['patch', 'put', 'delete'] as $verb) {
            $this->actingAs($this->owner)->{$verb}("/payments/{$p->id}")->assertStatus(405);
        }
        $this->expectException(\Throwable::class);
        $p->delete();
    }

    public function test_recording_is_refused_across_branches_and_to_people_without_verify_payments_and_leaves_no_trace(): void
    {
        $eastBill = $this->invoice(900, $this->studentB);
        $bill = $this->invoice();

        $this->record($eastBill)->assertForbidden();
        $this->record($bill, [], $this->makeUser('branch_admin', $this->branchA->id, ['manage-invoices', 'manage-students']))->assertForbidden();
        $this->record($bill, [], $this->makeUser('teacher', $this->branchA->id))->assertForbidden();
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, AuditLog::where('action', 'payment.recorded')->count());
    }

    public function test_every_recorded_payment_has_exactly_one_audit_entry_naming_who_and_how_much(): void
    {
        $bill = $this->invoice(1000);
        $this->record($bill, ['amount' => 400]);
        $this->record($bill, ['amount' => 250, 'paid_by_name' => 'Ato Tesfaye']);

        $logs = AuditLog::where('action', 'payment.recorded')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$this->accountant->id, $this->accountant->id], $logs->pluck('user_id')->all());
        $this->assertEquals([400, 250], $logs->map(fn ($l) => (float) $l->after['amount'])->all());
        $this->assertEquals([600, 350], $logs->map(fn ($l) => (float) $l->after['balance_after'])->all());
    }
}
