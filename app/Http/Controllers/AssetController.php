<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\InventoryCategory;
use App\Models\InventoryStore;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Assets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    private function visibleStores(User $user)
    {
        return InventoryStore::where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)))
            ->get();
    }

    /** Assets this user may see: every branch when org-wide, otherwise their own branch's and every
     * school-wide (branch_id null) asset -- the same rule used everywhere else in this module. */
    private function visibleAssets(User $user)
    {
        return Asset::where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)));
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canViewOrManage('asset-view'), 403);

        $search = trim((string) $request->query('search', ''));

        $assets = $this->visibleAssets($user)
            ->with(['category:id,name', 'assignee:id,name', 'currentStore:id,name'])
            ->when($search !== '', fn ($q) => $q->where(fn ($q2) => $q2->where('name', 'like', "%{$search}%")
                ->orWhere('asset_tag', 'like', "%{$search}%")
                ->orWhere('serial_number', 'like', "%{$search}%")))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Asset $a) => [
                'id' => $a->id,
                'asset_tag' => $a->asset_tag,
                'name' => $a->name,
                'category_name' => $a->category?->name,
                'status' => $a->status,
                'condition' => $a->condition,
                'assignee' => $a->assignee?->name,
                'store_name' => $a->currentStore?->name,
            ]);

        return Inertia::render('Assets/Index', [
            'assets' => $assets,
            'search' => $search,
            'status' => $request->query('status', ''),
            'statuses' => Asset::STATUSES,
            'canManage' => $user->can('asset-manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('asset-manage'), 403);

        return Inertia::render('Assets/Create', [
            'categories' => InventoryCategory::where('school_id', $user->school_id)->where('kind', 'asset')->orderBy('name')->get(['id', 'name']),
            'stores' => $this->visibleStores($user)->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'suppliers' => Supplier::where('school_id', $user->school_id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function nextTag(int $schoolId): string
    {
        $n = \App\Models\NumberSequence::next($schoolId, 'asset', (int) date('Y'), fn () => Asset::where('school_id', $schoolId)
            ->where('asset_tag', 'like', 'AST-%')
            ->pluck('asset_tag')
            ->map(fn ($x) => (int) substr($x, 4))
            ->max() ?? 0);

        return 'AST-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('asset-manage'), 403);

        $storeIds = $this->visibleStores($user)->pluck('id');

        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', Rule::exists('inventory_categories', 'id')->where('school_id', $user->school_id)->where('kind', 'asset')],
            'name' => ['required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'acquisition_cost' => ['nullable', 'numeric', 'min:0'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('school_id', $user->school_id)->whereNull('deleted_at')],
            'store_id' => ['required', 'integer', Rule::in($storeIds)],
            'condition' => ['required', Rule::in(Asset::CONDITIONS)],
            'warranty_expiry' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $store = InventoryStore::findOrFail($validated['store_id']);

        // A linked supplier's name is snapshotted into the frozen `supplier` text column right here, at
        // creation -- the same "frozen at the moment of the transaction" rule invoice_items already follows
        // -- so this display value stays correct even if the supplier is later renamed or archived. A
        // manually-typed `supplier` is only used when no real Supplier record was picked at all.
        $supplierName = $validated['supplier'] ?? null;
        if (! empty($validated['supplier_id'])) {
            $supplierName = Supplier::where('school_id', $user->school_id)->findOrFail($validated['supplier_id'])->name;
        }

        $asset = Asset::create([
            'school_id' => $user->school_id,
            'branch_id' => $store->branch_id,
            'category_id' => $validated['category_id'] ?? null,
            'asset_tag' => $this->nextTag($user->school_id),
            'name' => $validated['name'],
            'serial_number' => $validated['serial_number'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'purchase_date' => $validated['purchase_date'] ?? null,
            'acquisition_cost' => $validated['acquisition_cost'] ?? null,
            'supplier' => $supplierName,
            'supplier_id' => $validated['supplier_id'] ?? null,
            'current_store_id' => $store->id,
            'condition' => $validated['condition'],
            'status' => 'available',
            'warranty_expiry' => $validated['warranty_expiry'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('assets.show', $asset)->with('success', "Asset {$asset->asset_tag} added.");
    }

    public function show(Request $request, Asset $asset): Response
    {
        $user = $request->user();
        abort_unless($asset->school_id === $user->school_id, 403);
        abort_unless($user->canViewOrManage('asset-view'), 403);
        abort_unless($user->isOrgWide() || $asset->branch_id === null || $asset->branch_id === $user->branch_id, 403);

        $asset->load(['category:id,name', 'assignee:id,name', 'currentStore:id,name', 'supplierRecord:id,name']);
        $events = $asset->events()->with(['fromUser:id,name', 'toUser:id,name', 'fromStore:id,name', 'toStore:id,name', 'performer:id,name'])->latest()->get();

        return Inertia::render('Assets/Show', [
            'canViewSuppliers' => $user->canViewOrManage('supplier-manage'),
            'asset' => [
                'id' => $asset->id,
                'asset_tag' => $asset->asset_tag,
                'name' => $asset->name,
                'category_name' => $asset->category?->name,
                'serial_number' => $asset->serial_number,
                'barcode' => $asset->barcode,
                'purchase_date' => $asset->purchase_date?->toDateString(),
                'acquisition_cost' => $asset->acquisition_cost,
                'supplier' => $asset->supplier,
                // Only true once the link actually resolves to a still-active Supplier -- an archived
                // supplier's record 404s via route-model binding, so a dead link is never offered; the
                // frozen `supplier` text above is what stays reliable either way.
                'supplier_id' => $asset->supplierRecord ? $asset->supplier_id : null,
                'condition' => $asset->condition,
                'status' => $asset->status,
                'warranty_expiry' => $asset->warranty_expiry?->toDateString(),
                'notes' => $asset->notes,
                'assignee' => $asset->assignee?->name,
                'store_name' => $asset->currentStore?->name,
            ],
            'events' => $events->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'from_user' => $e->fromUser?->name,
                'to_user' => $e->toUser?->name,
                'from_store' => $e->fromStore?->name,
                'to_store' => $e->toStore?->name,
                'notes' => $e->notes,
                'performed_by' => $e->performer->name,
                'created_at' => $e->created_at->toDateTimeString(),
            ]),
            'stores' => $this->visibleStores($user)->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'people' => User::where('school_id', $user->school_id)->where('status', 'active')
                ->when(! $user->isOrgWide(), fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)))
                ->orderBy('name')->get(['id', 'name']),
            'canManage' => $user->can('asset-manage'),
        ]);
    }

    private function actor(Request $request, Asset $asset): User
    {
        $user = $request->user();
        abort_unless($user->can('asset-manage'), 403);
        abort_unless($asset->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $asset->branch_id === null || $asset->branch_id === $user->branch_id, 403);

        return $user;
    }

    public function assign(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->actor($request, $asset);
        $validated = $request->validate(['user_id' => ['required', 'integer'], 'notes' => ['nullable', 'string', 'max:255']]);
        $toUser = User::where('school_id', $user->school_id)->findOrFail($validated['user_id']);

        try {
            Assets::assign($asset, $toUser, $user, $validated['notes'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$asset->name} assigned to {$toUser->name}.");
    }

    public function reassign(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->actor($request, $asset);
        $validated = $request->validate(['user_id' => ['required', 'integer'], 'notes' => ['nullable', 'string', 'max:255']]);
        $toUser = User::where('school_id', $user->school_id)->findOrFail($validated['user_id']);

        try {
            Assets::reassign($asset, $toUser, $user, $validated['notes'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$asset->name} reassigned to {$toUser->name}.");
    }

    public function returnToStore(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->actor($request, $asset);
        $storeIds = $this->visibleStores($user)->pluck('id');
        $validated = $request->validate(['store_id' => ['required', 'integer', Rule::in($storeIds)], 'notes' => ['nullable', 'string', 'max:255']]);
        $store = InventoryStore::findOrFail($validated['store_id']);

        try {
            Assets::returnToStore($asset, $store, $user, $validated['notes'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$asset->name} returned to {$store->name}.");
    }

    public function transfer(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->actor($request, $asset);
        $storeIds = $this->visibleStores($user)->pluck('id');
        $validated = $request->validate(['store_id' => ['required', 'integer', Rule::in($storeIds)], 'notes' => ['nullable', 'string', 'max:255']]);
        $store = InventoryStore::findOrFail($validated['store_id']);

        try {
            Assets::transfer($asset, $store, $user, $validated['notes'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$asset->name} moved to {$store->name}.");
    }

    public function startRepair(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->actor($request, $asset);
        $validated = $request->validate(['notes' => ['required', 'string', 'max:255']]);

        try {
            Assets::startRepair($asset, $user, $validated['notes']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$asset->name} sent for repair.");
    }

    public function completeRepair(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->actor($request, $asset);
        $validated = $request->validate(['outcome' => ['required', Rule::in(['fixed', 'damaged'])], 'notes' => ['nullable', 'string', 'max:255']]);

        try {
            Assets::completeRepair($asset, $validated['outcome'], $user, $validated['notes'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Repair recorded for {$asset->name}.");
    }

    public function markLost(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->actor($request, $asset);
        $validated = $request->validate(['notes' => ['required', 'string', 'max:255']]);
        Assets::markLost($asset, $user, $validated['notes']);

        return back()->with('success', "{$asset->name} marked lost.");
    }

    public function markDamaged(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->actor($request, $asset);
        $validated = $request->validate(['notes' => ['required', 'string', 'max:255']]);
        Assets::markDamaged($asset, $user, $validated['notes']);

        return back()->with('success', "{$asset->name} marked damaged.");
    }

    public function retire(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->actor($request, $asset);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:255']]);

        try {
            Assets::retire($asset, $user, $validated['notes'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$asset->name} retired.");
    }

    public function dispose(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->actor($request, $asset);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:255']]);

        try {
            Assets::dispose($asset, $user, $validated['notes'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$asset->name} disposed.");
    }
}
