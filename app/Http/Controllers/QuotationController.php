<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Recording and comparing supplier quotations against an APPROVED purchase request. `selected` is only
 * ever set by an explicit human action here -- nothing in this controller ever picks one automatically,
 * per the brief's own instruction.
 */
class QuotationController extends Controller
{
    private function authorizeOnRequest(Request $request, PurchaseRequest $purchaseRequest): void
    {
        $user = $request->user();
        abort_unless($user->can('quotation-manage'), 403);
        abort_unless($purchaseRequest->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $purchaseRequest->branch_id === null || $purchaseRequest->branch_id === $user->branch_id, 403);
        abort_unless($purchaseRequest->status === 'approved', 422);
    }

    public function store(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorizeOnRequest($request, $purchaseRequest);
        $user = $request->user();

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('school_id', $user->school_id)],
            'reference' => ['nullable', 'string', 'max:255'],
            'quotation_date' => ['required', 'date'],
            'validity_date' => ['nullable', 'date', 'after_or_equal:quotation_date'],
            'delivery_info' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_request_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $requestItemIds = $purchaseRequest->items()->pluck('id');

        DB::transaction(function () use ($purchaseRequest, $user, $validated, $requestItemIds) {
            $quotation = Quotation::create([
                'school_id' => $user->school_id,
                'purchase_request_id' => $purchaseRequest->id,
                'supplier_id' => $validated['supplier_id'],
                'reference' => $validated['reference'] ?? null,
                'quotation_date' => $validated['quotation_date'],
                'validity_date' => $validated['validity_date'] ?? null,
                'delivery_info' => $validated['delivery_info'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'recorded_by' => $user->id,
            ]);

            foreach ($validated['items'] as $line) {
                if (! $requestItemIds->contains($line['purchase_request_item_id'])) {
                    continue; // not a line of THIS request -- silently ignored, never trusted from the client
                }
                $tax = $line['tax_amount'] ?? 0;
                $quotation->items()->create([
                    'purchase_request_item_id' => $line['purchase_request_item_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'tax_amount' => $tax,
                    'total' => round($line['quantity'] * $line['unit_price'] + $tax, 2),
                ]);
            }
        });

        return back()->with('success', 'Quotation recorded.');
    }

    public function select(Request $request, Quotation $quotation): RedirectResponse
    {
        $purchaseRequest = $quotation->purchaseRequest;
        $this->authorizeOnRequest($request, $purchaseRequest);

        DB::transaction(function () use ($quotation, $purchaseRequest) {
            Quotation::where('purchase_request_id', $purchaseRequest->id)->where('id', '!=', $quotation->id)->update(['selected' => false]);
            $quotation->update(['selected' => true]);
        });

        return back()->with('success', "The quotation from {$quotation->supplier->name} is now selected.");
    }

    public function destroy(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->authorizeOnRequest($request, $quotation->purchaseRequest);
        abort_if($quotation->selected, 422, 'The selected quotation cannot be removed -- select a different one first.');

        $quotation->delete();

        return back()->with('success', 'Quotation removed.');
    }
}
