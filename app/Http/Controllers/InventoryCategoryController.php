<?php

namespace App\Http\Controllers;

use App\Models\InventoryCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Categories have no page of their own -- they are listed and managed inline wherever they're chosen from
 * (Inventory/Items/Index.jsx today; Assets will reuse the same 'asset' kind in a later phase). */
class InventoryCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('inventory-manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(InventoryCategory::KINDS)],
        ]);

        InventoryCategory::create(['school_id' => $user->school_id] + $validated);

        return back()->with('success', 'Category added.');
    }

    public function update(Request $request, InventoryCategory $inventoryCategory): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('inventory-manage'), 403);
        abort_unless($inventoryCategory->school_id === $user->school_id, 403);

        $inventoryCategory->update($request->validate(['name' => ['required', 'string', 'max:255']]));

        return back()->with('success', 'Category updated.');
    }

    public function destroy(Request $request, InventoryCategory $inventoryCategory): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('inventory-manage'), 403);
        abort_unless($inventoryCategory->school_id === $user->school_id, 403);

        $inventoryCategory->delete();

        return back()->with('success', 'Category archived.');
    }
}
