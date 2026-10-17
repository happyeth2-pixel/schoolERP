<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentRejected;
use App\Notifications\PaymentVerified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The accountant's payment screens were redesigned (a queue, and one payment at a time: who, how much, for what,
 * how, and what to do next). The financial rules did not change, and these tests pin them: who may decide, that a
 * payment is decided once, that it can never overpay, that a rejection needs a reason, what a partial payment
 * leaves, that refunds are separate records, and that nobody sees another branch's payments.
 */
class PaymentWorkflowTest extends TestCase
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
        $this->accountant = $this->makeUser('accountant', $this->branchA->id, ['verify-payments']);
        $this->parent = $this->makeParent($this->studentA);
    }

    private function invoice(float $amount = 1000, $student = null): Invoice
    {
        $i = Invoice::create(['student_id' => ($student ?? $this->studentA)->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-'.(++$this->n), 'total_amount' => $amount, 'status' => 'pending', 'frequency' => 'annual', 'period_label' => 'Term 1', 'period_start' => now()->addDays($this->n)->toDateString()]);
        $i->items()->create(['description' => 'Tuition', 'amount' => $amount, 'item_type' => 'fee']);

        return $i->fresh();
    }

    private function pending(Invoice $i, float $amount, array $extra = [], ?User $by = null): Payment
    {
        return Payment::create($extra + ['invoice_id' => $i->id, 'amount' => $amount, 'method' => 'bank_transfer', 'reference_number' => 'REF'.(++$this->n), 'proof_path' => 'payment-proofs/p'.$this->n.'.png', 'status' => 'pending', 'submitted_by' => ($by ?? $this->parent)->id]);
    }

    /** The page props as plain arrays (Inertia may hand back collections). */
    private function review(Payment $p, ?User $as = null): array
    {
        return json_decode(json_encode($this->props($this->actingAs($as ?? $this->accountant)->get("/payments/{$p->id}")->assertOk())), true);
    }

    // ------------------------------------------------------------------ the page answers the five questions

    public function test_the_review_page_says_who_paid_how_much_for_what_and_how(): void
    {
        $inv = $this->invoice(1000);
        $p = $this->pending($inv, 400, ['reference_number' => 'FT123', 'method' => 'mobile_money']);

        $page = $this->review($p);

        $this->assertSame($this->parent->name, $page['payment']['submitted_by']);
        $this->assertSame('Mother', $page['payment']['submitted_by_relationship']);
        $this->assertSame($this->studentA->fullName(), $page['student']['name']);
        $this->assertSame('G1 A', $page['student']['class']);
        $this->assertEquals(400, $page['payment']['amount']);
        $this->assertSame('mobile_money', $page['payment']['method']);
        $this->assertSame('FT123', $page['payment']['reference_number']);
        $this->assertEquals([['description' => 'Tuition', 'amount' => 1000]], $page['invoice']['items']);
        $this->assertSame('Term 1', $page['invoice']['period_label']);
        $this->assertEquals(1000, $page['invoice']['balance']);
        $this->assertEquals(600, $page['invoice']['balance_after'], 'a partial payment says what would still be owed');
        $this->assertSame('image', $page['payment']['proof_kind']);
        $this->assertNull($page['payment']['receipt_url'], 'no receipt until it is verified');
        $this->assertNull($page['blocked']);
    }

    public function test_the_page_carries_no_contact_details_of_the_parent(): void
    {
        $this->parent->update(['phone' => '+251911223344']);
        $json = json_encode($this->review($this->pending($this->invoice(), 100)));

        $this->assertStringNotContainsString($this->parent->email, $json);
        $this->assertStringNotContainsString('911223344', $json);
    }

    // ------------------------------------------------------------------ payment received (verify)

    public function test_accepting_a_payment_records_it_issues_a_receipt_tells_the_parent_and_lands_on_its_page(): void
    {
        Notification::fake();
        $p = $this->pending($this->invoice(1000), 1000);

        $this->actingAs($this->accountant)->post("/payments/{$p->id}/verify")
            ->assertRedirect("/payments/{$p->id}")->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertSame('verified', $p->status);
        $this->assertMatchesRegularExpression('/^RCT-\d{4}-\d{6}$/', $p->receipt_number);
        $this->assertSame($this->accountant->id, $p->verified_by);
        Notification::assertSentTo($this->parent, PaymentVerified::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.verified']);

        $page = $this->review($p);
        $this->assertSame($p->receipt_number, $page['payment']['receipt_number']);
        $this->assertNotNull($page['payment']['receipt_url']);
        $this->assertSame($this->accountant->name, $page['payment']['verified_by']);
        $this->assertSame('paid', $page['invoice']['status']);
        $this->assertEquals(0, $page['invoice']['balance']);
    }

    public function test_a_partial_payment_leaves_the_invoice_partly_paid_and_the_rest_can_follow(): void
    {
        $inv = $this->invoice(1000);
        $first = $this->pending($inv, 300);
        $second = $this->pending($inv, 700);

        $this->actingAs($this->accountant)->post("/payments/{$first->id}/verify")->assertSessionHasNoErrors();
        $page = $this->review($second);
        $this->assertSame('partially_paid', $inv->fresh()->statusLabel());
        $this->assertEquals(700, $page['invoice']['balance']);
        $this->assertEquals(0, $page['invoice']['balance_after']);

        $this->actingAs($this->accountant)->post("/payments/{$second->id}/verify")->assertSessionHasNoErrors();
        $this->assertSame('paid', $inv->fresh()->statusLabel());
    }

    public function test_a_payment_is_decided_once_a_second_click_changes_nothing(): void
    {
        $p = $this->pending($this->invoice(1000), 500);
        $other = $this->makeUser('accountant', $this->branchA->id, ['verify-payments']);

        $this->actingAs($this->accountant)->post("/payments/{$p->id}/verify")->assertSessionHasNoErrors();
        $receipt = $p->fresh()->receipt_number;

        $this->actingAs($other)->post("/payments/{$p->id}/verify")->assertSessionHasErrors('payment');
        $this->actingAs($other)->post("/payments/{$p->id}/reject", ['rejection_reason' => 'Too late'])->assertSessionHasErrors('payment');

        $p->refresh();
        $this->assertSame(['verified', $receipt, $this->accountant->id], [$p->status, $p->receipt_number, $p->verified_by]);
        $this->assertSame(1, AuditLog::where('action', 'payment.verified')->count());
        $this->assertNull($p->rejection_reason);
    }

    public function test_a_payment_can_never_overpay_the_invoice_or_land_on_a_waived_one(): void
    {
        $inv = $this->invoice(1000);
        $ok = $this->pending($inv, 900);
        $tooMuch = $this->pending($inv, 300);
        $this->actingAs($this->accountant)->post("/payments/{$ok->id}/verify")->assertSessionHasNoErrors();

        $this->assertSame('over_balance', $this->review($tooMuch)['blocked'], 'the page says it cannot be accepted');
        $this->actingAs($this->accountant)->post("/payments/{$tooMuch->id}/verify")->assertSessionHasErrors('payment');
        $this->assertSame('pending', $tooMuch->fresh()->status);
        $this->assertNull($tooMuch->fresh()->receipt_number, 'a refused verification never uses up a receipt number');

        $waived = $this->invoice(500);
        $onWaived = $this->pending($waived, 100);
        $waived->update(['is_waived' => true]);
        $this->assertSame('waived', $this->review($onWaived)['blocked']);
        $this->actingAs($this->accountant)->post("/payments/{$onWaived->id}/verify")->assertSessionHasErrors('payment');

        $this->actingAs($this->accountant)->post("/payments/{$tooMuch->id}/reject", ['rejection_reason' => 'More than is owed'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $tooMuch->fresh()->status);
    }

    // ------------------------------------------------------------------ reject payment

    public function test_rejecting_needs_a_reason_records_it_and_tells_the_parent(): void
    {
        Notification::fake();
        $p = $this->pending($this->invoice(1000), 500);

        $this->actingAs($this->accountant)->post("/payments/{$p->id}/reject", [])->assertSessionHasErrors('rejection_reason');
        $this->actingAs($this->accountant)->post("/payments/{$p->id}/reject", ['rejection_reason' => '   '])->assertSessionHasErrors('rejection_reason');
        $this->actingAs($this->accountant)->post("/payments/{$p->id}/reject", ['rejection_reason' => str_repeat('x', 256)])->assertSessionHasErrors('rejection_reason');
        $this->assertSame('pending', $p->fresh()->status);

        $this->actingAs($this->accountant)->post("/payments/{$p->id}/reject", ['rejection_reason' => 'The photo is too blurry to read'])
            ->assertRedirect("/payments/{$p->id}")->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertSame(['rejected', 'The photo is too blurry to read', null], [$p->status, $p->rejection_reason, $p->receipt_number]);
        Notification::assertSentTo($this->parent, PaymentRejected::class);
        $this->assertSame('The photo is too blurry to read', AuditLog::where('action', 'payment.rejected')->first()->note);
        $this->assertSame('The photo is too blurry to read', $this->review($p)['payment']['rejection_reason']);
    }

    public function test_a_rejected_payment_cannot_then_be_accepted_and_a_rejection_never_counts_as_money(): void
    {
        $inv = $this->invoice(1000);
        $p = $this->pending($inv, 500);
        $this->actingAs($this->accountant)->post("/payments/{$p->id}/reject", ['rejection_reason' => 'Wrong account']);

        $this->actingAs($this->accountant)->post("/payments/{$p->id}/verify")->assertSessionHasErrors('payment');

        $this->assertSame('rejected', $p->fresh()->status);
        $this->assertSame('unpaid', $inv->fresh()->statusLabel());
    }

    // ------------------------------------------------------------------ duplicates (a heads-up, never a block)

    public function test_the_same_reference_or_the_same_amount_on_the_same_invoice_is_pointed_out(): void
    {
        $inv = $this->invoice(1000);
        $first = $this->pending($inv, 200, ['reference_number' => 'TX-777']);
        $sameRef = $this->pending($this->invoice(500), 500, ['reference_number' => ' tx-777 ']);   // another invoice, same transfer number
        $sameAmount = $this->pending($inv, 200, ['reference_number' => 'OTHER']);
        $unrelated = $this->pending($this->invoice(300), 300, ['reference_number' => 'NOPE']);

        $d = collect($this->review($first)['duplicates'])->keyBy('id');

        $this->assertSame('same_reference', $d[$sameRef->id]['why']);
        $this->assertSame('same_invoice_amount', $d[$sameAmount->id]['why']);
        $this->assertFalse($d->has($unrelated->id));
        $this->assertFalse($d->has($first->id), 'a payment is not its own duplicate');

        $this->actingAs($this->accountant)->post("/payments/{$first->id}/verify")->assertSessionHasNoErrors();   // a warning, not a block
    }

    public function test_duplicates_never_reach_across_branches(): void
    {
        $mine = $this->pending($this->invoice(1000), 100, ['reference_number' => 'SHARED']);
        $this->pending($this->invoice(1000, $this->studentB), 100, ['reference_number' => 'SHARED']);   // the East branch

        $this->assertSame([], $this->review($mine)['duplicates']);
    }

    // ------------------------------------------------------------------ the queue and "verify next"

    public function test_the_queue_is_oldest_first_and_each_row_links_to_its_page(): void
    {
        $old = $this->pending($this->invoice(), 100);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $new = $this->pending($this->invoice(), 100);

        $rows = json_decode(json_encode($this->props($this->actingAs($this->accountant)->get('/payments/pending')->assertOk())['payments']), true);

        $this->assertSame([$old->id, $new->id], array_column($rows, 'id'));
        $this->assertStringEndsWith("/payments/{$old->id}", $rows[0]['review_url']);
    }

    public function test_the_next_payment_is_the_oldest_other_one_in_my_branch_only(): void
    {
        $a = $this->pending($this->invoice(), 100);
        $a->forceFill(['created_at' => now()->subDays(3)])->save();
        $b = $this->pending($this->invoice(), 100);
        $b->forceFill(['created_at' => now()->subDays(1)])->save();
        $east = $this->pending($this->invoice(1000, $this->studentB), 100);
        $east->forceFill(['created_at' => now()->subDays(9)])->save();

        $this->assertSame($b->id, $this->review($a)['next']['id']);
        $this->assertSame($a->id, $this->review($b)['next']['id']);
        $this->actingAs($this->accountant)->post("/payments/{$b->id}/verify");
        $this->assertSame($a->id, $this->review($b)['next']['id']);
        $this->actingAs($this->accountant)->post("/payments/{$a->id}/verify");
        $this->assertNull($this->review($a)['next'], 'the East branch payment is never offered to a Main branch accountant');
        $this->assertSame(0, $this->review($a)['waiting']);
    }

    // ------------------------------------------------------------------ proof and receipt

    public function test_a_pdf_proof_is_reported_as_a_pdf_and_no_proof_as_none(): void
    {
        $pdf = $this->pending($this->invoice(), 100, ['proof_path' => 'payment-proofs/x.pdf']);
        $none = $this->pending($this->invoice(), 100, ['proof_path' => null]);

        $this->assertSame('pdf', $this->review($pdf)['payment']['proof_kind']);
        $this->assertNull($this->review($none)['payment']['proof_kind']);
        $this->assertFalse($this->review($none)['payment']['has_proof']);
    }

    public function test_the_receipt_exists_only_for_a_verified_payment(): void
    {
        $p = $this->pending($this->invoice(1000), 400);

        $this->actingAs($this->accountant)->get("/payments/{$p->id}/receipt")->assertNotFound();
        $this->actingAs($this->accountant)->post("/payments/{$p->id}/verify");
        $this->actingAs($this->accountant)->get("/payments/{$p->id}/receipt")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    // ------------------------------------------------------------------ refunds (unchanged rules, shown on the page)

    public function test_only_people_who_may_refund_see_the_refund_controls_and_the_rules_hold(): void
    {
        $p = $this->pending($this->invoice(1000), 600);
        $this->actingAs($this->accountant)->post("/payments/{$p->id}/verify");
        $refunder = $this->makeUser('accountant', $this->branchA->id, ['verify-payments', 'refund-payments']);

        $this->assertNull($this->review($p)['refund'], 'an accountant without refund-payments sees no refund controls');
        $this->actingAs($this->accountant)->post("/payments/{$p->id}/refund", ['amount' => 10, 'kind' => 'refund', 'reason' => 'test'])->assertForbidden();

        $this->assertEquals(600, $this->review($p, $refunder)['refund']['refundable']);
        $this->actingAs($refunder)->post("/payments/{$p->id}/refund", ['amount' => 700, 'kind' => 'refund', 'reason' => 'too much'])->assertSessionHasErrors('amount');
        $this->actingAs($refunder)->post("/payments/{$p->id}/refund", ['amount' => 100, 'kind' => 'refund', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($refunder)->post("/payments/{$p->id}/refund", ['amount' => 200, 'kind' => 'refund', 'reason' => 'Paid twice'])->assertSessionHasNoErrors();

        $page = $this->review($p, $refunder);
        $this->assertEquals(400, $page['refund']['refundable']);
        $this->assertEquals([200], array_column($page['refunds'], 'amount'));
        $this->assertSame('verified', $p->fresh()->status, 'the payment itself is never edited');
        $this->assertEquals(400, $page['invoice']['collected']);
        $this->assertEquals(600, $page['invoice']['balance']);
    }

    public function test_a_pending_payment_offers_no_refund(): void
    {
        $refunder = $this->makeUser('accountant', $this->branchA->id, ['verify-payments', 'refund-payments']);

        $this->assertNull($this->review($this->pending($this->invoice(), 100), $refunder)['refund']);
    }

    // ------------------------------------------------------------------ who may see or decide

    public function test_only_staff_with_verify_payments_in_the_right_branch_can_open_or_decide(): void
    {
        $p = $this->pending($this->invoice(1000), 100);
        $noPermission = $this->makeUser('accountant', $this->branchA->id, []);
        $east = $this->makeUser('accountant', $this->branchB->id, ['verify-payments']);
        $stranger = $this->makeParent($this->studentB);

        foreach ([$noPermission, $east, $this->parent, $stranger] as $u) {
            $this->actingAs($u)->get("/payments/{$p->id}")->assertForbidden();
            $this->actingAs($u)->post("/payments/{$p->id}/verify")->assertForbidden();
            $this->actingAs($u)->post("/payments/{$p->id}/reject", ['rejection_reason' => 'no'])->assertForbidden();
        }
        $this->actingAs($noPermission)->get('/payments/pending')->assertForbidden();
        $this->assertSame('pending', $p->fresh()->status);

        $this->actingAs($this->owner)->get("/payments/{$p->id}")->assertOk();   // org-wide
    }
}
