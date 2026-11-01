<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\NumberSequence;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\PurchaseRequestApproved;
use App\Notifications\PurchaseRequestRejected;
use App\Notifications\PurchaseRequestSubmitted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseRequestController extends Controller
{
    /** Purchase requests this user may see: their own, always; plus every one in their branch (or every
     * branch, if org-wide) if they hold purchase-approve/-large -- nothing to approve is nothing to look at. */
    private function visiblePurchaseRequests(User $user)
    {
        $query = PurchaseRequest::where('school_id', $user->school_id);

        $isApprover = $user->can('purchase-approve') || $user->can('purchase-approve-large');
        if (! $isApprover) {
            return $query->where('requester_id', $user->id);
        }

        if ($user->isOrgWide()) {
            return $query; // sees every branch's requests, plus their own -- already the whole school
        }

        return $query->where(fn ($q) => $q->where('requester_id', $user->id)
            ->orWhere(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)));
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('purchase-request-create') || $user->can('purchase-approve') || $user->can('purchase-approve-large'), 403);

        $requests = $this->visiblePurchaseRequests($user)
            ->with(['requester:id,name', 'items'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PurchaseRequest $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'purpose' => $r->purpose,
                'priority' => $r->priority,
                'status' => $r->status,
                'requester' => $r->requester->name,
                'estimated_total' => $r->estimatedTotal(),
                'is_mine' => $r->requester_id === $user->id,
                'created_at' => $r->created_at->toDateString(),
            ]);

        return Inertia::render('PurchaseRequests/Index', [
            'requests' => $requests,
            'status' => $request->query('status', ''),
            'statuses' => PurchaseRequest::STATUSES,
            'canCreate' => $user->can('purchase-request-create'),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('purchase-request-create'), 403);

        $user = $request->user();

        return Inertia::render('PurchaseRequests/Create', [
            'items' => InventoryItem::where('school_id', $user->school_id)->orderBy('name')->get(['id', 'name', 'unit']),
        ]);
    }

    private function nextNumber(int $schoolId): string
    {
        $year = (int) date('Y');
        $prefix = "PR-{$year}-";

        $n = NumberSequence::next($schoolId, 'purchase_request', $year, fn () => PurchaseRequest::where('school_id', $schoolId)
            ->where('number', 'like', "{$prefix}%")
            ->pluck('number')
            ->map(fn ($x) => (int) substr($x, strlen($prefix)))
            ->max() ?? 0);

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('purchase-request-create'), 403);

        $validated = $request->validate([
            'department' => ['nullable', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:255'],
            'priority' => ['required', Rule::in(PurchaseRequest::PRIORITIES)],
            'action' => ['required', Rule::in(['draft', 'submit'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $purchaseRequest = DB::transaction(function () use ($user, $validated) {
            $purchaseRequest = PurchaseRequest::create([
                'school_id' => $user->school_id,
                'branch_id' => $user->branch_id,
                'requester_id' => $user->id,
                'number' => $this->nextNumber($user->school_id),
                'department' => $validated['department'] ?? null,
                'purpose' => $validated['purpose'],
                'priority' => $validated['priority'],
                'status' => $validated['action'] === 'submit' ? 'submitted' : 'draft',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $line) {
                $item = null;
                if (! empty($line['item_id'])) {
                    $item = InventoryItem::where('school_id', $user->school_id)->find($line['item_id']);
                }
                $unitPrice = $line['estimated_unit_price'] ?? null;

                $purchaseRequest->items()->create([
                    'item_id' => $item?->id,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'estimated_unit_price' => $unitPrice,
                    'estimated_total' => $unitPrice !== null ? round($unitPrice * $line['quantity'], 2) : null,
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $purchaseRequest;
        });

        if ($purchaseRequest->status === 'submitted') {
            $this->notifyApprovers($purchaseRequest);
        }

        return redirect()->route('purchase-requests.show', $purchaseRequest)->with('success', $purchaseRequest->status === 'submitted' ? 'Purchase request submitted for approval.' : 'Purchase request saved as a draft.');
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest): Response
    {
        $user = $request->user();
        abort_unless($purchaseRequest->school_id === $user->school_id, 403);
        $isMine = $purchaseRequest->requester_id === $user->id;
        abort_unless($isMine || $user->can('purchase-approve') || $user->can('purchase-approve-large'), 403);

        $purchaseRequest->load(['items.item:id,name,unit', 'requester:id,name', 'reviewer:id,name', 'quotations.supplier:id,name', 'quotations.items', 'purchaseOrders.supplier:id,name', 'purchaseOrders.items']);

        return Inertia::render('PurchaseRequests/Show', [
            'request' => [
                'id' => $purchaseRequest->id,
                'number' => $purchaseRequest->number,
                'department' => $purchaseRequest->department,
                'purpose' => $purchaseRequest->purpose,
                'priority' => $purchaseRequest->priority,
                'status' => $purchaseRequest->status,
                'requester' => $purchaseRequest->requester->name,
                'reviewer' => $purchaseRequest->reviewer?->name,
                'reviewed_at' => $purchaseRequest->reviewed_at?->toDateTimeString(),
                'rejection_reason' => $purchaseRequest->rejection_reason,
                'notes' => $purchaseRequest->notes,
                'is_mine' => $isMine,
                'created_at' => $purchaseRequest->created_at->toDateTimeString(),
                'estimated_total' => $purchaseRequest->estimatedTotal(),
                'needs_large_approval' => $purchaseRequest->needsLargeApproval(),
                'items' => $purchaseRequest->items->map(fn ($i) => [
                    'id' => $i->id,
                    'item_name' => $i->item?->name,
                    'description' => $i->description,
                    'quantity' => $i->quantity,
                    'unit' => $i->item?->unit,
                    'estimated_unit_price' => $i->estimated_unit_price,
                    'estimated_total' => $i->estimated_total,
                    'notes' => $i->notes,
                    'remaining_to_order' => $purchaseRequest->status === 'approved' ? $i->quantity - $i->orderedQuantity() : null,
                ]),
            ],
            'canSubmit' => $isMine && $purchaseRequest->status === 'draft',
            // Mirrors approve()/reject() exactly: a requester never approves or rejects their own
            // request, even if they also hold purchase-approve(-large) -- so the buttons are never
            // shown only to then 403 the moment they're pressed.
            'canApprove' => ! $isMine && $purchaseRequest->status === 'submitted' && (
                $purchaseRequest->needsLargeApproval() ? $user->can('purchase-approve-large') : $user->can('purchase-approve')
            ),
            'canManageQuotations' => $user->can('quotation-manage') && $purchaseRequest->status === 'approved',
            'quotations' => $purchaseRequest->status === 'approved' ? $purchaseRequest->quotations->map(fn ($q) => [
                'id' => $q->id,
                'supplier_id' => $q->supplier_id,
                'supplier_name' => $q->supplier->name,
                'reference' => $q->reference,
                'quotation_date' => $q->quotation_date->toDateString(),
                'validity_date' => $q->validity_date?->toDateString(),
                'delivery_info' => $q->delivery_info,
                'notes' => $q->notes,
                'selected' => $q->selected,
                'total' => $q->total(),
                'items' => $q->items->map(fn ($i) => [
                    'purchase_request_item_id' => $i->purchase_request_item_id,
                    'quantity' => $i->quantity,
                    'unit_price' => $i->unit_price,
                    'tax_amount' => $i->tax_amount,
                    'total' => $i->total,
                ]),
            ]) : [],
            'suppliers' => $purchaseRequest->status === 'approved' ? Supplier::where('school_id', $user->school_id)->orderBy('name')->get(['id', 'name']) : [],
            'canManagePurchaseOrders' => $user->can('purchase-order-manage') && $purchaseRequest->status === 'approved',
            'purchaseOrders' => $purchaseRequest->status === 'approved' ? $purchaseRequest->purchaseOrders->map(fn ($po) => [
                'id' => $po->id,
                'number' => $po->number,
                'supplier_name' => $po->supplier->name,
                'status' => $po->status,
                'total' => $po->total(),
            ]) : [],
        ]);
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $user = $request->user();
        abort_unless($purchaseRequest->requester_id === $user->id, 403);
        abort_unless($purchaseRequest->status === 'draft', 422);

        $purchaseRequest->update(['status' => 'submitted']);
        $this->notifyApprovers($purchaseRequest);

        return back()->with('success', 'Purchase request submitted for approval.');
    }

    private function notifyApprovers(PurchaseRequest $purchaseRequest): void
    {
        $permission = $purchaseRequest->needsLargeApproval() ? 'purchase-approve-large' : 'purchase-approve';

        $recipients = User::where('school_id', $purchaseRequest->school_id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $purchaseRequest->branch_id))
            ->whereHas('permissions', fn ($q) => $q->where('name', $permission))
            ->get();

        Notification::send($recipients, new PurchaseRequestSubmitted($purchaseRequest));
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $user = $request->user();
        abort_unless($purchaseRequest->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $purchaseRequest->branch_id === null || $purchaseRequest->branch_id === $user->branch_id, 403);
        abort_unless($purchaseRequest->status === 'submitted', 422);
        // a requester never approves their own request
        abort_if($purchaseRequest->requester_id === $user->id, 403);
        $requiredPermission = $purchaseRequest->needsLargeApproval() ? 'purchase-approve-large' : 'purchase-approve';
        abort_unless($user->can($requiredPermission), 403);

        $purchaseRequest->update(['status' => 'approved', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
        $purchaseRequest->requester->notify(new PurchaseRequestApproved($purchaseRequest));

        return back()->with('success', "Purchase request {$purchaseRequest->number} approved.");
    }

    public function reject(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $user = $request->user();
        abort_unless($purchaseRequest->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $purchaseRequest->branch_id === null || $purchaseRequest->branch_id === $user->branch_id, 403);
        abort_unless($purchaseRequest->status === 'submitted', 422);
        abort_if($purchaseRequest->requester_id === $user->id, 403);
        $requiredPermission = $purchaseRequest->needsLargeApproval() ? 'purchase-approve-large' : 'purchase-approve';
        abort_unless($user->can($requiredPermission), 403);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $purchaseRequest->update(['status' => 'rejected', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'rejection_reason' => $validated['reason']]);
        $purchaseRequest->requester->notify(new PurchaseRequestRejected($purchaseRequest));

        return back()->with('success', "Purchase request {$purchaseRequest->number} rejected.");
    }

    public function destroy(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $user = $request->user();
        abort_unless($purchaseRequest->requester_id === $user->id, 403);
        abort_unless($purchaseRequest->status === 'draft', 422);

        $purchaseRequest->delete();

        return redirect()->route('purchase-requests.index')->with('success', 'Draft purchase request removed.');
    }
}
