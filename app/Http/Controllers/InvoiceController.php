<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->can('manage-invoices') || $request->user()->can('view-financial-reports') || abort(403);
        $user = $request->user();

        $query = Invoice::whereHas('student', function ($q) use ($user) {
            $q->whereIn('branch_id', $user->school->branches()->pluck('id'));
            if (! $user->isOrgWide()) {
                $q->where('branch_id', $user->branch_id);
            }
        })->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
          ->with(['student', 'items', 'payments']);

        $invoices = $query->get()->map(fn (Invoice $i) => [
            'id' => $i->id,
            'invoice_number' => $i->invoice_number,
            'student' => $i->student->fullName(),
            'student_id_number' => $i->student->student_id_number,
            'total' => $i->totalAmount(),
            'paid' => $i->paidAmount(),
            'balance' => $i->balance(),
            'status' => $i->statusLabel(),
        ]);

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'canManage' => $user->can('manage-invoices'),
            'grades' => $user->school->grades()->orderBy('sort_order')->get(['id', 'name']),
            'branches' => $user->isOrgWide()
                ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
        ]);
    }

    /**
     * Bulk-generates one invoice per actively-enrolled student in a
     * given branch+grade for the current academic year, snapshotting
     * that grade's fee structure line items at generation time.
     * Students who already have an invoice for the year are skipped —
     * safe to run again after enrolling a few more students.
     */
    public function generate(Request $request): RedirectResponse
    {
        $request->user()->can('manage-invoices') || abort(403);
        $user = $request->user();

        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'grade_id' => ['required', 'integer'],
        ]);

        $branch = $user->school->branches()->whereKey($validated['branch_id'])->firstOrFail();
        if (! $user->isOrgWide()) {
            abort_unless($user->branch_id === $branch->id, 403);
        }

        $academicYear = $user->school->academicYears()->where('is_current', true)->firstOrFail();

        $feeItems = FeeStructure::where('branch_id', $branch->id)
            ->where('grade_id', $validated['grade_id'])
            ->where('academic_year_id', $academicYear->id)
            ->get();

        abort_if($feeItems->isEmpty(), 422, 'No fee structure defined for this branch/grade/year yet.');

        $enrollments = Enrollment::where('status', 'active')
            ->whereHas('section', fn ($q) => $q->where('branch_id', $branch->id)->where('grade_id', $validated['grade_id'])->where('academic_year_id', $academicYear->id))
            ->with('student')
            ->get();

        $created = 0;
        foreach ($enrollments as $enrollment) {
            $exists = Invoice::where('student_id', $enrollment->student_id)->where('academic_year_id', $academicYear->id)->exists();
            if ($exists) {
                continue;
            }

            $invoice = Invoice::create([
                'student_id' => $enrollment->student_id,
                'academic_year_id' => $academicYear->id,
                'invoice_number' => $this->nextInvoiceNumber($user->school_id),
            ]);

            foreach ($feeItems as $item) {
                $invoice->items()->create(['description' => $item->name, 'amount' => $item->amount]);
            }

            $created++;
        }

        return back()->with('success', "{$created} invoice(s) generated.");
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        $this->authorizeView($request->user(), $invoice);

        $invoice->load(['student', 'items', 'payments.submittedBy', 'payments.verifiedBy']);

        return Inertia::render('Invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'student' => ['id' => $invoice->student->id, 'name' => $invoice->student->fullName()],
                'items' => $invoice->items,
                'subtotal' => $invoice->subtotal(),
                'discount_amount' => (float) $invoice->discount_amount,
                'discount_reason' => $invoice->discount_reason,
                'is_waived' => $invoice->is_waived,
                'total' => $invoice->totalAmount(),
                'paid' => $invoice->paidAmount(),
                'balance' => $invoice->balance(),
                'status' => $invoice->statusLabel(),
                'payments' => $invoice->payments->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => $p->amount,
                    'method' => $p->method,
                    'reference_number' => $p->reference_number,
                    'has_proof' => (bool) $p->proof_path,
                    'status' => $p->status,
                    'submitted_by' => $p->submittedBy->name,
                    'verified_by' => $p->verifiedBy?->name,
                    'rejection_reason' => $p->rejection_reason,
                    'receipt_number' => $p->receipt_number,
                ]),
            ],
            'canManage' => $request->user()->can('manage-invoices'),
        ]);
    }

    public function setDiscount(Request $request, Invoice $invoice): RedirectResponse
    {
        $request->user()->can('manage-invoices') || abort(403);
        $this->assertManageScope($request->user(), $invoice);

        $validated = $request->validate([
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $invoice->update($validated);

        return back()->with('success', 'Discount updated.');
    }

    public function toggleWaive(Request $request, Invoice $invoice): RedirectResponse
    {
        $request->user()->can('manage-invoices') || abort(403);
        $this->assertManageScope($request->user(), $invoice);

        $invoice->update(['is_waived' => ! $invoice->is_waived]);

        return back()->with('success', $invoice->is_waived ? 'Invoice waived.' : 'Waiver removed.');
    }

    private function nextInvoiceNumber(int $schoolId): string
    {
        $year = date('Y');
        $prefix = "INV-{$year}-";
        $count = Invoice::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->where('invoice_number', 'like', "{$prefix}%")
            ->count();

        return $prefix.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    private function authorizeView($user, Invoice $invoice): void
    {
        if ($user->hasRole('parent')) {
            abort_unless($user->children()->whereKey($invoice->student_id)->exists(), 403);

            return;
        }

        abort_unless($user->can('manage-invoices') || $user->can('view-financial-reports'), 403);
        $this->assertManageScope($user, $invoice);
    }

    private function assertManageScope($user, Invoice $invoice): void
    {
        $student = $invoice->student ?? Student::find($invoice->student_id);
        abort_unless($student->school_id === $user->school_id, 403);

        if (! $user->isOrgWide()) {
            abort_unless($student->branch_id === $user->branch_id, 403);
        }
    }
}
