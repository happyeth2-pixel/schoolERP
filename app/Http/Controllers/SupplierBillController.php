<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\NumberSequence;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\AccountingPostings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The vendor invoice -- the piece Procurement's own briefs explicitly deferred to this accounting phase.
 * A bill's lines may optionally tie back to a purchase order's own lines (the three-way match: a line can
 * never be billed for more than it actually received), or stand alone for a purchase that never went
 * through a formal PO. */
class SupplierBillController extends Controller
{
    private function visibleBills(User $user)
    {
        $query = SupplierBill::where('school_id', $user->school_id);

        if ($user->isOrgWide()) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $user->branch_id));
    }

    private function nextBillNumber(int $schoolId): string
    {
        $year = (int) date('Y');
        $prefix = "BILL-{$year}-";

        $n = NumberSequence::next($schoolId, 'supplier_bill', $year, fn () => SupplierBill::where('school_id', $schoolId)
            ->where('bill_number', 'like', "{$prefix}%")
            ->pluck('bill_number')
            ->map(fn ($x) => (int) substr($x, strlen($prefix)))
            ->max() ?? 0);

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canViewOrManage('supplier-bill-manage'), 403);

        $bills = $this->visibleBills($user)
            ->with(['supplier:id,name', 'items', 'payments'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SupplierBill $b) => [
                'id' => $b->id,
                'bill_number' => $b->bill_number,
                'supplier' => $b->supplier->name,
                'bill_date' => $b->bill_date->toDateString(),
                'total' => $b->total(),
                'balance' => $b->balance(),
            ]);

        return Inertia::render('SupplierBills/Index', ['bills' => $bills]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('supplier-bill-manage'), 403);

        $purchaseOrderId = $request->integer('purchase_order_id') ?: null;
        $fromOrder = null;
        if ($purchaseOrderId) {
            $po = PurchaseOrder::where('school_id', $user->school_id)
                ->whereIn('status', ['sent', 'partially_received', 'received'])
                ->with(['supplier:id,name', 'items.purchaseRequestItem:id,description'])
                ->findOrFail($purchaseOrderId);

            $fromOrder = [
                'id' => $po->id,
                'number' => $po->number,
                'supplier_id' => $po->supplier_id,
                'supplier_name' => $po->supplier->name,
                'items' => $po->items->map(fn ($i) => [
                    'id' => $i->id,
                    'description' => $i->purchaseRequestItem->description,
                    'unit_price' => $i->unit_price,
                    'remaining_to_bill' => $i->receivedQuantity() - $i->billedQuantity(),
                ])->filter(fn ($i) => $i['remaining_to_bill'] > 0)->values(),
            ];
        }

        return Inertia::render('SupplierBills/Create', [
            'suppliers' => Supplier::where('school_id', $user->school_id)->orderBy('name')->get(['id', 'name']),
            'eligibleOrders' => PurchaseOrder::where('school_id', $user->school_id)
                ->whereIn('status', ['sent', 'partially_received', 'received'])
                ->with('supplier:id,name')
                ->orderByDesc('id')
                ->get(['id', 'number', 'supplier_id']),
            'fromOrder' => $fromOrder,
            'expenseAccounts' => Account::where('school_id', $user->school_id)->where('type', 'expense')->where('is_header', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('supplier-bill-manage'), 403);

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('school_id', $user->school_id)],
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')->where('school_id', $user->school_id)],
            'supplier_reference' => ['nullable', 'string', 'max:255'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.expense_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('school_id', $user->school_id)],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $purchaseOrder = $validated['purchase_order_id'] ?? null
            ? PurchaseOrder::where('school_id', $user->school_id)->findOrFail($validated['purchase_order_id'])
            : null;
        abort_unless(! $purchaseOrder || $user->isOrgWide() || $purchaseOrder->branch_id === null || $purchaseOrder->branch_id === $user->branch_id, 403);

        $orderItems = $purchaseOrder ? $purchaseOrder->items->keyBy('id') : collect();

        $bill = DB::transaction(function () use ($user, $validated, $purchaseOrder, $orderItems) {
            $bill = SupplierBill::create([
                'school_id' => $user->school_id,
                'branch_id' => $purchaseOrder->branch_id ?? $user->branch_id,
                'supplier_id' => $validated['supplier_id'],
                'purchase_order_id' => $purchaseOrder?->id,
                'bill_number' => $this->nextBillNumber($user->school_id),
                'supplier_reference' => $validated['supplier_reference'] ?? null,
                'bill_date' => $validated['bill_date'],
                'due_date' => $validated['due_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($validated['items'] as $index => $line) {
                $poItemId = $line['purchase_order_item_id'] ?? null;
                if ($poItemId) {
                    /** @var \App\Models\PurchaseOrderItem|null $orderItem */
                    $orderItem = $orderItems->get($poItemId);
                    if (! $orderItem) {
                        continue;
                    }
                    $remaining = $orderItem->receivedQuantity() - $orderItem->billedQuantity();
                    if ($line['quantity'] > $remaining) {
                        throw ValidationException::withMessages([
                            "items.{$index}.quantity" => "Only {$remaining} of \"{$line['description']}\" has been received and not yet billed.",
                        ]);
                    }
                }

                $tax = $line['tax_amount'] ?? 0;
                $bill->items()->create([
                    'purchase_order_item_id' => $poItemId,
                    'expense_account_id' => $line['expense_account_id'] ?? null,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'tax_amount' => $tax,
                    'total' => round($line['quantity'] * $line['unit_price'] + $tax, 2),
                ]);
            }

            return $bill;
        });

        try {
            AccountingPostings::postSupplierBillRecorded($bill->fresh('items'), $user);
        } catch (\Throwable $e) {
            Log::warning('Accounting: failed to post supplier-bill entry', ['bill_id' => $bill->id, 'error' => $e->getMessage()]);
        }

        return redirect()->route('supplier-bills.show', $bill)->with('success', "Supplier bill {$bill->bill_number} recorded.");
    }

    public function show(Request $request, SupplierBill $supplierBill): Response
    {
        $user = $request->user();
        abort_unless($supplierBill->school_id === $user->school_id, 403);
        abort_unless($user->canViewOrManage('supplier-bill-manage'), 403);
        abort_unless($user->isOrgWide() || $supplierBill->branch_id === null || $supplierBill->branch_id === $user->branch_id, 403);

        $supplierBill->load(['supplier:id,name', 'purchaseOrder:id,number', 'createdBy:id,name', 'items', 'payments.recordedBy:id,name', 'payments.reversals.reversedBy:id,name']);

        return Inertia::render('SupplierBills/Show', [
            'bill' => [
                'id' => $supplierBill->id,
                'bill_number' => $supplierBill->bill_number,
                'supplier' => $supplierBill->supplier->name,
                'purchase_order' => $supplierBill->purchaseOrder?->number,
                'supplier_reference' => $supplierBill->supplier_reference,
                'bill_date' => $supplierBill->bill_date->toDateString(),
                'due_date' => $supplierBill->due_date?->toDateString(),
                'notes' => $supplierBill->notes,
                'created_by' => $supplierBill->createdBy->name,
                'total' => $supplierBill->total(),
                'paid' => $supplierBill->paidAmount(),
                'balance' => $supplierBill->balance(),
                'items' => $supplierBill->items->map(fn ($i) => [
                    'id' => $i->id,
                    'description' => $i->description,
                    'quantity' => $i->quantity,
                    'unit_price' => $i->unit_price,
                    'tax_amount' => $i->tax_amount,
                    'total' => $i->total,
                ]),
                'payments' => $supplierBill->payments->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => (float) $p->amount,
                    'method' => $p->method,
                    'reference_number' => $p->reference_number,
                    'paid_date' => $p->paid_date->toDateString(),
                    'recorded_by' => $p->recordedBy->name,
                    'reversible' => $p->netAmount(),
                    'reversals' => $p->reversals->map(fn ($r) => [
                        'id' => $r->id,
                        'kind' => $r->kind,
                        'amount' => (float) $r->amount,
                        'reason' => $r->reason,
                        'reference' => $r->reference,
                        'by' => $r->reversedBy->name,
                        'at' => $r->reversed_at->format('d M Y'),
                    ]),
                ]),
            ],
            'canManage' => $user->can('supplier-payment-manage') && $supplierBill->balance() > 0,
            'canReversePayments' => $user->can('supplier-payment-manage'),
            'reversalKinds' => \App\Models\SupplierPaymentReversal::KINDS,
        ]);
    }
}
