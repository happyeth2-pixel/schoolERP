<?php

namespace App\Http\Controllers;

use App\Models\InventoryStore;
use App\Models\NumberSequence;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    /** Purchase orders this user may see: everything in their branch (or every branch, if org-wide) --
     * same shape as PurchaseRequestController's own visibility rule, just for the one permission that
     * covers viewing and managing orders alike (mirrors quotation-manage). */
    private function visiblePurchaseOrders(User $user)
    {
        $query = PurchaseOrder::where('school_id', $user->school_id);

        if ($user->isOrgWide()) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $user->branch_id));
    }

    /** Same shape as StockMovementController's own visibleStores -- a school-wide store is visible to
     * everyone, a branch's own store only to that branch (or an org-wide account). */
    private function visibleStores(User $user)
    {
        return InventoryStore::where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)))
            ->get();
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canViewOrManage('purchase-order-manage'), 403);

        $orders = $this->visiblePurchaseOrders($user)
            ->with(['supplier:id,name', 'items'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'number' => $po->number,
                'supplier' => $po->supplier->name,
                'status' => $po->status,
                'order_date' => $po->order_date->toDateString(),
                'total' => $po->total(),
            ]);

        return Inertia::render('PurchaseOrders/Index', [
            'orders' => $orders,
            'status' => $request->query('status', ''),
            'statuses' => PurchaseOrder::STATUSES,
        ]);
    }

    private function nextNumber(int $schoolId): string
    {
        $year = (int) date('Y');
        $prefix = "PO-{$year}-";

        $n = NumberSequence::next($schoolId, 'purchase_order', $year, fn () => PurchaseOrder::where('school_id', $schoolId)
            ->where('number', 'like', "{$prefix}%")
            ->pluck('number')
            ->map(fn ($x) => (int) substr($x, strlen($prefix)))
            ->max() ?? 0);

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    private function authorizeOnRequest(Request $request, PurchaseRequest $purchaseRequest): void
    {
        $user = $request->user();
        abort_unless($user->can('purchase-order-manage'), 403);
        abort_unless($purchaseRequest->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $purchaseRequest->branch_id === null || $purchaseRequest->branch_id === $user->branch_id, 403);
        abort_unless($purchaseRequest->status === 'approved', 422);
    }

    public function store(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorizeOnRequest($request, $purchaseRequest);
        $user = $request->user();

        $validated = $request->validate([
            'quotation_id' => ['nullable', 'integer', Rule::exists('quotations', 'id')->where('purchase_request_id', $purchaseRequest->id)],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('school_id', $user->school_id)],
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'delivery_info' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_request_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $requestItems = $purchaseRequest->items->keyBy('id');

        DB::transaction(function () use ($purchaseRequest, $user, $validated, $requestItems) {
            $purchaseOrder = PurchaseOrder::create([
                'school_id' => $user->school_id,
                'branch_id' => $purchaseRequest->branch_id,
                'purchase_request_id' => $purchaseRequest->id,
                'quotation_id' => $validated['quotation_id'] ?? null,
                'supplier_id' => $validated['supplier_id'],
                'number' => $this->nextNumber($user->school_id),
                'status' => 'draft',
                'order_date' => $validated['order_date'],
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'delivery_info' => $validated['delivery_info'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($validated['items'] as $index => $line) {
                /** @var \App\Models\PurchaseRequestItem|null $requestItem */
                $requestItem = $requestItems->get($line['purchase_request_item_id']);
                if (! $requestItem) {
                    continue;
                }

                $remaining = $requestItem->quantity - $requestItem->orderedQuantity();
                if ($line['quantity'] > $remaining) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => "Only {$remaining} of \"{$requestItem->description}\" is still unordered.",
                    ]);
                }

                $tax = $line['tax_amount'] ?? 0;
                $purchaseOrder->items()->create([
                    'purchase_request_item_id' => $requestItem->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'tax_amount' => $tax,
                    'total' => round($line['quantity'] * $line['unit_price'] + $tax, 2),
                ]);
            }
        });

        return back()->with('success', 'Purchase order created as a draft.');
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $user = $request->user();
        abort_unless($purchaseOrder->school_id === $user->school_id, 403);
        abort_unless($user->canViewOrManage('purchase-order-manage'), 403);
        abort_unless($user->isOrgWide() || $purchaseOrder->branch_id === null || $purchaseOrder->branch_id === $user->branch_id, 403);

        $purchaseOrder->load([
            'supplier:id,name,phone,email', 'purchaseRequest:id,number,purpose', 'createdBy:id,name',
            'items.purchaseRequestItem:id,description', 'goodsReceipts.store:id,name', 'goodsReceipts.items', 'goodsReceipts.recordedBy:id,name',
        ]);
        $canManageGoodsReceipts = $user->can('goods-receipt-manage') && in_array($purchaseOrder->status, ['sent', 'partially_received'], true);

        return Inertia::render('PurchaseOrders/Show', [
            'order' => [
                'id' => $purchaseOrder->id,
                'number' => $purchaseOrder->number,
                'status' => $purchaseOrder->status,
                'supplier' => $purchaseOrder->supplier->name,
                'supplier_phone' => $purchaseOrder->supplier->phone,
                'supplier_email' => $purchaseOrder->supplier->email,
                'purchase_request' => ['id' => $purchaseOrder->purchaseRequest->id, 'number' => $purchaseOrder->purchaseRequest->number, 'purpose' => $purchaseOrder->purchaseRequest->purpose],
                'order_date' => $purchaseOrder->order_date->toDateString(),
                'expected_delivery_date' => $purchaseOrder->expected_delivery_date?->toDateString(),
                'delivery_info' => $purchaseOrder->delivery_info,
                'notes' => $purchaseOrder->notes,
                'created_by' => $purchaseOrder->createdBy->name,
                'created_at' => $purchaseOrder->created_at->toDateTimeString(),
                'cancellation_reason' => $purchaseOrder->cancellation_reason,
                'total' => $purchaseOrder->total(),
                'items' => $purchaseOrder->items->map(fn ($i) => [
                    'id' => $i->id,
                    'description' => $i->purchaseRequestItem->description,
                    'quantity' => $i->quantity,
                    'unit_price' => $i->unit_price,
                    'tax_amount' => $i->tax_amount,
                    'total' => $i->total,
                    'received_quantity' => $i->receivedQuantity(),
                    'remaining_to_receive' => $i->quantity - $i->receivedQuantity(),
                ]),
            ],
            'canManage' => $user->can('purchase-order-manage'),
            'canManageGoodsReceipts' => $canManageGoodsReceipts,
            'goodsReceipts' => $purchaseOrder->goodsReceipts->map(fn ($gr) => [
                'id' => $gr->id,
                'number' => $gr->number,
                'store_name' => $gr->store->name,
                'received_date' => $gr->received_date->toDateString(),
                'recorded_by' => $gr->recordedBy->name,
                'notes' => $gr->notes,
                'items' => $gr->items->map(fn ($i) => ['purchase_order_item_id' => $i->purchase_order_item_id, 'quantity_received' => $i->quantity_received]),
            ]),
            'stores' => $canManageGoodsReceipts ? $this->visibleStores($user)->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values() : [],
        ]);
    }

    public function send(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $user = $request->user();
        abort_unless($purchaseOrder->school_id === $user->school_id, 403);
        abort_unless($user->can('purchase-order-manage'), 403);
        abort_unless($user->isOrgWide() || $purchaseOrder->branch_id === null || $purchaseOrder->branch_id === $user->branch_id, 403);
        abort_unless($purchaseOrder->status === 'draft', 422);

        $purchaseOrder->update(['status' => 'sent', 'sent_at' => now()]);

        return back()->with('success', "Purchase order {$purchaseOrder->number} marked as sent.");
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $user = $request->user();
        abort_unless($purchaseOrder->school_id === $user->school_id, 403);
        abort_unless($user->can('purchase-order-manage'), 403);
        abort_unless($user->isOrgWide() || $purchaseOrder->branch_id === null || $purchaseOrder->branch_id === $user->branch_id, 403);
        abort_unless(in_array($purchaseOrder->status, ['draft', 'sent', 'partially_received'], true), 422);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $purchaseOrder->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => $validated['reason']]);

        return back()->with('success', "Purchase order {$purchaseOrder->number} cancelled.");
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $user = $request->user();
        abort_unless($purchaseOrder->school_id === $user->school_id, 403);
        abort_unless($user->can('purchase-order-manage'), 403);
        abort_unless($purchaseOrder->status === 'draft', 422);

        $purchaseOrder->delete();

        return redirect()->route('purchase-orders.index')->with('success', 'Draft purchase order removed.');
    }
}
