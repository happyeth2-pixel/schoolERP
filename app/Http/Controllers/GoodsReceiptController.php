<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\InventoryStore;
use App\Models\NumberSequence;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Notifications\PurchaseRequestFulfilled;
use App\Services\Stock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GoodsReceiptController extends Controller
{
    private function nextNumber(int $schoolId): string
    {
        $year = (int) date('Y');
        $prefix = "GR-{$year}-";

        $n = NumberSequence::next($schoolId, 'goods_receipt', $year, fn () => GoodsReceipt::where('school_id', $schoolId)
            ->where('number', 'like', "{$prefix}%")
            ->pluck('number')
            ->map(fn ($x) => (int) substr($x, strlen($prefix)))
            ->max() ?? 0);

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    public function store(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('goods-receipt-manage'), 403);
        abort_unless($purchaseOrder->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $purchaseOrder->branch_id === null || $purchaseOrder->branch_id === $user->branch_id, 403);
        abort_unless(in_array($purchaseOrder->status, ['sent', 'partially_received'], true), 422);

        $storeIds = InventoryStore::where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)))
            ->pluck('id');

        $validated = $request->validate([
            'store_id' => ['required', 'integer', Rule::in($storeIds)],
            'received_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.quantity_received' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $store = InventoryStore::findOrFail($validated['store_id']);
        $orderItems = $purchaseOrder->items->keyBy('id');

        DB::transaction(function () use ($purchaseOrder, $user, $validated, $orderItems, $store) {
            $goodsReceipt = GoodsReceipt::create([
                'school_id' => $user->school_id,
                'branch_id' => $purchaseOrder->branch_id,
                'purchase_order_id' => $purchaseOrder->id,
                'store_id' => $store->id,
                'number' => $this->nextNumber($user->school_id),
                'received_date' => $validated['received_date'],
                'notes' => $validated['notes'] ?? null,
                'recorded_by' => $user->id,
            ]);

            foreach ($validated['items'] as $index => $line) {
                /** @var \App\Models\PurchaseOrderItem|null $orderItem */
                $orderItem = $orderItems->get($line['purchase_order_item_id']);
                if (! $orderItem) {
                    continue;
                }

                $remaining = $orderItem->quantity - $orderItem->receivedQuantity();
                if ($line['quantity_received'] > $remaining) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity_received" => "Only {$remaining} of \"{$orderItem->purchaseRequestItem->description}\" is still expected.",
                    ]);
                }

                $receiptItem = $goodsReceipt->items()->create([
                    'purchase_order_item_id' => $orderItem->id,
                    'quantity_received' => $line['quantity_received'],
                    'notes' => $line['notes'] ?? null,
                ]);

                $catalogItem = $orderItem->purchaseRequestItem->item;
                if ($catalogItem) {
                    Stock::receive($catalogItem, $store, $line['quantity_received'], $user, $goodsReceipt->number, null, $receiptItem->id);
                }
            }

            $purchaseOrder->refreshReceiptStatus();

            if ($purchaseOrder->fresh()->status === 'received') {
                $purchaseRequest = $purchaseOrder->purchaseRequest;
                if ($purchaseRequest->isFullyReceived()) {
                    $purchaseRequest->requester->notify(new PurchaseRequestFulfilled($purchaseRequest));
                }
            }
        });

        return back()->with('success', 'Goods receipt recorded.');
    }
}
