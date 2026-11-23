<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Notifications\PaymentVerified;
use App\Rules\SafeUploadRule;
use App\Services\AccountingPostings;
use App\Services\CounterPayment;
use App\Support\SafeUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "A family paid a lump sum covering several bills at once (sometimes a whole year) in one trip to the
 * school" -- walking that in one invoice at a time is tedious and error-prone, especially for a family
 * with no portal account at all (an illiterate guardian, a neighbour dropping off money). One amount, one
 * method, one optional proof; the amount is spread across the student's own open bills, oldest period
 * first, as far as it goes -- each bill it touches gets its own real Payment row and its own receipt,
 * through the exact same CounterPayment rules (lock, balance cap, duplicate-reference and just-now guards)
 * a single-bill payment already uses, never a second copy of that logic.
 *
 * Deliberately capped at what is CURRENTLY billed: there is no "credit on account" ledger yet, so a family
 * paying more than every open bill adds up to is asked to reduce the amount, or have the next bill made
 * first and record the rest separately. Every ETB stays tied to an actual bill -- the same "no money
 * without a reason" discipline the rest of this app's financial trail already holds.
 */
class StudentPaymentController extends Controller
{
    public function create(Request $request, Student $student): Response
    {
        $user = $request->user();
        $this->assertScope($user, $student);

        $invoices = $this->openInvoices($student);
        abort_if($invoices->isEmpty(), 422, 'This student has no unpaid bills to record a payment against.');

        $student->load('guardians', 'currentEnrollment.section.grade');

        return Inertia::render('Payments/BulkRecord', [
            'student' => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'student_id_number' => $student->student_id_number,
                'class' => $student->currentEnrollment?->section ? "{$student->currentEnrollment->section->grade->name} {$student->currentEnrollment->section->name}" : null,
            ],
            'guardians' => $student->guardians->map(fn ($g) => ['name' => $g->name, 'relationship' => Student::relationshipLabel($g->pivot->relationship)])->values(),
            'invoices' => $invoices->map(fn (Invoice $i) => [
                'id' => $i->id,
                'number' => $i->invoice_number,
                'period' => $i->period_label ?? 'Full Year',
                'balance' => $i->balance(),
            ])->values(),
            'totalOwed' => round($invoices->sum(fn ($i) => $i->balance()), 2),
        ]);
    }

    public function store(Request $request, Student $student, CounterPayment $counter): RedirectResponse
    {
        $user = $request->user();
        $this->assertScope($user, $student);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'method' => ['required', 'in:bank_transfer,mobile_money,cash,other'],
            'reference_number' => ['nullable', 'string', 'max:255', 'required_unless:method,cash'],
            'paid_by_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'note' => ['nullable', 'string', 'max:500', 'required_if:method,other'],
            'confirm_duplicate' => ['nullable', 'boolean'],
            'proof' => ['nullable', 'file', 'max:5120', new SafeUploadRule(['jpg', 'png', 'pdf'])],
        ], [
            'reference_number.required_unless' => 'Type the reference number from the bank or mobile-money slip.',
            'note.required_if' => 'Write a short note saying what kind of payment this was.',
        ]);

        $amount = round((float) $validated['amount'], 2);
        $invoices = $this->openInvoices($student);
        $totalOwed = round($invoices->sum(fn ($i) => $i->balance()), 2);

        if ($invoices->isEmpty()) {
            return back()->withErrors(['amount' => 'This student has no unpaid bills right now.']);
        }
        if ($amount > $totalOwed + 0.001) {
            $over = number_format($amount - $totalOwed, 2);

            return back()->withErrors(['amount' => "This is {$over} ETB more than everything currently billed for {$student->fullName()} ({$totalOwed} ETB). Reduce the amount, or generate the next bill first and record the rest separately."])->withInput();
        }

        // Same duplicate-reference guard PaymentController::record() uses, run once for the whole lump sum
        // rather than once per bill it ends up touching -- the slip itself is one physical thing.
        $duplicateConfirmed = false;
        $reference = mb_strtolower(trim((string) ($validated['reference_number'] ?? '')));
        if ($reference !== '') {
            $used = Payment::whereHas('invoice.student', fn ($q) => $q->where('school_id', $user->school_id))
                ->whereIn('status', ['pending', 'verified'])
                ->whereRaw('LOWER(TRIM(reference_number)) = ?', [$reference])
                ->with('invoice.student')->latest('id')->first();

            if ($used) {
                if (! $request->boolean('confirm_duplicate')) {
                    throw ValidationException::withMessages(['duplicate_reference' => "This reference number was already used for {$used->invoice->student->fullName()}'s bill {$used->invoice->invoice_number}. Check the slip. If this is really a different payment, confirm it below."]);
                }
                $duplicateConfirmed = true;
            }
        }

        $path = $request->hasFile('proof') ? SafeUpload::storePrivate($request->file('proof'), 'payment-proofs') : null;

        $remaining = $amount;
        $created = [];
        foreach ($invoices as $index => $invoice) {
            if ($remaining <= 0.001) {
                break;
            }
            $share = round(min($remaining, $invoice->balance()), 2);
            if ($share <= 0) {
                continue;
            }

            $result = $counter->attempt($user, $invoice, $share, [
                'method' => $validated['method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'paid_by_name' => $validated['paid_by_name'] ?? null,
                'note' => $validated['note'] ?? null,
                // the proof slip is one artifact for the whole lump sum -- attached to the first bill only
                'proof_path' => $index === 0 ? $path : null,
            ]);

            if (isset($result['error'])) {
                // Each bill's payment above is already its own committed transaction, so nothing already
                // recorded is rolled back here -- said plainly, with what DID get recorded, never hidden.
                $doneSoFar = count($created) > 0
                    ? 'Recorded '.number_format($amount - $remaining, 2)." ETB across ".count($created).' bill(s) before this happened: '
                    : '';

                return back()->withErrors(['amount' => $doneSoFar.$result['error'].(count($created) > 0 ? ' The rest ('.number_format($remaining, 2).' ETB) was NOT recorded -- try again for it separately.' : '')]);
            }

            $created[] = $result['payment'];
            $remaining = round($remaining - $share, 2);
        }

        foreach ($created as $payment) {
            AuditLog::record($user, 'payment.recorded', $payment, null, [
                'invoice' => $payment->invoice->invoice_number,
                'student' => $student->fullName(),
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'reference_number' => $payment->reference_number,
                'paid_by_name' => $payment->paid_by_name,
                'receipt_number' => $payment->receipt_number,
                'channel' => Payment::CHANNEL_COUNTER,
                'note' => $payment->note,
                'duplicate_reference_confirmed' => $duplicateConfirmed,
                'has_proof' => $path !== null,
                'part_of_bulk_payment' => true,
                'bulk_total' => $amount,
            ], 'Money received at the school and recorded by staff, across several bills in one payment');

            try {
                AccountingPostings::postPaymentVerified($payment, $user);
            } catch (\Throwable $e) {
                Log::warning('Accounting: failed to post payment-verified entry', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            }
        }

        foreach ($student->guardians as $guardian) {
            foreach ($created as $payment) {
                $guardian->notify(new PaymentVerified($payment));
            }
        }

        $appliedTotal = round($amount - $remaining, 2);

        return Inertia::render('Payments/BulkReceipt', [
            'student' => ['id' => $student->id, 'name' => $student->fullName()],
            'amount' => $amount,
            'applied' => $appliedTotal,
            'leftover' => round($amount - $appliedTotal, 2),
            'payments' => collect($created)->map(fn (Payment $p) => [
                'id' => $p->id,
                'invoice_number' => $p->invoice->invoice_number,
                'period' => $p->invoice->period_label ?? 'Full Year',
                'amount' => (float) $p->amount,
                'receipt_number' => $p->receipt_number,
                'receipt_url' => route('payments.receipt', $p->id),
            ])->values(),
            'deskUrl' => route('students.desk', $student->id),
        ])->with('success', number_format($appliedTotal, 2)." ETB recorded across ".count($created).' bill(s).');
    }

    private function openInvoices(Student $student)
    {
        return Invoice::where('student_id', $student->id)
            ->where('is_waived', false)
            ->with('items', 'payments')
            ->orderBy('period_start')
            ->get()
            ->filter(fn (Invoice $i) => $i->balance() > 0.001)
            ->values();
    }

    /** Same rule InvoicePolicy::record() checks for a single invoice, applied once for the whole student
     *  rather than per-invoice -- every one of their bills shares the same school/branch. */
    private function assertScope($user, Student $student): void
    {
        abort_if($user->hasRole('parent') || $user->hasRole('student'), 403);
        abort_unless($user->can('verify-payments'), 403);
        abort_unless($student->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $user->branch_id === $student->branch_id, 403);
    }
}
