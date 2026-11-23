<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaginatesLists;
use App\Models\BillingBatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Which bills were generated, when, for which classes, how much, and did it work" -- every "Create
 * Bills" run (InvoiceController::generate()), success or not, in one place. Reads only billing_batches
 * (see its migration for why that's a separate table from invoices), so a run that was blocked or failed
 * and created nothing still shows up here instead of vanishing without a trace.
 */
class BillingBatchController extends Controller
{
    use PaginatesLists;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $user->can('manage-invoices') || abort(403);

        $query = BillingBatch::where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->with(['branch:id,name', 'academicYear:id,name', 'initiatedBy:id,name', 'cancelledBy:id,name'])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($this->perPage($request), ['*'], 'page', $request->query('page', 1));

        return Inertia::render('Invoices/Batches', [
            'batches' => $paginator->getCollection()->map(fn (BillingBatch $b) => [
                'id' => $b->id,
                'created_at' => $b->created_at->toDayDateTimeString(),
                'initiated_by' => $b->initiatedBy?->name ?? 'Unknown',
                'branch' => $b->branch->name,
                'academic_year' => $b->academicYear->name,
                'scope_label' => $b->scope_label,
                'periods' => $b->periods,
                'status' => $b->status,
                'students_considered' => $b->students_considered,
                'students_billed' => $b->students_billed,
                'invoices_created' => $b->invoices_created,
                'total_amount' => (float) $b->total_amount,
                'message' => $b->message,
                'generation_batch_id' => $b->generation_batch_id,
                'cancelled' => $b->cancelled_at !== null,
                'cancelled_by' => $b->cancelledBy?->name,
                'can_cancel' => $b->status === 'completed' && $b->cancelled_at === null && $b->generation_batch_id !== null,
            ]),
            'pagination' => $this->paginationMeta($paginator),
            'filters' => ['status' => $request->query('status')],
        ]);
    }
}
