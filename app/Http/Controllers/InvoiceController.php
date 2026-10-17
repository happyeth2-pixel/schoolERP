<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use App\Models\AuditLog;
use App\Exports\SimpleArrayExport;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\Finance;
use Illuminate\Validation\ValidationException;
use App\Models\NumberSequence;
use App\Models\School;
use App\Models\Student;
use App\Models\Term;
use App\Services\EthiopianCalendar;
use App\Notifications\InvoiceGenerated;
use App\Support\SchoolCalendar;
use App\Http\Controllers\Concerns\PaginatesLists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class InvoiceController extends Controller
{
    use PaginatesLists;

    /**
     * Shared branch/grade scoping for the on-screen list, its summary totals and the Excel export, so what a user
     * filters to on screen is exactly what the totals bar and the spreadsheet agree on -- never a silent mismatch.
     * Deliberately carries no eager-loading of its own: the two callers below need very different things from it
     * (a handful of scalar totals vs. the full invoice with every display relation).
     */
    private function invoiceScope(Request $request, $user): Builder
    {
        $query = Invoice::whereHas('student', function ($q) use ($user) {
            $q->whereIn('branch_id', $user->school->branches()->pluck('id'));
            if (! $user->isOrgWide()) {
                $q->where('branch_id', $user->branch_id);
            }
        })->whereHas('academicYear', fn ($q) => $q->where('is_current', true));

        if ($gradeId = $request->query('grade_id')) {
            $query->whereHas('student.currentEnrollment.section', fn ($q) => $q->where('grade_id', $gradeId));
        }

        if ($term = trim((string) $request->query('q', ''))) {
            $like = '%'.$term.'%';
            $query->where(function ($w) use ($like) {
                $w->where('invoice_number', 'like', $like)
                    ->orWhere('period_label', 'like', $like)
                    ->orWhereHas('student', fn ($s) => $s->where('first_name', 'like', $like)
                        ->orWhere('father_name', 'like', $like)
                        ->orWhere('grandfather_name', 'like', $like)
                        ->orWhere('student_id_number', 'like', $like));
            });
        }

        return $query;
    }

    /**
     * One lightweight row per invoice in scope -- just the scalars Finance::centsFromTotals() needs (gross,
     * discount, verified-received, refunded, is_waived) -- never the invoice's items/payments/refunds
     * collections. The status shown and filtered on is computed, not stored (Finance's own design, so the
     * figures can never drift out of sync -- see Finance's docblock), so answering "which of these invoices are
     * unpaid" or "what do all the filtered invoices add up to" means visiting every invoice in scope once either
     * way; doing it as five numbers per row instead of a fully hydrated model is the actual fix for the memory
     * this screen used to need.
     */
    private function invoiceTotalsQuery(Request $request, $user): Builder
    {
        return $this->invoiceScope($request, $user)
            ->select(['invoices.id', 'invoices.discount_amount', 'invoices.is_waived'])
            ->addSelect([
                'gross' => InvoiceItem::selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('invoice_id', 'invoices.id'),
                'received' => Payment::selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('invoice_id', 'invoices.id')->where('status', 'verified'),
                'refunded' => PaymentRefund::selectRaw('COALESCE(SUM(payment_refunds.amount), 0)')
                    ->join('payments', 'payments.id', '=', 'payment_refunds.payment_id')
                    ->whereColumn('payments.invoice_id', 'invoices.id')
                    ->where('payments.status', 'verified'),
            ]);
    }

    public function index(Request $request): Response
    {
        $request->user()->can('manage-invoices') || $request->user()->can('view-financial-reports') || abort(403);
        $user = $request->user();

        $status = $request->query('status');

        $totalsRows = $this->invoiceTotalsQuery($request, $user)->get();
        $matching = $status ? $totalsRows->filter(fn ($row) => Finance::statusFromTotals($row) === $status) : $totalsRows;

        // the summary bar reflects every invoice the filters match, not just the page on screen
        $totals = Finance::summarizeFromTotals($matching);

        $paginator = $this->invoiceScope($request, $user)
            ->when($status, fn ($q) => $q->whereIn('invoices.id', $matching->pluck('id')))
            // 'items' and 'payments.refunds' are what totalAmount()/paidAmount()/balance()/statusLabel() below
            // read via Finance::cents() -- eager-loaded here so the page is a handful of batched queries, never
            // two more lazy-loaded queries for every single invoice on the page
            ->with(['student.currentEnrollment.section.grade', 'items', 'payments.refunds'])
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $invoices = $paginator->getCollection()->map(fn (Invoice $i) => [
            'id' => $i->id,
            'invoice_number' => $i->invoice_number,
            'student' => $i->student->fullName(),
            'student_id_number' => $i->student->student_id_number,
            'grade' => $i->student->currentEnrollment?->section?->grade?->name,
            'grade_id' => $i->student->currentEnrollment?->section?->grade?->id,
            'period' => $i->period_label ?? 'Full Year',
            'total' => $i->totalAmount(),
            'paid' => $i->paidAmount(),
            'balance' => $i->balance(),
            'status' => $i->statusLabel(),
        ])->values();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'invoicesMeta' => $this->paginationMeta($paginator),
            'totals' => $totals,
            'canManage' => $user->can('manage-invoices'),
            'grades' => $user->school->grades()->orderBy('sort_order')->get(['id', 'name']),
            'branches' => $user->isOrgWide()
                ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
            'filters' => $request->only('grade_id', 'status', 'q'),
        ]);
    }

    /**
     * The spreadsheet always covers the complete filtered set, never just a page -- the same branch/grade/status
     * filters as the screen, resolved the same way (via invoiceTotalsQuery() for which ids match a status filter),
     * then loaded in full (with every relation the rows below actually need) for exactly those invoices.
     */
    public function export(Request $request)
    {
        $request->user()->can('manage-invoices') || $request->user()->can('view-financial-reports') || abort(403);
        $user = $request->user();

        $status = $request->query('status');

        $invoiceQuery = $this->invoiceScope($request, $user)->with(['student.currentEnrollment.section.grade', 'items', 'payments.refunds']);
        if ($status) {
            $matchingIds = $this->invoiceTotalsQuery($request, $user)->get()
                ->filter(fn ($row) => Finance::statusFromTotals($row) === $status)
                ->pluck('id');
            $invoiceQuery->whereIn('invoices.id', $matchingIds);
        }

        $invoices = $invoiceQuery->get();

        $rows = [
            [$user->school->name],
            ['Invoices — '.SchoolCalendar::date(SchoolCalendar::today(), $user)],
            ['Invoice #', 'Student', 'Student ID', 'Grade', 'Period', 'Billed (ETB)', 'Collected (ETB)', 'Balance (ETB)', 'Status'],
        ];

        foreach ($invoices as $i) {
            $rows[] = [
                $i->invoice_number,
                $i->student->fullName(),
                $i->student->student_id_number,
                $i->student->currentEnrollment?->section?->grade?->name ?? '',
                $i->period_label ?? 'Full Year',
                $i->totalAmount(),
                $i->paidAmount(),
                $i->balance(),
                str_replace('_', ' ', $i->statusLabel()),
            ];
        }

        // Same Finance figures as the screen: billed = after discount, collected = verified payments
        // minus refunds, balance = still owed (0 for waived invoices).
        $t = Finance::summarize($invoices);
        $rows[] = [];
        $rows[] = ['TOTAL', '', '', '', $t['invoice_count'].' invoices', $t['net'], $t['collected'], $t['outstanding'], ''];
        $rows[] = ['of which: discounts', '', '', '', '', $t['discount'], '', '', ''];
        $rows[] = ['of which: waived', '', '', '', '', $t['waived'], '', '', ''];
        $rows[] = ['of which: refunded', '', '', '', '', '', $t['refunded'], '', ''];

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

        $ctx = $this->generationContext($request);

        if ($ctx['fees']->has('termly') && $ctx['terms']->isEmpty()) {
            return back()->withErrors(['generate' => 'This grade has a "per term" fee, but no terms are set up for the current academic year yet. Add the terms first (Academic Years & Terms), then generate again. No invoices were created.']);
        }

        $result = $this->runGeneration($user, $ctx, false);

        AuditLog::record($user, 'invoices.generated', $ctx['grade'], null, [
            'branch' => $ctx['branch']->name,
            'academic_year' => $ctx['year']->name,
            'invoices_created' => $result['invoices'],
            'students_considered' => $ctx['enrollments']->count(),
            'periods' => $ctx['selected'] ?? 'all',
        ]);

        return back()
            ->with('success', "{$result['invoices']} invoice(s) generated.")
            ->with('invoice_run', $result + ['branch' => $ctx['branch']->name, 'grade' => $ctx['grade']->name, 'grade_id' => $ctx['grade']->id]);
    }

    /**
     * Read-only "what would happen": runs the very same loop as generate() with nothing written, so the numbers
     * on the last screen of the guided workflow are the ones the real run then produces. Same permission and
     * branch scope as generate(). The problems that stop a run are reported, not thrown, so the screen can explain them.
     */
    public function generatePreview(Request $request)
    {
        $request->user()->can('manage-invoices') || abort(403);
        $user = $request->user();

        $ctx = $this->generationContext($request);
        $fees = $ctx['fees']->flatten(1);
        $servicesStudents = $ctx['enrollments']->filter(fn ($e) => $e->student->optionalServices
            ->where('branch_id', $ctx['branch']->id)->where('academic_year_id', $ctx['year']->id)->isNotEmpty())->count();

        $blocked = null;
        if ($ctx['enrollments']->isEmpty()) {
            $blocked = 'no_students';
        } elseif ($fees->isEmpty() && $servicesStudents === 0) {
            $blocked = 'no_fees';
        } elseif ($ctx['fees']->has('termly') && $ctx['terms']->isEmpty()) {
            $blocked = 'no_terms';
        }

        $result = $blocked ? null : $this->runGeneration($user, $ctx, true);
        if ($result && $result['invoices'] === 0) {
            $blocked = 'nothing_new';
        }

        return response()->json([
            'branch' => $ctx['branch']->name,
            'grade' => $ctx['grade']->name,
            'academic_year' => $ctx['year']->name,
            'students' => $ctx['enrollments']->count(),
            'fees' => $fees->map(fn ($f) => ['name' => $f->name, 'amount' => (float) $f->amount, 'frequency' => $f->frequency])->values(),
            'services_students' => $servicesStudents,
            'terms' => $ctx['terms']->count(),
            'blocked' => $blocked,
            'result' => $result,
        ]);
    }

    /**
     * Everything a run depends on, validated and scoped to the user: the branch and grade, the current year, the
     * grade's fees, its terms and the students. `periods` (optional) narrows the run to some billing periods --
     * `annual`, `term:<id>`, `month:<first day>`; leaving it out means every period, exactly as before.
     */
    private function generationContext(Request $request): array
    {
        $user = $request->user();

        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'grade_id' => ['required', 'integer'],
            'periods' => ['nullable', 'array', 'min:1'],
            'periods.*' => ['string', 'max:40'],
        ]);

        $branch = $user->school->branches()->whereKey($validated['branch_id'])->firstOrFail();
        if (! $user->isOrgWide()) {
            abort_unless($user->branch_id === $branch->id, 403);
        }

        $grade = Grade::whereKey($validated['grade_id'])->where('school_id', $user->school_id)->firstOrFail();
        $year = $user->school->academicYears()->where('is_current', true)->firstOrFail();

        $fees = FeeStructure::where('branch_id', $branch->id)
            ->where('grade_id', $grade->id)
            ->where('academic_year_id', $year->id)
            ->get()
            ->groupBy('frequency');

        $terms = Term::where('academic_year_id', $year->id)->orderBy('start_date')->get();

        // Active students, plus students withdrawn this year (their periods up to the effective date may still be unbilled).
        $enrollments = Enrollment::where(function ($q) {
            $q->active()->orWhere(fn ($w) => $w->where('enrollments.status', 'withdrawn')
                ->whereHas('student', fn ($s) => $s->where('status', 'withdrawn')->whereNotNull('withdrawn_on')));
        })
            ->whereHas('section', fn ($q) => $q->where('branch_id', $branch->id)->where('grade_id', $grade->id)->where('academic_year_id', $year->id))
            ->with('student.optionalServices', 'student.guardians')
            ->get();

        $months = $this->monthlyPeriods($year, $user->school);

        $selected = $validated['periods'] ?? null;
        if ($selected !== null) {
            $allowed = array_merge(['annual'], $terms->map(fn ($t) => 'term:'.$t->id)->all(), collect($months)->map(fn ($m) => 'month:'.$m[0])->all());
            if (array_diff($selected, $allowed) !== []) {
                throw ValidationException::withMessages(['periods' => 'One of the chosen billing periods does not exist for this year.']);
            }
        }

        return compact('branch', 'grade', 'year', 'fees', 'terms', 'enrollments', 'months', 'selected');
    }

    /**
     * Walks every student and every billing period of the run. With $dryRun nothing is written (billPeriod()
     * only reports what it would do); otherwise this is the original generation, unchanged, plus a tally of
     * what happened to each student -- created, already billed, left before the period, or nothing to charge.
     *
     * @return array{students:int,students_billed:int,invoices:int,amount:float,parents_notified:int,future_invoices:int,periods:list<array>,skipped:array<string,list<string>>}
     */
    private function runGeneration($user, array $ctx, bool $dryRun): array
    {
        ['year' => $year, 'branch' => $branch, 'fees' => $fees, 'terms' => $terms, 'enrollments' => $enrollments, 'months' => $months, 'selected' => $selected] = $ctx;

        $today = SchoolCalendar::today();
        $periods = [];
        $skipped = ['already_billed' => [], 'left_school' => [], 'nothing_to_bill' => []];
        $invoices = $billed = $notified = $future = 0;
        $amount = 0.0;

        foreach ($enrollments as $enrollment) {
            $student = $enrollment->student;

            // Optional services (tutoring, transportation, ...) only bill
            // students who actually opted in -- never the whole grade.
            $studentServices = $student->optionalServices
                ->where('branch_id', $branch->id)
                ->where('academic_year_id', $year->id);

            $tally = ['created' => 0, 'exists' => 0, 'withdrawn' => 0];

            $bill = function (string $key, ?Term $term, ?string $label, string $frequency, $start, Collection $periodFees, Collection $periodServices) use ($user, $student, $year, $dryRun, $today, $selected, &$periods, &$tally, &$invoices, &$amount, &$notified, &$future) {
                if ($selected !== null && ! in_array($key, $selected, true)) {
                    return;
                }

                $outcome = $this->billPeriod($user, $student, $year, $term, $label, $frequency, $start, $periodFees, $periodServices, $student->withdrawn_on, $dryRun);
                if ($outcome === 'nothing') {
                    return;
                }

                $periods[$key] ??= ['key' => $key, 'frequency' => $frequency, 'label' => $label, 'starts' => Carbon::parse($start)->toDateString(), 'created' => 0, 'exists' => 0, 'withdrawn' => 0, 'amount' => 0.0];
                $periods[$key][$outcome]++;
                $tally[$outcome]++;

                if ($outcome === 'created') {
                    $line = $this->periodAmount($periodFees, $periodServices);
                    $periods[$key]['amount'] += $line;
                    $invoices++;
                    $amount += $line;
                    $notified += $student->guardians->count();
                    $future += Carbon::parse($start)->gt($today) ? 1 : 0;
                }
            };

            $bill('annual', null, null, 'annual', $year->start_date, $fees->get('annual', collect()), $studentServices->where('frequency', 'annual'));

            if ($fees->has('termly') || $studentServices->where('frequency', 'termly')->isNotEmpty()) {
                foreach ($terms as $term) {
                    $bill('term:'.$term->id, $term, $term->name, 'termly', $term->start_date, $fees->get('termly', collect()), $studentServices->where('frequency', 'termly'));
                }
            }

            if ($fees->has('monthly') || $studentServices->where('frequency', 'monthly')->isNotEmpty()) {
                foreach ($months as [$periodStart, $label]) {
                    $bill('month:'.$periodStart, null, $label, 'monthly', $periodStart, $fees->get('monthly', collect()), $studentServices->where('frequency', 'monthly'));
                }
            }

            if ($tally['created'] > 0) {
                $billed++;
            } else {
                $reason = $tally['exists'] > 0 ? 'already_billed' : ($tally['withdrawn'] > 0 ? 'left_school' : 'nothing_to_bill');
                $skipped[$reason][] = $student->fullName();
            }
        }

        $rank = ['annual' => 0, 'termly' => 1, 'monthly' => 2];
        usort($periods, fn ($x, $y) => [$rank[$x['frequency']], $x['starts']] <=> [$rank[$y['frequency']], $y['starts']]);

        return [
            'students' => $enrollments->count(),
            'students_billed' => $billed,
            'invoices' => $invoices,
            'amount' => round($amount, 2),
            'parents_notified' => $notified,
            'future_invoices' => $future,
            'periods' => array_values($periods),
            'skipped' => array_map(fn ($names) => ['count' => count($names), 'names' => array_slice($names, 0, 50)], $skipped),
        ];
    }

    /** What one invoice for these fees and services adds up to: a student's own price for a service replaces its standard amount. */
    private function periodAmount(Collection $fees, Collection $services): float
    {
        $total = round((float) $fees->sum('amount'), 2);
        foreach ($services as $service) {
            $custom = $service->pivot->custom_amount ?? null;
            $total += round((float) ($custom ?? $service->amount), 2);
        }

        return round($total, 2);
    }

    /** The guided workflow's page: which grade, which periods, a summary, then create. */
    public function wizard(Request $request): Response
    {
        $request->user()->can('manage-invoices') || abort(403);
        $user = $request->user();

        $branches = $user->isOrgWide()
            ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
            : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']);
        $year = $user->school->academicYears()->where('is_current', true)->first();

        $counts = $year
            ? Enrollment::active()->join('sections', 'sections.id', '=', 'enrollments.section_id')
                ->where('sections.academic_year_id', $year->id)->whereIn('sections.branch_id', $branches->pluck('id'))
                ->selectRaw('sections.branch_id as b, sections.grade_id as g, COUNT(*) as n')->groupBy('sections.branch_id', 'sections.grade_id')->get()
                ->mapWithKeys(fn ($r) => ["{$r->b}-{$r->g}" => (int) $r->n])
            : collect();

        return Inertia::render('Invoices/Generate', [
            'branches' => $branches,
            'grades' => $user->school->grades()->orderBy('sort_order')->get(['id', 'name']),
            'academicYear' => $year?->name,
            'studentCounts' => $counts,
            'run' => $request->session()->get('invoice_run'),
        ]);
    }

    /**
     * Creates one invoice for one student for one billing period, if
     * it doesn't already exist. Returns what happened: 'created',
     * 'exists' (that period was already billed), 'withdrawn' (the
     * student left before the period began) or 'nothing' (no fee or
     * service applies). With $dryRun nothing is written and 'created'
     * means "would be created".
     */
    private function billPeriod($user, Student $student, AcademicYear $year, ?Term $term, ?string $label, string $frequency, $periodStart, Collection $fees, Collection $services, $withdrawnOn = null, bool $dryRun = false): string
    {
        // A withdrawn student is only billed for periods that began on or before their effective date.
        if ($withdrawnOn && Carbon::parse($periodStart)->gt(Carbon::parse($withdrawnOn))) {
            return 'withdrawn';
        }

        if ($fees->isEmpty() && $services->isEmpty()) {
            return 'nothing';
        }

        if ($dryRun) {
            return Invoice::where('student_id', $student->id)
                ->where('academic_year_id', $year->id)
                ->where('frequency', $frequency)
                ->whereDate('period_start', $periodStart)
                ->exists() ? 'exists' : 'created';
        }

        // One transaction per invoice: the invoice and ALL its lines exist
        // together or not at all. The school row is locked first so invoice
        // numbers are handed out one at a time, and the "already billed?"
        // check runs under that lock. The unique key on (student, year,
        // frequency, period_start) is still the real guarantee: if two
        // requests ever get past the check together, the database refuses
        // the second insert and we treat it as "someone else just billed
        // this period" -- skipping it, never crashing the rest of the run.
        try {
            $invoice = DB::transaction(function () use ($user, $student, $year, $term, $label, $frequency, $periodStart, $fees, $services) {
                School::whereKey($user->school_id)->lockForUpdate()->first();

                $exists = Invoice::where('student_id', $student->id)
                    ->where('academic_year_id', $year->id)
                    ->where('frequency', $frequency)
                    ->whereDate('period_start', $periodStart)
                    ->exists();

                if ($exists) {
                    return null;
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
                    $invoice->addLineFrom($fee);
                }
                foreach ($services as $service) {
                    // a student's own price for this service, if they have one, replaces the standard amount
                    $custom = $service->pivot->custom_amount ?? null;
                    $invoice->addLineFrom($service, 1, $custom !== null ? (float) $custom : null);
                }

                return $invoice;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            return 'exists';
        }

        if (! $invoice) {
            return 'exists';
        }

        // Notify only after the invoice is safely committed.
        $student->guardians->each(fn ($guardian) => $guardian->notify(new InvoiceGenerated($invoice)));

        // Best-effort: mirrors this invoice into the general ledger if the school has adopted accounting.
        // Never allowed to fail the actual billing run -- any gap here is caught by Phase D's reconciliation.
        try {
            \App\Services\AccountingPostings::postInvoiceIssued($invoice, $user);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Accounting: failed to post invoice-issued entry', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
        }

        return 'created';
    }

    /**
     * One entry per calendar month the academic year spans, e.g.
     * [2026-09-01, "September 2026"]. Months aren't a stored model in
     * this schema, so these are computed fresh every time rather than
     * persisted -- they only ever need to exist long enough to check
     * "does an invoice already have this period_start".
     */
    private function monthlyPeriods(AcademicYear $year, ?School $school = null): array
    {
        $periods = [];
        $end = Carbon::parse($year->end_date);

        // A school that works in the Ethiopian calendar is billed by Ethiopian
        // months (Meskerem, Tikimt, ...), so each period starts on the 1st of an
        // Ethiopian month. Pagume (5-6 days) is not billed as a month of its own.
        // Everyone else keeps Gregorian calendar months.
        if (($school?->calendar_preference) === 'ethiopian') {
            $e = EthiopianCalendar::fromIso(Carbon::parse($year->start_date)->format('Y-m-d'));
            [$y, $m] = [$e['year'], min($e['month'], 12)];

            while (true) {
                $start = EthiopianCalendar::toIso($y, $m, 1);
                if (Carbon::parse($start)->gt($end)) {
                    break;
                }
                $periods[] = [$start, EthiopianCalendar::monthName($m).' '.$y];
                if (++$m > 12) {
                    [$y, $m] = [$y + 1, 1];
                }
            }

            return $periods;
        }

        $cursor = Carbon::parse($year->start_date)->startOfMonth();

        while ($cursor->lte($end)) {
            $periods[] = [$cursor->toDateString(), $cursor->format('F Y')];
            $cursor = $cursor->copy()->addMonthNoOverflow();
        }

        return $periods;
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        $invoice->load(['student.school', 'items', 'payments.submittedBy', 'payments.verifiedBy', 'payments.refunds.refundedBy', 'waivedBy', 'discountBy']);
        $viewer = $request->user();
        $fig = $invoice->figures();
        $school = $invoice->student->school;

        return Inertia::render('Invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'period' => $invoice->period_label ?? 'Full Year',
                'student' => ['id' => $invoice->student->id, 'name' => $invoice->student->fullName()],
                'items' => $invoice->items,
                'subtotal' => $fig['gross'],
                'discount_amount' => $fig['discount'],
                'discount_reason' => $invoice->discount_reason,
                'discount_by' => $invoice->discountBy?->name,
                'is_waived' => $invoice->is_waived,
                'waiver_reason' => $invoice->waiver_reason,
                'waived_by' => $invoice->waivedBy?->name,
                'total' => $fig['net'],
                'waived' => $fig['waived'],
                'refunded' => $fig['refunded'],
                'paid' => $fig['collected'],
                'balance' => $fig['outstanding'],
                'status' => $fig['status'],
                'payments' => $invoice->payments->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => $p->amount,
                    'method' => $p->method,
                    'reference_number' => $p->reference_number,
                    'has_proof' => (bool) $p->proof_path,
                    'status' => $p->status,
                    'submitted_by' => $p->submittedBy->name,
                    'channel' => $p->channel,
                    'paid_by_name' => $p->paid_by_name,
                    'verified_by' => $p->verifiedBy?->name,
                    'rejection_reason' => $p->rejection_reason,
                    'receipt_number' => $p->receipt_number,
                    'refundable' => $p->status === 'verified' ? $p->refundableAmount() : 0,
                    'refunds' => $p->refunds->map(fn ($r) => [
                        'id' => $r->id,
                        'amount' => (float) $r->amount,
                        'kind' => $r->kind,
                        'reason' => $r->reason,
                        'reference' => $r->reference,
                        'by' => $r->refundedBy?->name,
                        'at' => SchoolCalendar::dateTime($r->refunded_at, $viewer),
                    ])->values(),
                ]),
            ],
            'limits' => [
                'discount_percent' => (float) $school->discount_limit_percent,
                'waiver_amount' => (float) $school->waiver_limit_amount,
            ],
            'canApprove' => $viewer->can('approve-large-discounts'),
            'canRefund' => $viewer->can('refund-payments') && ! $viewer->hasRole('parent'),
            'refundKinds' => \App\Models\PaymentRefund::KINDS,
            'canManage' => $request->user()->can('manage-invoices'),
            'canRecord' => Gate::forUser($viewer)->allows('record', $invoice) && ! $invoice->is_waived && $fig['outstanding'] > 0,
            'paymentAccounts' => $invoice->student->school->paymentAccounts()->availableForBranch($invoice->student->branch_id)->get()->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'display_name' => $a->display_name,
                'account_number' => $a->account_number,
                'account_name' => $a->account_name,
                'logo_url' => $a->logoUrl(),
            ]),
        ]);
    }

    public function setDiscount(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-invoices') || abort(403);
        $this->assertManageScope($user, $invoice);

        $validated = $request->validate([
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
        ]);
        $amount = round((float) $validated['discount_amount'], 2);
        if ($amount > 0 && blank($validated['discount_reason'] ?? null)) {
            throw ValidationException::withMessages(['discount_reason' => 'Say why this discount is being given.']);
        }

        $invoice->load('items', 'payments.refunds', 'student.school');
        $fig = $invoice->figures();

        if ($amount > $fig['gross']) {
            return back()->withErrors(['discount_amount' => 'The discount cannot be more than the invoice subtotal ('.number_format($fig['gross'], 2).' ETB).']);
        }

        // A discount cannot push the total below what has already been collected.
        if ($fig['collected'] > $fig['gross'] - $amount) {
            return back()->withErrors(['discount_amount' => 'Already collected '.number_format($fig['collected'], 2).' ETB, so the total cannot go below that. Use a smaller discount.']);
        }

        // Ordinary staff may give discounts up to the limit the school has set; anything larger needs an approver.
        $school = $invoice->student->school;
        $limit = round($fig['gross'] * (float) $school->discount_limit_percent / 100, 2);
        if ($amount > $limit && ! $user->can('approve-large-discounts')) {
            return back()->withErrors(['discount_amount' => 'Discounts above '.(float) $school->discount_limit_percent.'% of the invoice ('.number_format($limit, 2).' ETB) need approval from an owner or administrator.']);
        }

        $oldDiscount = (float) $invoice->discount_amount;

        $invoice->update([
            'discount_amount' => $amount,
            'discount_reason' => $amount > 0 ? $validated['discount_reason'] : null,
            'discount_by' => $amount > 0 ? $user->id : null,
        ]);

        try {
            \App\Services\AccountingPostings::postDiscountChanged($invoice, $oldDiscount, $amount, $user);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Accounting: failed to post discount-changed entry', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
        }

        return back()->with('success', 'Discount updated.');
    }

    public function toggleWaive(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-invoices') || abort(403);
        $this->assertManageScope($user, $invoice);

        // Removing a waiver puts the charge back; anyone who manages invoices may do that.
        if ($invoice->is_waived) {
            $amountWasWaived = $invoice->waivedAmount();

            $invoice->update(['is_waived' => false, 'waiver_reason' => null, 'waived_by' => null, 'waived_at' => null]);

            try {
                \App\Services\AccountingPostings::postWaiverChanged($invoice, false, $amountWasWaived, $user);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Accounting: failed to post waiver-removed entry', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            }

            return back()->with('success', 'Waiver removed.');
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        // Waiving forgives whatever is still unpaid. Above the limit the school has set, that needs an approver.
        $invoice->load('student.school');
        $unpaid = $invoice->balance();
        $school = $invoice->student->school;
        if ($unpaid > (float) $school->waiver_limit_amount && ! $user->can('approve-large-discounts')) {
            return back()->withErrors(['reason' => 'Waiving more than '.number_format((float) $school->waiver_limit_amount, 2).' ETB (this invoice has '.number_format($unpaid, 2).' ETB unpaid) needs approval from an owner or administrator.']);
        }

        $invoice->update(['is_waived' => true, 'waiver_reason' => $validated['reason'], 'waived_by' => $user->id, 'waived_at' => now()]);

        try {
            \App\Services\AccountingPostings::postWaiverChanged($invoice, true, $unpaid, $user);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Accounting: failed to post waiver entry', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
        }

        return back()->with('success', 'Invoice waived.');
    }

    /**
     * INV-{year}-{6 digits} from the locked counter in number_sequences; only
     * call inside the billPeriod() transaction so a rolled-back invoice never
     * uses a number up.
     */
    private function nextInvoiceNumber(int $schoolId): string
    {
        $year = (int) date('Y');
        $prefix = "INV-{$year}-";

        $n = NumberSequence::next($schoolId, 'invoice', $year, fn () => Invoice::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->where('invoice_number', 'like', "{$prefix}%")
            ->pluck('invoice_number')
            ->map(fn ($x) => (int) substr($x, strlen($prefix)))
            ->max() ?? 0);

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
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
