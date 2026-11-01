<?php

namespace App\Http\Controllers;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryStore;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryItemController extends Controller
{
    /** Stores this user may see stock in: every store when org-wide, otherwise their own branch's plus every
     * school-wide (branch_id null) store -- the same rule PaymentAccount and Announcement already use. */
    private function visibleStores(User $user)
    {
        return InventoryStore::where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)))
            ->get();
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canViewOrManage('inventory-view'), 403);

        $storeIds = $this->visibleStores($user)->pluck('id');
        $search = trim((string) $request->query('search', ''));

        // Whole-catalog breakdown for the page's stock-status chart -- deliberately never filtered by
        // $search, so the chart always reads as "the whole store," the same scope as the dashboard's own
        // inventory figures (DashboardController::inventoryStats()), which this mirrors.
        $allItems = InventoryItem::where('school_id', $user->school_id)
            ->with(['balances' => fn ($q) => $q->whereIn('store_id', $storeIds)])
            ->get();
        $lowStockCount = 0;
        $outOfStockCount = 0;
        foreach ($allItems as $item) {
            $onHand = $item->totalOnHand($item->balances);
            if ($onHand <= 0) {
                $outOfStockCount++;
            } elseif ($item->isLowStock($onHand)) {
                $lowStockCount++;
            }
        }
        $summary = ['total' => $allItems->count(), 'low_stock' => $lowStockCount, 'out_of_stock' => $outOfStockCount];

        $items = InventoryItem::where('school_id', $user->school_id)
            ->with(['category:id,name', 'balances' => fn ($q) => $q->whereIn('store_id', $storeIds)])
            ->when($search !== '', fn ($q) => $q->where(fn ($q2) => $q2->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(function (InventoryItem $item) {
                $onHand = $item->totalOnHand($item->balances);

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'barcode' => $item->barcode,
                    'unit' => $item->unit,
                    'category_name' => $item->category?->name,
                    'on_hand' => $onHand,
                    'reorder_level' => $item->reorder_level,
                    'low_stock' => $item->isLowStock($onHand),
                    'out_of_stock' => $onHand <= 0,
                ];
            });

        return Inertia::render('Inventory/Items/Index', [
            'items' => $items,
            'summary' => $summary,
            'search' => $search,
            'canManage' => $user->can('inventory-manage'),
            'canReceive' => $user->can('stock-receive'),
            'canIssue' => $user->can('stock-issue'),
            'canTransfer' => $user->can('stock-transfer'),
            'canAdjust' => $user->can('stock-adjust'),
            'stores' => $this->visibleStores($user)->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'categories' => InventoryCategory::where('school_id', $user->school_id)->where('kind', 'consumable')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('inventory-manage'), 403);

        return Inertia::render('Inventory/Items/Create', [
            'categories' => InventoryCategory::where('school_id', $user->school_id)->where('kind', 'consumable')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function rules(Request $request, ?InventoryItem $item = null): array
    {
        $user = $request->user();

        return [
            'category_id' => ['nullable', 'integer', Rule::exists('inventory_categories', 'id')->where('school_id', $user->school_id)],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('inventory_items', 'sku')->where('school_id', $user->school_id)->ignore($item?->id)->whereNull('deleted_at')],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('inventory_items', 'barcode')->where('school_id', $user->school_id)->ignore($item?->id)->whereNull('deleted_at')],
            'unit' => ['required', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:2000'],
            'min_stock_level' => ['required', 'integer', 'min:0'],
            'reorder_level' => ['required', 'integer', 'min:0'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('inventory-manage'), 403);

        $validated = $request->validate($this->rules($request));

        $item = InventoryItem::create(['school_id' => $user->school_id] + $validated);

        return redirect()->route('inventory-items.show', $item)->with('success', 'Item added.');
    }

    public function show(Request $request, InventoryItem $inventoryItem): Response
    {
        $user = $request->user();
        abort_unless($user->canViewOrManage('inventory-view'), 403);
        abort_unless($inventoryItem->school_id === $user->school_id, 403);

        $stores = $this->visibleStores($user);
        $storeIds = $stores->pluck('id');
        $balances = $inventoryItem->balances()->whereIn('store_id', $storeIds)->get()->keyBy('store_id');

        $movements = $inventoryItem->movements()
            ->whereIn('store_id', $storeIds)
            ->with(['store:id,name', 'performer:id,name', 'relatedMovement.store:id,name'])
            ->latest()
            ->paginate(20)
            ->through(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'quantity' => $m->quantity,
                'is_increase' => $m->isIncrease(),
                'store_name' => $m->store?->name,
                'related_store_name' => $m->relatedMovement?->store?->name,
                'reference_number' => $m->reference_number,
                'reason' => $m->reason,
                'performed_by' => $m->performer?->name,
                'created_at' => $m->created_at->toDateTimeString(),
            ]);

        return Inertia::render('Inventory/Items/Show', [
            'item' => [
                'id' => $inventoryItem->id,
                'name' => $inventoryItem->name,
                'sku' => $inventoryItem->sku,
                'barcode' => $inventoryItem->barcode,
                'unit' => $inventoryItem->unit,
                'description' => $inventoryItem->description,
                'category_name' => $inventoryItem->category?->name,
                'min_stock_level' => $inventoryItem->min_stock_level,
                'reorder_level' => $inventoryItem->reorder_level,
            ],
            'balances' => $stores->map(fn ($s) => [
                'store_id' => $s->id,
                'store_name' => $s->name,
                'quantity' => $balances->get($s->id)?->quantity ?? 0,
            ])->values(),
            'movements' => $movements,
            'stores' => $stores->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'canManage' => $user->can('inventory-manage'),
            'canReceive' => $user->can('stock-receive'),
            'canIssue' => $user->can('stock-issue'),
            'canTransfer' => $user->can('stock-transfer'),
            'canAdjust' => $user->can('stock-adjust'),
        ]);
    }

    public function update(Request $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('inventory-manage'), 403);
        abort_unless($inventoryItem->school_id === $user->school_id, 403);

        $inventoryItem->update($request->validate($this->rules($request, $inventoryItem)));

        return back()->with('success', 'Item updated.');
    }

    public function destroy(Request $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('inventory-manage'), 403);
        abort_unless($inventoryItem->school_id === $user->school_id, 403);

        $inventoryItem->delete();

        return redirect()->route('inventory-items.index')->with('success', 'Item archived (kept for history).');
    }
}
