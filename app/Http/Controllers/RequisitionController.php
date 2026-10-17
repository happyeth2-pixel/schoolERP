<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryStore;
use App\Models\NumberSequence;
use App\Models\Requisition;
use App\Models\User;
use App\Notifications\RequisitionApproved;
use App\Notifications\RequisitionRejected;
use App\Notifications\RequisitionSubmitted;
use App\Services\Stock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RequisitionController extends Controller
{
    private function visibleStores(User $user)
    {
        return InventoryStore::where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $user->branch_id)))
            ->get();
    }

    /** Requisitions this user may see: their own, always; plus every one in a store they can see, if they
     * hold requisition-approve or requisition-issue (nothing to approve/issue is nothing to look at). */
    private function visibleRequisitions(User $user)
    {
        $query = Requisition::where('school_id', $user->school_id);

        if ($user->can('requisition-approve') || $user->can('requisition-issue')) {
            $storeIds = $this->visibleStores($user)->pluck('id');
            $query->where(fn ($q) => $q->where('requester_id', $user->id)->orWhereIn('store_id', $storeIds));
        } else {
            $query->where('requester_id', $user->id);
        }

        return $query;
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('requisition-create') || $user->can('requisition-approve') || $user->can('requisition-issue'), 403);

        $requisitions = $this->visibleRequisitions($user)
            ->with(['requester:id,name', 'store:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Requisition $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'purpose' => $r->purpose,
                'priority' => $r->priority,
                'status' => $r->status,
                'requester' => $r->requester->name,
                'store_name' => $r->store->name,
                'is_mine' => $r->requester_id === $user->id,
                'created_at' => $r->created_at->toDateString(),
            ]);

        return Inertia::render('Requisitions/Index', [
            'requisitions' => $requisitions,
            'status' => $request->query('status', ''),
            'statuses' => Requisition::STATUSES,
            'canCreate' => $user->can('requisition-create'),
            'canApprove' => $user->can('requisition-approve'),
            'canIssue' => $user->can('requisition-issue'),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('requisition-create'), 403);

        return Inertia::render('Requisitions/Create', [
            'stores' => $this->visibleStores($user)->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'items' => InventoryItem::where('school_id', $user->school_id)->orderBy('name')->get(['id', 'name', 'unit']),
        ]);
    }

    private function nextNumber(int $schoolId): string
    {
        $year = (int) date('Y');
        $prefix = "REQ-{$year}-";

        $n = NumberSequence::next($schoolId, 'requisition', $year, fn () => Requisition::where('school_id', $schoolId)
            ->where('number', 'like', "{$prefix}%")
            ->pluck('number')
            ->map(fn ($x) => (int) substr($x, strlen($prefix)))
            ->max() ?? 0);

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('requisition-create'), 403);

        $validated = $request->validate([
            'store_id' => ['required', 'integer'],
            'department' => ['nullable', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:255'],
            'priority' => ['required', Rule::in(Requisition::PRIORITIES)],
            'action' => ['required', Rule::in(['draft', 'submit'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $storeIds = $this->visibleStores($user)->pluck('id');
        $store = InventoryStore::whereIn('id', $storeIds)->findOrFail($validated['store_id']);

        $requisition = DB::transaction(function () use ($user, $validated, $store) {
            $requisition = Requisition::create([
                'school_id' => $user->school_id,
                'branch_id' => $store->branch_id,
                'store_id' => $store->id,
                'requester_id' => $user->id,
                'number' => $this->nextNumber($user->school_id),
                'department' => $validated['department'] ?? null,
                'purpose' => $validated['purpose'],
                'priority' => $validated['priority'],
                'status' => $validated['action'] === 'submit' ? 'submitted' : 'draft',
            ]);

            foreach ($validated['items'] as $line) {
                $item = InventoryItem::where('school_id', $user->school_id)->findOrFail($line['item_id']);
                $requisition->items()->create([
                    'item_id' => $item->id,
                    'quantity_requested' => $line['quantity'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $requisition;
        });

        if ($requisition->status === 'submitted') {
            $this->notifyApprovers($requisition);
        }

        return redirect()->route('requisitions.show', $requisition)->with('success', $requisition->status === 'submitted' ? 'Requisition submitted for approval.' : 'Requisition saved as a draft.');
    }

    public function show(Request $request, Requisition $requisition): Response
    {
        $user = $request->user();
        abort_unless($requisition->school_id === $user->school_id, 403);
        $isMine = $requisition->requester_id === $user->id;
        abort_unless($isMine || $user->can('requisition-approve') || $user->can('requisition-issue'), 403);

        $requisition->load(['items.item:id,name,unit', 'requester:id,name', 'reviewer:id,name', 'store:id,name']);

        return Inertia::render('Requisitions/Show', [
            'requisition' => [
                'id' => $requisition->id,
                'number' => $requisition->number,
                'department' => $requisition->department,
                'purpose' => $requisition->purpose,
                'priority' => $requisition->priority,
                'status' => $requisition->status,
                'requester' => $requisition->requester->name,
                'reviewer' => $requisition->reviewer?->name,
                'reviewed_at' => $requisition->reviewed_at?->toDateTimeString(),
                'rejection_reason' => $requisition->rejection_reason,
                'store_name' => $requisition->store->name,
                'is_mine' => $isMine,
                'created_at' => $requisition->created_at->toDateTimeString(),
                'items' => $requisition->items->map(fn ($i) => [
                    'id' => $i->id,
                    'item_id' => $i->item_id,
                    'item_name' => $i->item->name,
                    'unit' => $i->item->unit,
                    'quantity_requested' => $i->quantity_requested,
                    'quantity_approved' => $i->quantity_approved,
                    'quantity_issued' => $i->quantity_issued,
                    'remaining' => $i->remaining(),
                    'notes' => $i->notes,
                ]),
            ],
            'canSubmit' => $isMine && $requisition->status === 'draft',
            'canApprove' => $user->can('requisition-approve') && $requisition->status === 'submitted',
            'canIssue' => $user->can('requisition-issue') && in_array($requisition->status, ['approved', 'partially_issued'], true),
        ]);
    }

    public function submit(Request $request, Requisition $requisition): RedirectResponse
    {
        $user = $request->user();
        abort_unless($requisition->requester_id === $user->id, 403);
        abort_unless($requisition->status === 'draft', 422);

        $requisition->update(['status' => 'submitted']);
        $this->notifyApprovers($requisition);

        return back()->with('success', 'Requisition submitted for approval.');
    }

    private function notifyApprovers(Requisition $requisition): void
    {
        $recipients = User::where('school_id', $requisition->school_id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $requisition->branch_id))
            ->whereHas('permissions', fn ($q) => $q->where('name', 'requisition-approve'))
            ->get();

        Notification::send($recipients, new RequisitionSubmitted($requisition));
    }

    public function approve(Request $request, Requisition $requisition): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('requisition-approve'), 403);
        abort_unless($requisition->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $requisition->branch_id === null || $requisition->branch_id === $user->branch_id, 403);
        abort_unless($requisition->status === 'submitted', 422);

        $validated = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($requisition, $validated, $user) {
            foreach ($requisition->items as $item) {
                $approved = $validated['quantities'][$item->id] ?? 0;
                $item->update(['quantity_approved' => min($approved, $item->quantity_requested)]);
            }

            $requisition->update(['status' => 'approved', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
        });

        $recipients = collect([$requisition->requester])
            ->merge(User::where('school_id', $requisition->school_id)
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $requisition->branch_id))
                ->whereHas('permissions', fn ($q) => $q->where('name', 'stock-issue'))
                ->get())
            ->unique('id');
        Notification::send($recipients, new RequisitionApproved($requisition));

        return back()->with('success', "Requisition {$requisition->number} approved.");
    }

    public function reject(Request $request, Requisition $requisition): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('requisition-approve'), 403);
        abort_unless($requisition->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $requisition->branch_id === null || $requisition->branch_id === $user->branch_id, 403);
        abort_unless($requisition->status === 'submitted', 422);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $requisition->update(['status' => 'rejected', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'rejection_reason' => $validated['reason']]);
        $requisition->requester->notify(new RequisitionRejected($requisition));

        return back()->with('success', "Requisition {$requisition->number} rejected.");
    }

    public function issue(Request $request, Requisition $requisition): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('requisition-issue'), 403);
        abort_unless($requisition->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $requisition->branch_id === null || $requisition->branch_id === $user->branch_id, 403);
        abort_unless(in_array($requisition->status, ['approved', 'partially_issued'], true), 422);

        $validated = $request->validate([
            'requisition_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $line = $requisition->items()->findOrFail($validated['requisition_item_id']);
        if ($validated['quantity'] > $line->remaining()) {
            throw ValidationException::withMessages(['quantity' => "Only {$line->remaining()} {$line->item->unit} left to issue on this line."]);
        }

        DB::transaction(function () use ($requisition, $line, $validated, $user) {
            Stock::issue($line->item, $requisition->store, $validated['quantity'], $user, "Requisition {$requisition->number}", $line->id);
            $line->increment('quantity_issued', $validated['quantity']);
            $requisition->refreshIssueStatus();
        });

        return back()->with('success', "Issued {$validated['quantity']} {$line->item->unit} of {$line->item->name}.");
    }

    public function destroy(Request $request, Requisition $requisition): RedirectResponse
    {
        $user = $request->user();
        abort_unless($requisition->requester_id === $user->id, 403);
        abort_unless($requisition->status === 'draft', 422);

        $requisition->delete();

        return redirect()->route('requisitions.index')->with('success', 'Draft requisition removed.');
    }
}
