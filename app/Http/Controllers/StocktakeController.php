<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryStore;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Stock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Counting a store's stock against what the system says, and turning real differences into authorized
 * adjustments -- reusing App\Services\Stock::adjust() exactly, never a second way to change a balance.
 * Gated on stock-adjust throughout: a stocktake's whole point is to end in an adjustment, so whoever may
 * run one must already be allowed to make one.
 */
class StocktakeController extends Controller
{
    private function visibleStores(User $user)
    {
        return InventoryStore::where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)))
            ->get();
    }

    private function visibleStocktakes(User $user)
    {
        $storeIds = $this->visibleStores($user)->pluck('id');

        return Stocktake::where('school_id', $user->school_id)->whereIn('store_id', $storeIds);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canViewOrManage('stock-adjust'), 403);

        $stocktakes = $this->visibleStocktakes($user)
            ->with(['store:id,name', 'starter:id,name'])
            ->withCount('lines')
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (Stocktake $s) => [
                'id' => $s->id,
                'store_name' => $s->store->name,
                'status' => $s->status,
                'started_by' => $s->starter->name,
                'started_at' => $s->started_at->toDateString(),
                'line_count' => $s->lines_count,
            ]);

        return Inertia::render('Stocktakes/Index', [
            'stocktakes' => $stocktakes,
            'stores' => $this->visibleStores($user)->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'canManage' => $user->can('stock-adjust'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('stock-adjust'), 403);

        $storeIds = $this->visibleStores($user)->pluck('id');
        $validated = $request->validate(['store_id' => ['required', 'integer', Rule::in($storeIds)]]);
        $store = InventoryStore::findOrFail($validated['store_id']);

        if (Stocktake::where('store_id', $store->id)->where('status', 'in_progress')->exists()) {
            throw ValidationException::withMessages(['store_id' => "{$store->name} already has a stocktake in progress."]);
        }

        $items = InventoryItem::where('school_id', $user->school_id)->get();

        $stocktake = DB::transaction(function () use ($user, $store, $items) {
            $stocktake = Stocktake::create([
                'school_id' => $user->school_id,
                'branch_id' => $store->branch_id,
                'store_id' => $store->id,
                'status' => 'in_progress',
                'started_by' => $user->id,
                'started_at' => now(),
            ]);

            foreach ($items as $item) {
                $stocktake->lines()->create([
                    'item_id' => $item->id,
                    'system_quantity' => Stock::balance($item, $store),
                ]);
            }

            return $stocktake;
        });

        return redirect()->route('stocktakes.show', $stocktake)->with('success', "Stocktake started for {$store->name}.");
    }

    public function show(Request $request, Stocktake $stocktake): Response
    {
        $user = $request->user();
        abort_unless($stocktake->school_id === $user->school_id, 403);
        abort_unless($user->canViewOrManage('stock-adjust'), 403);
        abort_unless($user->isOrgWide() || $stocktake->branch_id === null || $stocktake->branch_id === $user->branch_id, 403);

        $stocktake->load(['store:id,name', 'starter:id,name', 'completer:id,name', 'lines.item:id,name,unit']);

        return Inertia::render('Stocktakes/Show', [
            'stocktake' => [
                'id' => $stocktake->id,
                'store_name' => $stocktake->store->name,
                'status' => $stocktake->status,
                'started_by' => $stocktake->starter->name,
                'started_at' => $stocktake->started_at->toDateTimeString(),
                'completed_by' => $stocktake->completer?->name,
                'completed_at' => $stocktake->completed_at?->toDateTimeString(),
            ],
            'lines' => $stocktake->lines->map(fn ($l) => [
                'id' => $l->id,
                'item_name' => $l->item->name,
                'unit' => $l->item->unit,
                'system_quantity' => $l->system_quantity,
                'physical_quantity' => $l->physical_quantity,
                'reason' => $l->reason,
            ]),
            'canManage' => $user->can('stock-adjust') && $stocktake->status === 'in_progress',
        ]);
    }

    public function saveCounts(Request $request, Stocktake $stocktake): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('stock-adjust'), 403);
        abort_unless($stocktake->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $stocktake->branch_id === null || $stocktake->branch_id === $user->branch_id, 403);
        abort_unless($stocktake->status === 'in_progress', 422);

        $this->applyCounts($request, $stocktake);

        return back()->with('success', 'Counts saved.');
    }

    /** Shared by saveCounts() and complete(): a request may carry the same counts payload either way, so
     * completing never depends on a separate save happening first. */
    private function applyCounts(Request $request, Stocktake $stocktake): void
    {
        $validated = $request->validate([
            'counts' => ['sometimes', 'array'],
            'counts.*.physical_quantity' => ['nullable', 'integer', 'min:0'],
            'counts.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($validated['counts'] ?? [] as $lineId => $data) {
            $line = $stocktake->lines()->find($lineId);
            if (! $line) {
                continue;
            }
            $line->update(['physical_quantity' => $data['physical_quantity'] ?? null, 'reason' => $data['reason'] ?? null]);
        }
    }

    public function complete(Request $request, Stocktake $stocktake): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('stock-adjust'), 403);
        abort_unless($stocktake->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $stocktake->branch_id === null || $stocktake->branch_id === $user->branch_id, 403);
        abort_unless($stocktake->status === 'in_progress', 422);

        $this->applyCounts($request, $stocktake);

        $lines = $stocktake->lines()->with('item')->get();

        if ($lines->contains(fn ($l) => ! $l->isCounted())) {
            throw ValidationException::withMessages(['status' => 'Every item must have a physical count before the stocktake can be completed.']);
        }
        if ($lines->contains(fn ($l) => $l->difference() !== 0 && ! $l->reason)) {
            throw ValidationException::withMessages(['status' => 'Every item with a difference needs a reason before the stocktake can be completed.']);
        }

        DB::transaction(function () use ($stocktake, $lines, $user) {
            foreach ($lines as $line) {
                if ($line->difference() === 0) {
                    continue;
                }
                Stock::adjust($line->item, $stocktake->store, $line->physical_quantity, $user, $line->reason, $line->id);
            }

            $stocktake->update(['status' => 'completed', 'completed_by' => $user->id, 'completed_at' => now()]);
        });

        return redirect()->route('stocktakes.show', $stocktake)->with('success', 'Stocktake completed. Differences were recorded as stock adjustments.');
    }
}
