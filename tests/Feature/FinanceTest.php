<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Student;
use App\Models\User;
use App\Notifications\PaymentRefunded;
use App\Services\Finance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** Items 32-34: one definition of the money figures, refunds as their own records, discounts/waivers within limits. */
class FinanceTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        Carbon::setTestNow('2026-10-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(float $amount = 1000, ?Student $student = null): Invoice
    {
        $i = Invoice::create(['student_id' => ($student ?? $this->studentA)->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-'.(++$this->n), 'total_amount' => $amount, 'status' => 'pending', 'frequency' => 'annual', 'period_start' => Carbon::parse('2026-09-01')->addDays($this->n)->toDateString()]);
        $i->items()->create(['description' => 'Tuition', 'amount' => $amount, 'item_type' => 'fee']);

        return $i->fresh();
    }

    private function pay(Invoice $i, float $amount, string $status = 'verified'): Payment
    {
        return Payment::create(['invoice_id' => $i->id, 'amount' => $amount, 'method' => 'cash', 'status' => $status, 'submitted_by' => $this->owner->id, 'verified_by' => $status === 'verified' ? $this->owner->id : null, 'verified_at' => $status === 'verified' ? now() : null, 'receipt_number' => $status === 'verified' ? 'R'.(++$this->n) : null]);
    }

    private function refund(Payment $p, float $amount, string $kind = 'refund'): PaymentRefund
    {
        return $p->refunds()->create(['amount' => $amount, 'kind' => $kind, 'reason' => 'test', 'refunded_by' => $this->owner->id, 'refunded_at' => now()]);
    }

    private function fig(Invoice $i): array
    {
        return Finance::figures($i->fresh());
    }

    // ------------------------------------------------------------------ 32

    public function test_the_formulas(): void
    {
        // plain partial payment; pending and rejected payments are not money
        $i = $this->invoice(1000);
        $this->pay($i, 300);
        $this->pay($i, 500, 'pending');
        $this->pay($i, 200, 'rejected');
        $f = $this->fig($i);
        $this->assertEquals([1000, 0, 1000, 300, 700, 0, 'partially_paid'], [$f['gross'], $f['discount'], $f['net'], $f['collected'], $f['outstanding'], $f['waived'], $f['status']]);

        // discount lowers what is billed and owed
        $i->update(['discount_amount' => 100, 'discount_reason' => 'x']);
        $f = $this->fig($i);
        $this->assertEquals([900, 300, 600], [$f['net'], $f['collected'], $f['outstanding']]);

        // a refund of the verified payment puts the money back on the balance
        $this->refund(Payment::where('status', 'verified')->first(), 100);
        $f = $this->fig($i);
        $this->assertEquals([200, 100, 700], [$f['collected'], $f['refunded'], $f['outstanding']]);

        // waiving forgives only what is still unpaid; the collected money stays collected
        $i->update(['is_waived' => true, 'waiver_reason' => 'hardship']);
        $f = $this->fig($i);
        $this->assertEquals([700, 200, 0, 'waived'], [$f['waived'], $f['collected'], $f['outstanding'], $f['status']]);
        $this->assertEquals(200, $f['due'], 'due = billed - waived');
    }

    public function test_full_payment_overpayment_and_clamped_discount(): void
    {
        $i = $this->invoice(500);
        $this->pay($i, 500);
        $this->assertSame('paid', $this->fig($i)['status']);

        $this->pay($i, 50);   // cannot happen through the app; the definition still has to be sane
        $f = $this->fig($i);
        $this->assertEquals([0, 50], [$f['outstanding'], $f['credit']]);

        $j = $this->invoice(100);
        $j->update(['discount_amount' => 999]);
        $f = $this->fig($j);
        $this->assertEquals([100, 0, 0], [$f['discount'], $f['net'], $f['outstanding']], 'a discount never exceeds the invoice');
    }

    public function test_cents_do_not_drift(): void
    {
        $i = $this->invoice(0.30);
        $this->pay($i, 0.10);
        $this->pay($i, 0.20);
        $this->assertEquals(0.0, $this->fig($i)['outstanding']);
        $this->assertSame('paid', $this->fig($i)['status']);
    }

    public function test_dashboard_invoice_list_and_export_all_agree_with_the_service(): void
    {
        $a = $this->invoice(1000);
        $this->pay($a, 400);
        $b = $this->invoice(800, $this->studentB);
        $b->update(['discount_amount' => 80, 'discount_reason' => 'x']);
        $this->pay($b, 200);
        $this->refund(Payment::where('invoice_id', $b->id)->first(), 50);
        $c = $this->invoice(600);
        $c->update(['is_waived' => true, 'waiver_reason' => 'x']);
        $this->pay($this->invoice(300), 300);

        $truth = Finance::summarize(Finance::invoicesFor($this->owner)->get());
        $this->assertEquals(2700 - 80, $truth['net']);
        $this->assertEquals(400 + 150 + 300, $truth['collected']);
        $this->assertEquals(600, $truth['waived']);

        $dash = $this->props($this->actingAs($this->owner)->get('/dashboard'))['stats'];
        $this->assertEquals($truth['collected'], $dash['fees_paid']);
        $this->assertEquals($truth['due'], $dash['fees_total']);
        $this->assertEquals($truth['outstanding'], $dash['outstanding_fees']);
        $this->assertEquals($truth['collection_rate'], $dash['fee_collection_rate']);

        $accountant = $this->makeUser('accountant', null);
        $acc = $this->props($this->actingAs($accountant)->get('/dashboard'))['stats'];
        $this->assertEquals($truth['outstanding'], $acc['outstanding_balance']);
        $this->assertEquals($truth['collected'], $acc['fees_paid']);

        $list = $this->props($this->actingAs($this->owner)->get('/invoices'))['totals'];
        $this->assertEquals($truth['net'], $list['net']);
        $this->assertEquals($truth['collected'], $list['collected']);
        $this->assertEquals($truth['outstanding'], $list['outstanding']);
        $this->assertEquals($truth['waived'], $list['waived']);

        Excel::fake();
        $this->actingAs($this->owner)->get('/invoices/export');
        Excel::assertDownloaded('invoices.xlsx', function ($export) use ($truth) {
            $rows = collect($export->array());
            $total = $rows->firstWhere(0, 'TOTAL');
            $this->assertEquals([$truth['net'], $truth['collected'], $truth['outstanding']], [$total[6], $total[7], $total[8]]);

            return true;
        });

        // and the per-invoice figures on the invoice page match the service too
        $show = $this->props($this->actingAs($this->owner)->get("/invoices/{$b->id}"))['invoice'];
        $this->assertEquals(Finance::figures($b->fresh())['outstanding'], $show['balance']);
        $this->assertEquals(150, $show['paid']);
    }

    public function test_a_waived_invoice_is_not_counted_as_owed(): void
    {
        $i = $this->invoice(1000);
        $i->update(['is_waived' => true, 'waiver_reason' => 'x']);
        $this->assertEquals(0, Finance::summarize(Finance::invoicesFor($this->owner)->get())['outstanding']);
    }

    public function test_cash_flow_uses_the_addis_day_and_nets_refunds(): void
    {
        Carbon::setTestNow('2026-10-14 22:30:00');   // 01:30 on the 15th in Addis
        $i = $this->invoice(1000);
        $p = $this->pay($i, 400);
        $this->refund($p, 100);
        $today = Finance::cashFlow($this->owner, ...Finance::today());
        $this->assertEquals([400, 100, 300], [$today['received'], $today['refunded'], $today['net']]);

        Carbon::setTestNow('2026-10-14 20:00:00');   // still the 14th in Addis: nothing today
        $this->assertEquals(0, Finance::cashFlow($this->owner, ...Finance::today())['received']);
    }

    // ------------------------------------------------------------------ 33

    public function test_a_verified_payment_can_be_refunded_partly_then_fully_but_never_beyond(): void
    {
        $boss = $this->owner;
        $parent = $this->makeParent($this->studentA);
        $i = $this->invoice(1000);
        $p = $this->pay($i, 600);
        $post = fn (array $d) => $this->actingAs($boss)->post("/payments/{$p->id}/refund", $d);

        $post(['amount' => 200, 'kind' => 'refund', 'reason' => 'Duplicate transfer', 'reference' => 'CBE123'])->assertSessionHasNoErrors();
        $r = PaymentRefund::firstOrFail();
        $this->assertSame($boss->id, $r->refunded_by);
        $this->assertNotNull($r->refunded_at);
        $this->assertEquals(200, $r->amount);
        $this->assertEquals(200, $this->fig($i)['refunded']);
        $this->assertEquals(400, $this->fig($i)['collected']);
        $this->assertEquals('verified', $p->fresh()->status, 'the payment itself is untouched');
        $this->assertEquals(600, $p->fresh()->amount);

        $post(['amount' => 500, 'kind' => 'refund', 'reason' => 'too much'])->assertSessionHasErrors('amount');
        $post(['amount' => 400, 'kind' => 'reversal', 'reason' => 'Money never arrived'])->assertSessionHasNoErrors();
        $post(['amount' => 1, 'kind' => 'refund', 'reason' => 'again'])->assertSessionHasErrors('amount');
        $this->assertEquals(0, $this->fig($i)['collected']);
        $this->assertEquals(1000, $this->fig($i)['outstanding']);

        $post(['amount' => 1, 'kind' => 'refund', 'reason' => ''])->assertSessionHasErrors('reason');

        // audit trail + parent notified
        $log = AuditLog::where('action', 'payment_refund.created')->latest('id')->firstOrFail();
        $this->assertSame($boss->id, $log->user_id);
        $this->assertSame('Money never arrived', $log->note);
        $this->assertSame(2, $parent->notifications()->where('type', PaymentRefunded::class)->count());
        $this->assertStringContainsString('Duplicate transfer', $parent->notifications()->oldest()->first()->data['body']);
    }

    public function test_only_verified_payments_and_only_authorized_staff_and_never_deleted_or_edited(): void
    {
        $i = $this->invoice(1000);
        $pending = $this->pay($i, 100, 'pending');
        $verified = $this->pay($i, 100);
        $data = ['amount' => 10, 'kind' => 'refund', 'reason' => 'because'];

        $this->actingAs($this->owner)->post("/payments/{$pending->id}/refund", $data)->assertSessionHasErrors('amount');

        $accountant = $this->makeUser('accountant', $this->branchA->id);
        $this->actingAs($accountant)->post("/payments/{$verified->id}/refund", $data)->assertForbidden();
        $this->actingAs($this->makeParent($this->studentA))->post("/payments/{$verified->id}/refund", $data)->assertNotFound();

        // holding the permission is not enough outside your branch
        $eastFinance = $this->makeUser('accountant', $this->branchB->id);
        $eastFinance->givePermissionTo('refund-payments');
        $this->actingAs($eastFinance)->post("/payments/{$verified->id}/refund", $data)->assertForbidden();
        $accountant->givePermissionTo('refund-payments');
        $this->actingAs($accountant)->post("/payments/{$verified->id}/refund", $data)->assertSessionHasNoErrors();

        $refund = PaymentRefund::firstOrFail();
        foreach ([fn () => $refund->delete(), fn () => $refund->update(['amount' => 1]), fn () => $verified->delete()] as $try) {
            try {
                $try();
                $this->fail('should have been refused');
            } catch (\App\Exceptions\DeletionBlockedException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_refunded_payment_can_be_paid_again_and_status_follows(): void
    {
        $i = $this->invoice(100);
        $p = $this->pay($i, 100);
        $this->assertSame('paid', $this->fig($i)['status']);
        $this->refund($p, 100);
        $this->assertSame('unpaid', $this->fig($i)['status']);
        $this->assertEquals(100, $i->fresh()->balance());
    }

    // ------------------------------------------------------------------ 34

    public function test_discounts_within_the_limit_need_a_reason_and_larger_ones_need_an_approver(): void
    {
        $i = $this->invoice(1000);
        $clerk = $this->makeUser('accountant', $this->branchA->id);   // manage-invoices, no approval right
        $disc = fn ($u, $amount, $reason = 'Sibling') => $this->actingAs($u)->patch("/invoices/{$i->id}/discount", ['discount_amount' => $amount, 'discount_reason' => $reason]);

        $disc($clerk, 100)->assertSessionHasNoErrors();   // exactly 10%
        $this->assertEquals(100, $i->fresh()->discount_amount);
        $this->assertSame($clerk->id, $i->fresh()->discount_by);

        $disc($clerk, 100.01)->assertSessionHasErrors('discount_amount');
        $disc($clerk, 300)->assertSessionHasErrors('discount_amount');
        $this->assertEquals(100, $i->fresh()->discount_amount, 'refused discount changed nothing');

        $disc($clerk, 50, '')->assertSessionHasErrors('discount_reason');
        $disc($clerk, 5000)->assertSessionHasErrors('discount_amount');

        $this->actingAs($this->owner)->patch("/invoices/{$i->id}/discount", ['discount_amount' => 300, 'discount_reason' => 'Scholarship'])->assertSessionHasNoErrors();
        $this->assertEquals(300, $i->fresh()->discount_amount);
        $log = AuditLog::where('action', 'invoice.discount_changed')->latest('id')->first();
        $this->assertSame('Scholarship', $log->note);
        $this->assertSame($this->owner->id, $log->user_id);

        $admin = $this->makeUser('branch_admin', $this->branchA->id);
        $this->actingAs($admin)->patch("/invoices/{$i->id}/discount", ['discount_amount' => 400, 'discount_reason' => 'Staff child'])->assertSessionHasNoErrors();
    }

    public function test_the_school_can_change_its_limits(): void
    {
        $i = $this->invoice(1000);
        $clerk = $this->makeUser('accountant', $this->branchA->id);
        $this->school->update(['discount_limit_percent' => 25]);
        $this->actingAs($clerk)->patch("/invoices/{$i->id}/discount", ['discount_amount' => 250, 'discount_reason' => 'x'])->assertSessionHasNoErrors();
        $this->school->update(['discount_limit_percent' => 0]);
        $this->actingAs($clerk)->patch("/invoices/{$i->id}/discount", ['discount_amount' => 1, 'discount_reason' => 'x'])->assertSessionHasErrors('discount_amount');
    }

    public function test_waivers_need_a_reason_and_large_ones_an_approver(): void
    {
        $small = $this->invoice(800);
        $large = $this->invoice(50000, $this->studentB);
        $clerk = $this->makeUser('accountant', null);

        $this->actingAs($clerk)->post("/invoices/{$small->id}/toggle-waive", [])->assertSessionHasErrors('reason');
        $this->actingAs($clerk)->post("/invoices/{$small->id}/toggle-waive", ['reason' => 'Orphan'])->assertSessionHasNoErrors();
        $s = $small->fresh();
        $this->assertTrue($s->is_waived);
        $this->assertSame('Orphan', $s->waiver_reason);
        $this->assertSame($clerk->id, $s->waived_by);
        $this->assertNotNull($s->waived_at);

        $this->actingAs($clerk)->post("/invoices/{$large->id}/toggle-waive", ['reason' => 'Because'])->assertSessionHasErrors('reason');
        $this->assertFalse($large->fresh()->is_waived);

        // the limit is on what is UNPAID: with most of it already paid, a clerk can waive the rest
        $this->pay($large, 49500);
        $this->actingAs($clerk)->post("/invoices/{$large->id}/toggle-waive", ['reason' => 'Rest forgiven'])->assertSessionHasNoErrors();
        $this->assertTrue($large->fresh()->is_waived);

        $another = $this->invoice(50000, $this->studentB);
        $this->actingAs($this->owner)->post("/invoices/{$another->id}/toggle-waive", ['reason' => 'Board decision'])->assertSessionHasNoErrors();
        $log = AuditLog::where('action', 'invoice.waived')->latest('id')->first();
        $this->assertSame('Board decision', $log->note);
        $this->assertSame($this->owner->id, $log->user_id);

        // removing a waiver is allowed to invoice staff and restores the charge
        $this->actingAs($clerk)->post("/invoices/{$another->id}/toggle-waive")->assertSessionHasNoErrors();
        $this->assertEquals(50000, $another->fresh()->balance());
        $this->assertNull($another->fresh()->waiver_reason);
    }

    public function test_staff_without_manage_invoices_cannot_discount_or_waive_and_parents_never(): void
    {
        $i = $this->invoice(1000);
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->actingAs($teacher)->patch("/invoices/{$i->id}/discount", ['discount_amount' => 1, 'discount_reason' => 'x'])->assertForbidden();
        $this->actingAs($teacher)->post("/invoices/{$i->id}/toggle-waive", ['reason' => 'x'])->assertForbidden();
        $parent = $this->makeParent($this->studentA);
        $this->actingAs($parent)->patch("/invoices/{$i->id}/discount", ['discount_amount' => 1, 'discount_reason' => 'x'])->assertStatus(403);
    }

    public function test_withdrawal_waivers_are_attributed_to_the_person_withdrawing(): void
    {
        $i = $this->invoice(1000);
        $i->update(['period_start' => '2027-05-01']);
        $this->actingAs($this->owner)->post("/students/{$this->studentA->id}/withdraw", ['effective_date' => '2026-10-01', 'reason' => 'Moved'])->assertSessionHasNoErrors();
        $w = $i->fresh();
        $this->assertTrue($w->is_waived);
        $this->assertSame($this->owner->id, $w->waived_by);
        $this->assertStringContainsString('withdrawn', $w->waiver_reason);
    }
}
