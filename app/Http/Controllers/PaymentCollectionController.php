<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\OptionalService;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Student;
use App\Notifications\PaymentVerified;
use App\Services\AccountingPostings;
use App\Services\CounterPayment;
use App\Services\StudentBillingSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "The registrar sits at the desk, a parent walks in with cash, and pays a bit of everything at once" --
 * one table per class: every student, a box per thing they could owe (tuition, each extra service they
 * are actually enrolled in, books), fill in only what this family is paying right now. That can be less
 * than the full balance -- same as any other payment here, the rest simply stays owed and shows up again
 * next time. A student not enrolled in a given service shows "not assigned" there instead of a box.
 *
 * This screen only COLLECTS money against what is already billed -- it never enrolls anyone in a service
 * or sells them a book (that stays on the existing Extra Services / Books pages). The separate boxes are
 * a convenience for TYPING the amount (so the registrar is not doing mental addition); underneath, each
 * student's total is applied the exact same way StudentPaymentController's own one-student lump sum
 * already works -- oldest open invoice first, through CounterPayment -- because payments attach to a
 * whole invoice, never to one line of it (see StudentBillingSnapshot for why the per-category numbers
 * shown are an honest estimate, not a separate ledger). Recorded at the counter, so -- like every other
 * staff-entered payment -- each one is verified immediately; only a parent's own online submission goes
 * through the approval queue.
 */
class PaymentCollectionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('verify-payments'), 403);

        $sectionsQuery = Section::whereIn('branch_id', $user->school->branches()->pluck('id'))
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->with('grade', 'branch');
        if (! $user->isOrgWide()) {
            $sectionsQuery->where('branch_id', $user->branch_id);
        }

        $sections = $sectionsQuery->orderBy('grade_id')->get()->map(fn (Section $s) => [
            'id' => $s->id,
            'label' => "{$s->grade->name} {$s->name}",
            'branch' => $s->branch->name,
        ])->values();

        $sectionId = $request->integer('section');
        $grid = null;
        if ($sectionId) {
            $section = Section::with('grade')->findOrFail($sectionId);
            $this->assertBranchInScope($user, $section->branch_id);
            $grid = $this->buildGrid($section);
        }

        return Inertia::render('Payments/Collect', [
            'sections' => $sections,
            'focus' => $sectionId ?: null,
            'grid' => $grid,
            'branchCount' => $user->school->branches()->count(),
        ]);
    }

    public function store(Request $request, Section $section, CounterPayment $counter): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('verify-payments'), 403);
        $this->assertBranchInScope($user, $section->branch_id);

        $validated = $request->validate([
            'method' => ['required', 'in:bank_transfer,mobile_money,cash,other'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.student_id' => ['required', 'integer'],
            'rows.*.tuition' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'rows.*.book' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'rows.*.services' => ['nullable', 'array'],
            'rows.*.services.*.service_id' => ['required_with:rows.*.services', 'integer'],
            'rows.*.services.*.amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $serviceNames = OptionalService::whereIn('id', collect($validated['rows'])
            ->flatMap(fn ($r) => collect($r['services'] ?? [])->pluck('service_id'))
            ->unique())->pluck('name', 'id');

        $recorded = [];
        $skipped = [];
        $recordedTotal = 0.0;

        foreach ($validated['rows'] as $row) {
            $parts = [];
            if (($row['tuition'] ?? 0) > 0) {
                $parts['Tuition'] = round((float) $row['tuition'], 2);
            }
            if (($row['book'] ?? 0) > 0) {
                $parts['Books'] = round((float) $row['book'], 2);
            }
            foreach ($row['services'] ?? [] as $svcRow) {
                $amt = round((float) ($svcRow['amount'] ?? 0), 2);
                if ($amt > 0) {
                    $parts[$serviceNames[$svcRow['service_id']] ?? 'Service'] = $amt;
                }
            }

            $amount = round(array_sum($parts), 2);
            if ($amount <= 0) {
                continue;
            }

            $student = Student::find($row['student_id']);
            if (! $student || $student->branch_id !== $section->branch_id) {
                $skipped[] = "#{$row['student_id']}";

                continue;
            }

            $invoices = Invoice::where('student_id', $student->id)->where('is_waived', false)
                ->with('items', 'payments')->orderBy('period_start')->get()
                ->filter(fn ($i) => $i->balance() > 0.001)->values();
            $totalOwed = round($invoices->sum(fn ($i) => $i->balance()), 2);

            if ($invoices->isEmpty() || $amount > $totalOwed + 0.001) {
                $skipped[] = $student->fullName();

                continue;
            }

            $note = collect($parts)->map(fn ($v, $k) => "{$k}: ".number_format($v, 2))->implode(', ');

            $remaining = $amount;
            foreach ($invoices as $invoice) {
                if ($remaining <= 0.001) {
                    break;
                }
                $share = round(min($remaining, $invoice->balance()), 2);
                if ($share <= 0) {
                    continue;
                }

                $result = $counter->attempt($user, $invoice, $share, [
                    'method' => $validated['method'],
                    'note' => $note,
                ]);

                if (isset($result['error'])) {
                    // whatever already landed for THIS student stays recorded (each is its own committed
                    // transaction); only the rest of this one student's rows stop early.
                    break;
                }

                $recorded[] = $result['payment'];
                $recordedTotal += $share;
                $remaining = round($remaining - $share, 2);
            }
        }

        foreach ($recorded as $payment) {
            AuditLog::record($user, 'payment.recorded', $payment, null, [
                'invoice' => $payment->invoice->invoice_number,
                'student' => $payment->invoice->student->fullName(),
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'receipt_number' => $payment->receipt_number,
                'channel' => Payment::CHANNEL_COUNTER,
                'note' => $payment->note,
                'part_of_class_collection' => true,
                'section' => "{$section->grade->name} {$section->name}",
            ], 'Money received at the school and recorded by staff, from the class collection table');

            try {
                AccountingPostings::postPaymentVerified($payment, $user);
            } catch (\Throwable $e) {
                Log::warning('Accounting: failed to post payment-verified entry', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            }

            foreach ($payment->invoice->student->guardians as $guardian) {
                $guardian->notify(new PaymentVerified($payment));
            }
        }

        $studentsCount = collect($recorded)->pluck('invoice.student_id')->unique()->count();
        $skipped = array_unique($skipped);

        // Nothing recorded, even though at least one box had an amount in it: this must read as a real,
        // visible failure (a red banner the registrar cannot miss and the typed amount stays on screen to
        // fix), never a quiet "success" message that looks the same as money actually being taken.
        if ($studentsCount === 0) {
            return back()->withErrors(['rows' => empty($skipped)
                ? 'Nothing was recorded -- type an amount for at least one student first.'
                : 'Nothing was recorded. '.count($skipped).' student(s) were more than what they currently owe, or have no open bill at all: '.implode(', ', $skipped).'. Lower the amount and try again.',
            ]);
        }

        $redirect = back()->with('success', number_format($recordedTotal, 2)." ETB recorded for {$studentsCount} student(s).");
        if (! empty($skipped)) {
            $redirect->with('warning', count($skipped).' other student(s) were skipped (more than they owe, or nothing billed yet): '.implode(', ', $skipped).'.');
        }

        return $redirect;
    }

    private function buildGrid(Section $section): array
    {
        $enrollments = $section->enrollments()->active()->with('student.optionalServices')->get();
        $students = $enrollments->map(fn ($e) => $e->student)->sortBy('first_name')->values();

        $serviceIds = $students->flatMap(fn (Student $s) => $s->optionalServices->pluck('id'))->unique()->values();
        $services = OptionalService::whereIn('id', $serviceIds)->orderBy('name')->get(['id', 'name']);

        $roster = $students->map(function (Student $student) use ($services) {
            $owed = StudentBillingSnapshot::owedByCategory($student);
            $enrolledIds = $student->optionalServices->pluck('id');

            return [
                'id' => $student->id,
                'name' => $student->fullName(),
                'student_id_number' => $student->student_id_number,
                'tuition_owed' => $owed['fee'],
                'book_owed' => $owed['book'],
                'services' => $services->map(fn ($svc) => [
                    'service_id' => $svc->id,
                    'assigned' => $enrolledIds->contains($svc->id),
                    'owed' => $owed['service'][$svc->id] ?? 0.0,
                ])->values(),
            ];
        })->values();

        return [
            'section' => ['id' => $section->id, 'label' => "{$section->grade->name} {$section->name}"],
            'services' => $services->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'students' => $roster,
        ];
    }

    private function assertBranchInScope($user, int $branchId): void
    {
        $branch = $user->school->branches()->whereKey($branchId)->first();
        abort_unless($branch, 403);

        if (! $user->isOrgWide()) {
            abort_unless($user->branch_id === $branchId, 403);
        }
    }
}
