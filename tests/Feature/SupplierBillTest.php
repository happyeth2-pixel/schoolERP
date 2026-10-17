<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\InventoryStore;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\ChartOfAccountsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** Accounting Phase C: the payable side. SupplierBill/SupplierPayment are new models (Procurement's own
 * briefs deliberately deferred a vendor-bill concept to this phase). These tests cover both the business
 * rules (the three-way match, branch isolation, authorization, immutability) and the accounting postings,
 * following the exact same "never break the underlying workflow" principle as Phase B. */
class SupplierBillTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function installAccounting(): void
    {
        ChartOfAccountsTemplate::install($this->school);
        AccountingPeriod::create(['school_id' => $this->school->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
    }

    private function account(string $code): Account
    {
        return Account::where('school_id', $this->school->id)->where('code', $code)->firstOrFail();
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['school_id' => $this->school->id, 'code' => 'SUP-'.uniqid(), 'name' => 'ABC Stationery']);
    }

    private function manager(?int $branchId = null): User
    {
        return $this->makeUser('branch_admin', $branchId, ['purchase-order-manage', 'goods-receipt-manage', 'supplier-bill-manage', 'supplier-payment-manage']);
    }

    /** A fully-received purchase order with one line, quantity 10 @ 100 ETB, no catalog item (free-text --
     * the three-way match only cares about quantity, not inventory valuation). Returns [$order, $orderItem]. */
    private function receivedOrder(?User $manager = null, int $quantity = 10): array
    {
        $manager ??= $this->manager();
        $requester = $this->makeUser('teacher', $manager->branch_id, ['purchase-request-create']);
        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Supplies', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'Office supplies', 'quantity' => $quantity, 'estimated_unit_price' => 100]],
        ]);
        $pr = PurchaseRequest::firstOrFail();
        $this->actingAs($this->owner)->post("/purchase-requests/{$pr->id}/approve");
        $line = $pr->fresh()->items->first();

        $supplier = $this->supplier();
        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => $quantity, 'unit_price' => 100]],
        ]);
        $order = PurchaseOrder::firstOrFail();
        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/send");

        $store = InventoryStore::create(['school_id' => $this->school->id, 'name' => 'Main Store']);
        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $order->items->first()->id, 'quantity_received' => $quantity]],
        ]);

        return [$order->fresh(), $order->items->first()];
    }

    // ---------------------------------------------------------------- the three-way match

    public function test_a_bill_line_cannot_exceed_what_was_actually_received(): void
    {
        $manager = $this->manager();
        [$order, $orderItem] = $this->receivedOrder($manager, 10);

        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'Office supplies', 'quantity' => 11, 'unit_price' => 100]],
        ])->assertSessionHasErrors('items.0.quantity');
        $this->assertSame(0, SupplierBill::count());
    }

    public function test_a_received_line_can_be_billed_across_two_bills_but_never_over_billed(): void
    {
        $manager = $this->manager();
        [$order, $orderItem] = $this->receivedOrder($manager, 10);

        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'Office supplies', 'quantity' => 6, 'unit_price' => 100]],
        ])->assertSessionHasNoErrors();

        // 6 already billed, only 4 left
        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'Office supplies', 'quantity' => 5, 'unit_price' => 100]],
        ])->assertSessionHasErrors('items.0.quantity');
        $this->assertSame(1, SupplierBill::count());

        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'Office supplies', 'quantity' => 4, 'unit_price' => 100]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, SupplierBill::count());
    }

    public function test_a_free_form_bill_with_no_purchase_order_is_accepted(): void
    {
        $manager = $this->manager();
        $supplier = $this->supplier();

        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $supplier->id, 'bill_date' => now()->toDateString(),
            'items' => [['description' => 'Ad hoc repair', 'quantity' => 1, 'unit_price' => 500]],
        ])->assertSessionHasNoErrors();

        $bill = SupplierBill::firstOrFail();
        $this->assertNull($bill->purchase_order_id);
        $this->assertSame(500.0, $bill->total());
    }

    // ---------------------------------------------------------------- accounting postings

    public function test_recording_a_bill_posts_expense_and_accounts_payable_matching_the_brief(): void
    {
        $this->installAccounting();
        $manager = $this->manager();
        [$order, $orderItem] = $this->receivedOrder($manager, 10);

        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'expense_account_id' => $this->account('5900')->id, 'description' => 'Office supplies', 'quantity' => 10, 'unit_price' => 2000]],
        ]);

        $bill = SupplierBill::firstOrFail();
        $this->assertSame(20000.0, $bill->total());

        $entry = $bill->journalEntries()->firstOrFail();
        $this->assertSame('posted', $entry->status);
        $expenseLine = $entry->lines->firstWhere('account_id', $this->account('5900')->id);
        $apLine = $entry->lines->firstWhere('account_id', $this->account('2100')->id);
        $this->assertSame(20000.0, (float) $expenseLine->debit);
        $this->assertSame(20000.0, (float) $apLine->credit);
    }

    public function test_paying_a_bill_posts_accounts_payable_and_cash_matching_the_brief(): void
    {
        $this->installAccounting();
        $manager = $this->manager();
        [$order, $orderItem] = $this->receivedOrder($manager, 1);

        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'Office supplies', 'quantity' => 1, 'unit_price' => 20000]],
        ]);
        $bill = SupplierBill::firstOrFail();

        $this->actingAs($manager)->post("/supplier-bills/{$bill->id}/payments", [
            'amount' => 20000, 'method' => 'bank_transfer', 'paid_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $payment = SupplierPayment::firstOrFail();
        $entry = $payment->journalEntries()->firstOrFail();
        $apLine = $entry->lines->firstWhere('account_id', $this->account('2100')->id);
        // the method only picks which JOURNAL this posts to (BANK here); the account is still the
        // school's single default_cash_account_id until a per-payment account is explicitly chosen
        $cashLine = $entry->lines->firstWhere('account_id', $this->account('1150')->id);
        $this->assertSame(20000.0, (float) $apLine->debit);
        $this->assertSame(20000.0, (float) $cashLine->credit);
        $this->assertSame(0.0, $bill->fresh()->balance());
    }

    public function test_a_payment_cannot_exceed_the_bills_balance(): void
    {
        $this->installAccounting();
        $manager = $this->manager();
        [$order, $orderItem] = $this->receivedOrder($manager, 1);
        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'Office supplies', 'quantity' => 1, 'unit_price' => 1000]],
        ]);
        $bill = SupplierBill::firstOrFail();

        $this->actingAs($manager)->post("/supplier-bills/{$bill->id}/payments", [
            'amount' => 1500, 'method' => 'cash', 'paid_date' => now()->toDateString(),
        ])->assertSessionHasErrors('amount');
        $this->assertSame(0, SupplierPayment::count());
    }

    public function test_without_a_chart_of_accounts_billing_and_paying_still_works_and_nothing_posts(): void
    {
        $manager = $this->manager();
        [$order, $orderItem] = $this->receivedOrder($manager, 1);

        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'Office supplies', 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertSessionHasNoErrors();
        $bill = SupplierBill::firstOrFail();
        $this->assertSame(0, JournalEntry::count());

        $this->actingAs($manager)->post("/supplier-bills/{$bill->id}/payments", [
            'amount' => 1000, 'method' => 'cash', 'paid_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $bill->fresh()->balance());
        $this->assertSame(0, JournalEntry::count());
    }

    // ---------------------------------------------------------------- reversing a payment (§5 hardening)

    public function test_reversing_a_payment_puts_the_balance_back_up_and_is_capped_at_what_was_paid(): void
    {
        $this->installAccounting();
        $manager = $this->manager();
        [$order, $orderItem] = $this->receivedOrder($manager, 1);
        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'x', 'quantity' => 1, 'unit_price' => 1000]],
        ]);
        $bill = SupplierBill::firstOrFail();
        $this->actingAs($manager)->post("/supplier-bills/{$bill->id}/payments", ['amount' => 1000, 'method' => 'cash', 'paid_date' => now()->toDateString()]);
        $payment = SupplierPayment::firstOrFail();
        $this->assertSame(0.0, $bill->fresh()->balance());

        // trying to reverse more than was paid is refused and changes nothing
        $this->actingAs($manager)->post("/supplier-payments/{$payment->id}/reverse", [
            'amount' => 1001, 'kind' => 'reversal', 'reason' => 'Sent twice by mistake',
        ])->assertSessionHasErrors('amount');
        $this->assertSame(0, \App\Models\SupplierPaymentReversal::count());

        // a partial reversal is fine, and the bill balance reflects it immediately
        $this->actingAs($manager)->post("/supplier-payments/{$payment->id}/reverse", [
            'amount' => 400, 'kind' => 'reversal', 'reason' => 'Overpaid by mistake',
        ])->assertSessionHasNoErrors();
        $this->assertSame(400.0, $bill->fresh()->balance());
        $this->assertSame(600.0, $payment->fresh()->netAmount());

        // a second reversal can only take the remaining 600, never more
        $this->actingAs($manager)->post("/supplier-payments/{$payment->id}/reverse", [
            'amount' => 601, 'kind' => 'refund', 'reason' => 'Trying to over-reverse',
        ])->assertSessionHasErrors('amount');

        $this->actingAs($manager)->post("/supplier-payments/{$payment->id}/reverse", [
            'amount' => 600, 'kind' => 'refund', 'reason' => 'Rest returned by the supplier',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1000.0, $bill->fresh()->balance(), 'fully reversed means fully unpaid again');
        $this->assertSame(0.0, $payment->fresh()->netAmount());
    }

    public function test_reversing_a_payment_posts_the_opposite_entry_of_recording_it(): void
    {
        $this->installAccounting();
        $manager = $this->manager();
        [$order, $orderItem] = $this->receivedOrder($manager, 1);
        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'x', 'quantity' => 1, 'unit_price' => 1000]],
        ]);
        $bill = SupplierBill::firstOrFail();
        $this->actingAs($manager)->post("/supplier-bills/{$bill->id}/payments", ['amount' => 1000, 'method' => 'cash', 'paid_date' => now()->toDateString()]);
        $payment = SupplierPayment::firstOrFail();

        $this->actingAs($manager)->post("/supplier-payments/{$payment->id}/reverse", [
            'amount' => 1000, 'kind' => 'reversal', 'reason' => 'Wrong bill entirely',
        ]);

        $reversal = \App\Models\SupplierPaymentReversal::firstOrFail();
        $entry = $reversal->journalEntries()->firstOrFail();
        $apLine = $entry->lines->firstWhere('account_id', $this->account('2100')->id);
        $cashLine = $entry->lines->firstWhere('account_id', $this->account('1150')->id);
        $this->assertSame(1000.0, (float) $apLine->credit, 'the reversal re-raises the payable, the opposite of recording the payment');
        $this->assertSame(1000.0, (float) $cashLine->debit);
    }

    public function test_a_supplier_payment_reversal_can_never_be_changed(): void
    {
        $manager = $this->manager();
        [$order, $orderItem] = $this->receivedOrder($manager, 1);
        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'x', 'quantity' => 1, 'unit_price' => 1000]],
        ]);
        $bill = SupplierBill::firstOrFail();
        $this->actingAs($manager)->post("/supplier-bills/{$bill->id}/payments", ['amount' => 1000, 'method' => 'cash', 'paid_date' => now()->toDateString()]);
        $payment = SupplierPayment::firstOrFail();
        $this->actingAs($manager)->post("/supplier-payments/{$payment->id}/reverse", ['amount' => 1000, 'kind' => 'reversal', 'reason' => 'test']);
        $reversal = \App\Models\SupplierPaymentReversal::firstOrFail();

        $this->expectException(\App\Exceptions\DeletionBlockedException::class);
        $reversal->update(['amount' => 1]);
    }

    public function test_only_supplier_payment_manage_can_reverse_and_branch_scope_is_checked(): void
    {
        $managerA = $this->manager($this->branchA->id);
        [$order, $orderItem] = $this->receivedOrder($managerA, 1);
        $this->actingAs($managerA)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'x', 'quantity' => 1, 'unit_price' => 1000]],
        ]);
        $bill = SupplierBill::firstOrFail();
        $this->actingAs($managerA)->post("/supplier-bills/{$bill->id}/payments", ['amount' => 1000, 'method' => 'cash', 'paid_date' => now()->toDateString()]);
        $payment = SupplierPayment::firstOrFail();

        $bystander = $this->makeUser('branch_admin', $this->branchA->id, ['supplier-bill-manage']);
        $this->actingAs($bystander)->post("/supplier-payments/{$payment->id}/reverse", ['amount' => 1000, 'kind' => 'reversal', 'reason' => 'test'])->assertForbidden();

        $managerB = $this->manager($this->branchB->id);
        $this->actingAs($managerB)->post("/supplier-payments/{$payment->id}/reverse", ['amount' => 1000, 'kind' => 'reversal', 'reason' => 'test'])->assertForbidden();

        $this->assertSame(0, \App\Models\SupplierPaymentReversal::count());
    }

    // ---------------------------------------------------------------- authorization & immutability

    public function test_only_supplier_bill_manage_can_record_a_bill(): void
    {
        [$order, $orderItem] = $this->receivedOrder();
        $bystander = $this->makeUser('teacher', null, []);

        $this->actingAs($bystander)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'x', 'quantity' => 1, 'unit_price' => 10]],
        ])->assertForbidden();
    }

    public function test_only_supplier_payment_manage_can_pay_a_bill(): void
    {
        $manager = $this->manager();
        [$order, $orderItem] = $this->receivedOrder($manager, 1);
        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'x', 'quantity' => 1, 'unit_price' => 10]],
        ]);
        $bill = SupplierBill::firstOrFail();

        $billOnly = $this->makeUser('branch_admin', null, ['supplier-bill-manage']);
        $this->actingAs($billOnly)->post("/supplier-bills/{$bill->id}/payments", [
            'amount' => 10, 'method' => 'cash', 'paid_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_a_branch_admin_cannot_bill_for_another_branchs_order(): void
    {
        $managerB = $this->manager($this->branchB->id);
        [$order, $orderItem] = $this->receivedOrder($managerB, 1);

        $managerA = $this->manager($this->branchA->id);
        $this->actingAs($managerA)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'x', 'quantity' => 1, 'unit_price' => 10]],
        ])->assertForbidden();
    }

    public function test_a_supplier_payment_can_never_be_changed_or_removed(): void
    {
        $manager = $this->manager();
        [$order, $orderItem] = $this->receivedOrder($manager, 1);
        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'x', 'quantity' => 1, 'unit_price' => 1000]],
        ]);
        $bill = SupplierBill::firstOrFail();
        $this->actingAs($manager)->post("/supplier-bills/{$bill->id}/payments", ['amount' => 1000, 'method' => 'cash', 'paid_date' => now()->toDateString()]);
        $payment = SupplierPayment::firstOrFail();

        $this->expectException(\App\Exceptions\DeletionBlockedException::class);
        $payment->update(['amount' => 1]);
    }

    public function test_recording_a_bill_and_paying_it_are_in_the_audit_trail(): void
    {
        $manager = $this->manager();
        [$order, $orderItem] = $this->receivedOrder($manager, 1);
        $this->actingAs($manager)->post('/supplier-bills', [
            'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'description' => 'x', 'quantity' => 1, 'unit_price' => 1000]],
        ]);
        $this->assertNotNull(\App\Models\AuditLog::where('action', 'supplier_bill.created')->first());

        $bill = SupplierBill::firstOrFail();
        $this->actingAs($manager)->post("/supplier-bills/{$bill->id}/payments", ['amount' => 1000, 'method' => 'cash', 'paid_date' => now()->toDateString()]);
        $this->assertNotNull(\App\Models\AuditLog::where('action', 'supplier_payment.created')->first());
    }
}
