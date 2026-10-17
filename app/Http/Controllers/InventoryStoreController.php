<?php

namespace App\Http\Controllers;

use App\Models\InventoryStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryStoreController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canViewOrManage('inventory-view') || $user->can('inventory-manage'), 403);

        $stores = InventoryStore::where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)))
            ->with('branch:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (InventoryStore $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'code' => $s->code,
                'branch_id' => $s->branch_id,
                'branch_name' => $s->branch?->name,
                'item_count' => $s->balances()->where('quantity', '>', 0)->count(),
            ]);

        return Inertia::render('Inventory/Stores/Index', [
            'stores' => $stores,
            'branches' => $user->school->branches()->select('id', 'name')->orderBy('name')->get(),
            'canManage' => $user->can('inventory-manage'),
            'isOrgWide' => $user->isOrgWide(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('inventory-manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $branchId = $validated['branch_id'] ?? null;
        if (! $user->isOrgWide()) {
            $branchId = $user->branch_id;
        } elseif ($branchId !== null) {
            $user->school->branches()->whereKey($branchId)->firstOrFail();
        }

        InventoryStore::create([
            'school_id' => $user->school_id,
            'branch_id' => $branchId,
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
        ]);

        return back()->with('success', 'Store added.');
    }

    public function update(Request $request, InventoryStore $inventoryStore): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('inventory-manage'), 403);
        abort_unless($inventoryStore->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $inventoryStore->branch_id === $user->branch_id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
        ]);

        $inventoryStore->update($validated);

        return back()->with('success', 'Store updated.');
    }

    public function destroy(Request $request, InventoryStore $inventoryStore): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('inventory-manage'), 403);
        abort_unless($inventoryStore->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $inventoryStore->branch_id === $user->branch_id, 403);

        $inventoryStore->delete();

        return back()->with('success', 'Store archived.');
    }
}
