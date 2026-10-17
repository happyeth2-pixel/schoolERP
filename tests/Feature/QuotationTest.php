<?php

namespace Tests\Feature;

use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function approvedRequest(): PurchaseRequest
    {
        $requester = $this->makeUser('teacher', null, ['purchase-request-create']);
        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'Library books', 'quantity' => 10, 'estimated_unit_price' => 100]],
        ]);
        $pr = PurchaseRequest::firstOrFail();
        $this->actingAs($this->owner)->post("/purchase-requests/{$pr->id}/approve");

        return $pr->fresh();
    }

    private function supplier(string $name = 'ABC Stationery'): Supplier
    {
        return Supplier::create(['school_id' => $this->school->id, 'code' => 'SUP-'.uniqid(), 'name' => $name]);
    }

    public function test_a_quotation_can_only_be_recorded_against_an_approved_request(): void
    {
        $requester = $this->makeUser('teacher', null, ['purchase-request-create']);
        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Books', 'priority' => 'normal', 'action' => 'draft',
            'items' => [['description' => 'Books', 'quantity' => 1, 'estimated_unit_price' => 10]],
        ]);
        $draft = PurchaseRequest::firstOrFail();
        $line = $draft->items->first();
        $supplier = $this->supplier();
        $manager = $this->makeUser('branch_admin', null, ['quotation-manage']);

        $this->actingAs($manager)->post("/purchase-requests/{$draft->id}/quotations", [
            'supplier_id' => $supplier->id, 'quotation_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertStatus(422);
        $this->assertSame(0, Quotation::count());
    }

    public function test_recording_a_quotation_computes_line_and_grand_totals(): void
    {
        $pr = $this->approvedRequest();
        $line = $pr->items->first();
        $supplier = $this->supplier();
        $manager = $this->makeUser('branch_admin', null, ['quotation-manage']);

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/quotations", [
            'supplier_id' => $supplier->id, 'reference' => 'Q-100', 'quotation_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 10, 'unit_price' => 95, 'tax_amount' => 50]],
        ])->assertSessionHasNoErrors();

        $quotation = Quotation::firstOrFail();
        $this->assertSame($supplier->id, $quotation->supplier_id);
        $this->assertSame(1000.0, $quotation->total(), '10 * 95 + 50 = 1000');
        $this->assertFalse($quotation->selected);
    }

    public function test_two_quotations_can_be_compared_and_selecting_one_deselects_the_other(): void
    {
        $pr = $this->approvedRequest();
        $line = $pr->items->first();
        $supplierA = $this->supplier('Supplier A');
        $supplierB = $this->supplier('Supplier B');
        $manager = $this->makeUser('branch_admin', null, ['quotation-manage']);

        $post = fn ($supplier, $price) => $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/quotations", [
            'supplier_id' => $supplier->id, 'quotation_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 10, 'unit_price' => $price]],
        ]);
        $post($supplierA, 100);
        $post($supplierB, 90);
        $this->assertCount(2, Quotation::all());

        $quoteA = Quotation::where('supplier_id', $supplierA->id)->firstOrFail();
        $quoteB = Quotation::where('supplier_id', $supplierB->id)->firstOrFail();

        $this->actingAs($manager)->post("/quotations/{$quoteA->id}/select")->assertSessionHasNoErrors();
        $this->assertTrue($quoteA->fresh()->selected);
        $this->assertFalse($quoteB->fresh()->selected);

        // choosing the cheaper one instead automatically deselects the first -- never both selected at once
        $this->actingAs($manager)->post("/quotations/{$quoteB->id}/select");
        $this->assertFalse($quoteA->fresh()->selected);
        $this->assertTrue($quoteB->fresh()->selected);
    }

    public function test_a_selected_quotation_cannot_be_removed(): void
    {
        $pr = $this->approvedRequest();
        $line = $pr->items->first();
        $supplier = $this->supplier();
        $manager = $this->makeUser('branch_admin', null, ['quotation-manage']);

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/quotations", [
            'supplier_id' => $supplier->id, 'quotation_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);
        $quotation = Quotation::firstOrFail();
        $this->actingAs($manager)->post("/quotations/{$quotation->id}/select");

        $this->actingAs($manager)->delete("/quotations/{$quotation->id}")->assertStatus(422);
        $this->assertNotNull(Quotation::find($quotation->id));
    }

    public function test_only_quotation_manage_can_record_or_select_a_quotation(): void
    {
        $pr = $this->approvedRequest();
        $line = $pr->items->first();
        $supplier = $this->supplier();
        $bystander = $this->makeUser('teacher', null, []);

        $this->actingAs($bystander)->post("/purchase-requests/{$pr->id}/quotations", [
            'supplier_id' => $supplier->id, 'quotation_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertForbidden();
    }

    public function test_a_branch_admin_cannot_record_a_quotation_for_another_branchs_request(): void
    {
        $requesterB = $this->makeUser('teacher', $this->branchB->id, ['purchase-request-create']);
        $this->actingAs($requesterB)->post('/purchase-requests', [
            'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'Books', 'quantity' => 1, 'estimated_unit_price' => 10]],
        ]);
        $pr = PurchaseRequest::firstOrFail();
        $this->actingAs($this->owner)->post("/purchase-requests/{$pr->id}/approve");
        $line = $pr->fresh()->items->first();
        $supplier = $this->supplier();

        $adminA = $this->makeUser('branch_admin', $this->branchA->id, ['quotation-manage']);
        $this->actingAs($adminA)->post("/purchase-requests/{$pr->id}/quotations", [
            'supplier_id' => $supplier->id, 'quotation_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertForbidden();
    }

    public function test_recording_a_quotation_is_in_the_audit_trail(): void
    {
        $pr = $this->approvedRequest();
        $line = $pr->items->first();
        $supplier = $this->supplier();
        $manager = $this->makeUser('branch_admin', null, ['quotation-manage']);

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/quotations", [
            'supplier_id' => $supplier->id, 'quotation_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 1, 'unit_price' => 10]],
        ]);

        $this->assertNotNull(\App\Models\AuditLog::where('action', 'quotation.created')->first());

        $quotation = Quotation::firstOrFail();
        $this->actingAs($manager)->post("/quotations/{$quotation->id}/select");
        $this->assertNotNull(\App\Models\AuditLog::where('action', 'quotation.selected')->first());
    }
}
