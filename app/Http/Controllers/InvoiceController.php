<?php

namespace App\Http\Controllers;

use App\Exports\SimpleArrayExport;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\Term;
use App\Notifications\InvoiceGenerated;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

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
            'period' => $i->period_label ?? 'Full Year',
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

    public function export(Request $request)
    {
        $request->user()->can('manage-invoices') || $request->user()->can('view-financial-reports') || abort(403);
        $user = $request->user();

        $invoices = Invoice::whereHas('student', function ($q) use ($user) {
            $q->whereIn('branch_id', $user->school->branches()->pluck('id'));
            if (! $user->isOrgWide()) {
                $q->where('branch_id', $user->branch_id);
            }
        })->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
          ->with(['student', 'items', 'payments'])
          ->get();

        $rows = [
            [$user->school->name],
            ['Invoices — '.now()->format('M j, Y')],
            ['Invoice #', 'Student', 'Student ID', 'Period', 'Total (ETB)', 'Paid (ETB)', 'Balance (ETB)', 'Status'],
        ];

        foreach ($invoices as $i) {
            $rows[] = [
                $i->invoice_number,
                $i->student->fullName(),
                $i->student->student_id_number,
                $i->period_label ?? 'Full Year',
                $i->totalAmount(),
                $i->paidAmount(),
                $i->balance(),
                str_replace('_', ' ', $i->statusLabel()),
            ];
        }

        return Excel::download(new SimpleArrayExport($rows, 'Invoices'), 'invoices.xlsx');
    }

    /**
     * Bulk-generates invoices for every actively-enrolled student in a
     * given branch+grade for the current academic year. How many
     * invoices each student gets depends on how their fees are billed:
     * an "annual" fee produces one invoice for the whole year, a
     * "termly" fee produces one invoice per real Term the school has
     * set up (using each Term's actual dates, not assumed equal
     * slices), and a "monthly" fee produces one per calendar month
     * spanning the academic year (there's no stored "Month" model, so
     * these are computed). A student can end up with a mix of all
     * three if the grade's fees are set up that way. Re-running this
     * only ever fills in whatever period is still missing for a
     * student -- it never duplicates an invoice for a period that
     * already has one.
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

        $feesByFrequency = FeeStructure::where('branch_id', $branch->id)
            ->where('grade_id', $validated['grade_id'])
            ->where('academic_year_id', $academicYear->id)
            ->get()
            ->groupBy('frequency');

        $terms = Term::where('academic_year_id', $academicYear->id)->orderBy('start_date')->get();

        abort_if($feesByFrequency->has('termly') && $terms->isEmpty(), 422, 'This grade has a "per term" fee, but no terms are set up for the current academic year yet.');

        $enrollments = Enrollment::where('status', 'active')
            ->whereHas('section', fn ($q) => $q->where('branch_id', $branch->id)->where('grade_id', $validated['grade_id'])->where('academic_year_id', $academicYear->id))
            ->with('student.optionalServices', 'student.guardians')
            ->get();

        $monthlyPeriods = $this->monthlyPeriods($academicYear);

        $created = 0;
        foreach ($enrollments as $enrollment) {
            $student = $enrollment->student;

            // Optional services (tutoring, transportation, ...) only bill
            // students who actually opted in -- never the whole grade.
            $studentServices = $student->optionalServices
                ->where('branch_id', $branch->id)
                ->where('academic_year_id', $academicYear->id);

            $created += $this->billPeriod(
                $user, $student, $academicYear, null, null, 'annual', $academicYear->start_date,
                $feesByFrequency->get('annual', collect()), $studentServices->where('frequency', 'annual')
            );

            if ($feesByFrequency->has('termly') || $studentServices->where('frequency', 'termly')->isNotEmpty()) {
                foreach ($terms as $term) {
                    $created += $this->billPeriod(
                        $user, $student, $academicYear, $term, $term->name, 'termly', $term->start_date,
                        $feesByFrequency->get('termly', collect()), $studentServices->where('frequency', 'termly')
                    );
                }
            }

            if ($feesByFrequency->has('monthly') || $studentServices->where('frequency', 'monthly')->isNotEmpty()) {
                foreach ($monthlyPeriods as [$periodStart, $label]) {
                    $created += $this->billPeriod(
                        $user, $student, $academicYear, null, $label, 'monthly', $periodStart,
                        $feesByFrequency->get('monthly', collect()), $studentServices->where('frequency', 'monthly')
                    );
                }
            }
        }

        return back()->with('success', "{$created} invoice(s) generated.");
    }

    /**
     * Creates one invoice for one student for one billing period, if
     * it doesn't already exist. Returns 1 if an invoice was created,
     * 0 if that period was already billed or there was nothing to
     * charge for it.
     */
    private function billPeriod($user, Student $student, AcademicYear $year, ?Term $term, ?string $label, string $frequency, $periodStart, Collection $fees, Collection $services): int
    {
        if ($fees->isEmpty() && $services->isEmpty()) {
            return 0;
        }

        $exists = Invoice::where('student_id', $student->id)
            ->where('academic_year_id', $year->id)
            ->where('frequency', $frequency)
            ->where('period_start', $periodStart)
            ->exists();

        if ($exists) {
            return 0;
        }

        $invoice = Invoice::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'term_id' => $term?->id,
            'period_label' => $label,
            'frequency' => $frequency,
            'period_start' => $periodStart,
            'invoice_number' => $this->nextInvoiceNumber($user->school_id),
        ]);

        foreach ($fees as $fee) {
            $invoice->items()->create(['description' => $fee->name, 'amount' => $fee->amount]);
        }
        foreach ($services as $service) {
            $invoice->items()->create(['description' => $service->name, 'amount' => $service->amount]);
        }

        $student->guardians->each(fn ($guardian) => $guardian->notify(new InvoiceGenerated($invoice)));

        return 1;
    }

    /**
     * One entry per calendar month the academic year spans, e.g.
     * [2026-09-01, "September 2026"]. Months aren't a stored model in
     * this schema, so these are computed fresh every time rather than
     * persisted -- they only ever need to exist long enough to check
     * "does an invoice already have this period_start".
     */
    private function monthlyPeriods(AcademicYear $year): array
    {
        $periods = [];
        $cursor = Carbon::parse($year->start_date)->startOfMonth();
        $end = Carbon::parse($year->end_date);

        while ($cursor->lte($end)) {
            $periods[] = [$cursor->toDateString(), $cursor->format('F Y')];
            $cursor = $cursor->copy()->addMonthNoOverflow();
        }

        return $periods;
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        $this->authorizeView($request->user(), $invoice);

        $invoice->load(['student.school', 'items', 'payments.submittedBy', 'payments.verifiedBy']);

        return Inertia::render('Invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'period' => $invoice->period_label ?? 'Full Year',
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
            'paymentAccount' => [
                'bank_name' => $invoice->student->school->bank_name,
                'bank_account_number' => $invoice->student->school->bank_account_number,
                'bank_account_name' => $invoice->student->school->bank_account_name,
                'mobile_money_number' => $invoice->student->school->mobile_money_number,
            ],
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
