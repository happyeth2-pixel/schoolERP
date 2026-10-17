<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryStore;
use App\Models\User;
use App\Services\Stock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StockMovementController extends Controller
{
    private function visibleStores(User $user)
    {
        return InventoryStore::where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)))
            ->get();
    }

    /** Resolves an item + store the request named, checked against this school and this user's visible stores. */
    private function resolve(Request $request, User $user, string $storeField = 'store_id'): array
    {
        $item = InventoryItem::where('school_id', $user->school_id)->findOrFail($request->input('item_id'));
        $storeIds = $this->visibleStores($user)->pluck('id');
        $store = InventoryStore::whereIn('id', $storeIds)->findOrFail($request->input($storeField));

        return [$item, $store];
    }

    public function receive(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('stock-receive'), 403);

        $validated = $request->validate([
            'item_id' => ['required', 'integer'],
            'store_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        [$item, $store] = $this->resolve($request, $user);
        Stock::receive($item, $store, $validated['quantity'], $user, $validated['reference_number'] ?? null, $validated['reason'] ?? null);

        return back()->with('success', "Received {$validated['quantity']} {$item->unit} of {$item->name} into {$store->name}.");
    }

    public function issue(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('stock-issue'), 403);

        $validated = $request->validate([
            'item_id' => ['required', 'integer'],
            'store_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        [$item, $store] = $this->resolve($request, $user);

        try {
            Stock::issue($item, $store, $validated['quantity'], $user, $validated['reason']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Issued {$validated['quantity']} {$item->unit} of {$item->name} from {$store->name}.");
    }

    public function transfer(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('stock-transfer'), 403);

        $validated = $request->validate([
            'item_id' => ['required', 'integer'],
            'from_store_id' => ['required', 'integer'],
            'to_store_id' => ['required', 'integer', 'different:from_store_id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $item = InventoryItem::where('school_id', $user->school_id)->findOrFail($validated['item_id']);
        $storeIds = $this->visibleStores($user)->pluck('id');
        $from = InventoryStore::whereIn('id', $storeIds)->findOrFail($validated['from_store_id']);
        $to = InventoryStore::whereIn('id', $storeIds)->findOrFail($validated['to_store_id']);

        try {
            Stock::transfer($item, $from, $to, $validated['quantity'], $user, $validated['reason'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Transferred {$validated['quantity']} {$item->unit} of {$item->name} from {$from->name} to {$to->name}.");
    }

    public function adjust(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('stock-adjust'), 403);

        $validated = $request->validate([
            'item_id' => ['required', 'integer'],
            'store_id' => ['required', 'integer'],
            'physical_quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        [$item, $store] = $this->resolve($request, $user, 'store_id');
        Stock::adjust($item, $store, $validated['physical_quantity'], $user, $validated['reason']);

        return back()->with('success', "Stock for {$item->name} at {$store->name} adjusted to {$validated['physical_quantity']} {$item->unit}.");
    }

    /** The full movement log across every item -- section 11's "stock movement history" report. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canViewOrManage('inventory-view'), 403);

        $storeIds = $this->visibleStores($user)->pluck('id');

        $movements = \App\Models\StockMovement::whereIn('store_id', $storeIds)
            ->with(['item:id,name,unit', 'store:id,name', 'performer:id,name'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->latest()
            ->paginate(30)
            ->withQueryString()
            ->through(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'quantity' => $m->quantity,
                'is_increase' => $m->isIncrease(),
                'item_name' => $m->item?->name,
                'unit' => $m->item?->unit,
                'store_name' => $m->store?->name,
                'reference_number' => $m->reference_number,
                'reason' => $m->reason,
                'performed_by' => $m->performer?->name,
                'created_at' => $m->created_at->toDateTimeString(),
            ]);

        return Inertia::render('Inventory/Movements/Index', [
            'movements' => $movements,
            'type' => $request->query('type', ''),
            'types' => \App\Models\StockMovement::TYPES,
        ]);
    }
}
