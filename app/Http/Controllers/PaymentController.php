<?php

namespace App\Http\Controllers;

use App\Notifications\PaymentRefunded;
use App\Support\SafeUpload;
use App\Rules\SafeUploadRule;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\NumberSequence;
use App\Models\Payment;
use App\Notifications\PaymentRejected;
use App\Notifications\PaymentVerified;
use App\Models\PaymentRefund;
use App\Models\Student;
use App\Support\SchoolCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PaymentController extends Controller
{
    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->user();
        Gate::authorize('pay', $invoice);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:bank_transfer,mobile_money,cash,other'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'proof' => ['required', 'file', 'max:5120', new SafeUploadRule(['jpg', 'png', 'pdf'])],
        ]);

        $invoice->load('items', 'payments');
        if ($invoice->is_waived || $invoice->balance() <= 0) {
            throw ValidationException::withMessages(['amount' => 'This invoice has nothing left to pay.']);
        }
        if (round((float) $validated['amount'], 2) > $invoice->balance()) {
            throw ValidationException::withMessages([
                'amount' => "You can't pay more than the remaining balance of ".number_format($invoice->balance(), 2).' ETB.',
            ]);
        }

        $path = SafeUpload::storePrivate($request->file('proof'), 'payment-proofs');

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $validated['amount'],
            'method' => $validated['method'],
            'reference_number' => $validated['reference_number'] ?? null,
            'proof_path' => $path,
            'submitted_by' => $user->id,
            'status' => 'pending',
        ]);

        return back()->with('success', 'Payment proof submitted — the school will verify it shortly.');
    }

    /**
     * "Record payment received": the page for staff who took money at the school (or were shown a bank / mobile-money
     * slip). It asks how, how much, who handed it over, and shows any proof a parent already sent online for the
     * same bill so the same money is not counted twice.
     */
    public function recordForm(Request $request, Invoice $invoice): Response|RedirectResponse
    {
        Gate::authorize('record', $invoice);

        $invoice->load(['student.currentEnrollment.section.grade', 'student.guardians', 'items', 'payments.submittedBy']);
        $fig = $invoice->figures();

        if ($invoice->is_waived || $fig['outstanding'] <= 0) {
            return redirect()->route('invoices.show', $invoice)->withErrors(['payment' => 'This bill has nothing left to pay.']);
        }

        $student = $invoice->student;

        return Inertia::render('Payments/Record', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->invoice_number,
                'period_label' => $invoice->period_label,
                'total' => $fig['net'],
                'collected' => $fig['collected'],
                'balance' => $fig['outstanding'],
            ],
            'student' => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'student_id_number' => $student->student_id_number,
                'class' => $student->currentEnrollment?->section ? "{$student->currentEnrollment->section->grade->name} {$student->currentEnrollment->section->name}" : null,
            ],
            'guardians' => $student->guardians->map(fn ($g) => ['name' => $g->name, 'relationship' => Student::relationshipLabel($g->pivot->relationship)])->values(),
            'waiting' => $invoice->payments->where('status', 'pending')->map(fn (Payment $p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'reference_number' => $p->reference_number,
                'by' => $p->submittedBy?->name,
                'when' => $p->created_at->diffForHumans(),
                'review_url' => route('payments.review', $p->id),
            ])->values(),
        ]);
    }

    /**
     * Records money received at the school. It follows the same financial rules as accepting a parent's proof
     * (the invoice row is locked, a waived bill takes nothing, nobody can pay more than the balance, the receipt
     * number comes from the locked counter only after every check passed) and the payment is born verified because the
     * person recording it holds verify-payments and is the one who received it. The payer needs no account.
     */
    public function record(Request $request, Invoice $invoice): RedirectResponse
    {
        Gate::authorize('record', $invoice);
        $actor = $request->user();

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'method' => ['required', 'in:bank_transfer,mobile_money,cash,other'],
            'reference_number' => ['nullable', 'string', 'max:255', 'required_unless:method,cash'],
            'paid_by_name' => ['required', 'string', 'min:2', 'max:120'],
            'note' => ['nullable', 'string', 'max:500', 'required_if:method,other'],
            'confirm_duplicate' => ['nullable', 'boolean'],
            'proof' => ['nullable', 'file', 'max:5120', new SafeUploadRule(['jpg', 'png', 'pdf'])],
        ], [
            'reference_number.required_unless' => 'Type the reference number from the bank or mobile-money slip.',
            'paid_by_name.required' => 'Write the name of the person who paid.',
            'note.required_if' => 'Write a short note saying what kind of payment this was.',
        ]);

        $amount = round((float) $validated['amount'], 2);

        // The same bank / mobile-money reference on another payment is the classic double count. It is stopped once with
        // the details (only for people who may see that payment; anyone else is told just that it was used) and goes
        // ahead only when the person confirms it really is a different payment. The confirmation is written to the audit trail.
        $duplicateConfirmed = false;
        $reference = mb_strtolower(trim((string) ($validated['reference_number'] ?? '')));
        if ($reference !== '') {
            $used = Payment::whereHas('invoice.student', fn ($q) => $q->where('school_id', $actor->school_id))
                ->whereIn('status', ['pending', 'verified'])
                ->whereRaw('LOWER(TRIM(reference_number)) = ?', [$reference])
                ->with('invoice.student')->latest('id')->first();

            if ($used) {
                if (! $request->boolean('confirm_duplicate')) {
                    $mine = $this->inScopePayments($actor)->whereKey($used->id)->exists();
                    throw ValidationException::withMessages(['duplicate_reference' => $mine
                        ? "This reference number was already used for {$used->invoice->student->fullName()}'s bill {$used->invoice->invoice_number} (".number_format((float) $used->amount, 2).' ETB, '.($used->status === 'pending' ? 'waiting to be checked' : 'accepted').'). Check the slip. If this is really a different payment, confirm it below.'
                        : 'This reference number was already used for another payment. Check the slip. If this is really a different payment, confirm it below.']);
                }
                $duplicateConfirmed = true;
            }
        }
        $path = $request->hasFile('proof') ? SafeUpload::storePrivate($request->file('proof'), 'payment-proofs') : null;

        $result = DB::transaction(function () use ($actor, $invoice, $validated, $amount, $path) {
            // same lock order as verify(): invoice first, receipt counter last
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            $locked->load('items', 'payments');

            if ($locked->is_waived) {
                return ['error' => 'This bill is waived, so it cannot receive a payment.'];
            }
            if ($amount > $locked->balance() + 0.001) {
                return ['error' => 'This is more than the balance of '.number_format(max(0, $locked->balance()), 2).' ETB. Check the amount.'];
            }

            $justNow = Payment::where('invoice_id', $locked->id)->where('channel', Payment::CHANNEL_COUNTER)
                ->where('submitted_by', $actor->id)->where('amount', $amount)->where('method', $validated['method'])
                ->where('created_at', '>=', now()->subSeconds(60))->exists();
            if ($justNow) {
                return ['error' => 'You just recorded this same payment. Look at the payments on the bill before recording it again.'];
            }

            $payment = Payment::create([
                'invoice_id' => $locked->id,
                'amount' => $amount,
                'method' => $validated['method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'proof_path' => $path,
                'submitted_by' => $actor->id,
                'status' => 'verified',
                'channel' => Payment::CHANNEL_COUNTER,
                'paid_by_name' => trim($validated['paid_by_name']),
                'note' => filled($validated['note'] ?? null) ? trim($validated['note']) : null,
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'receipt_number' => $this->nextReceiptNumber($actor->school_id),
            ]);

            return ['payment' => $payment, 'balance_after' => max(0, round($locked->balance() - $amount, 2))];
        }, 5);

        if (isset($result['error'])) {
            return back()->withErrors(['amount' => $result['error']])->withInput();
        }

        /** @var Payment $payment */
        $payment = $result['payment'];
        $invoice->load('student.guardians');

        AuditLog::record($actor, 'payment.recorded', $payment, null, [
            'invoice' => $invoice->invoice_number,
            'student' => $invoice->student->fullName(),
            'amount' => $amount,
            'method' => $payment->method,
            'reference_number' => $payment->reference_number,
            'paid_by_name' => $payment->paid_by_name,
            'receipt_number' => $payment->receipt_number,
            'balance_after' => $result['balance_after'],
            'channel' => Payment::CHANNEL_COUNTER,
            'note' => $payment->note,
            'duplicate_reference_confirmed' => $duplicateConfirmed,
            'has_proof' => $path !== null,
        ], 'Money received at the school and recorded by staff');

        try {
            \App\Services\AccountingPostings::postPaymentVerified($payment, $actor);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Accounting: failed to post payment-verified entry', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }

        // linked parents who use the portal are told; a parent without an account simply gets the printed receipt
        foreach ($invoice->student->guardians as $guardian) {
            $guardian->notify(new PaymentVerified($payment));
        }

        return redirect()->route('payments.review', $payment)->with('success', 'Payment recorded. Receipt '.$payment->receipt_number.' is ready to print.');
    }

    /** Every payment the user may see: their school, and their branch unless they are org-wide. */
    private function inScopePayments($user)
    {
        return Payment::whereHas('invoice.student', function ($q) use ($user) {
            $q->whereIn('branch_id', $user->school->branches()->pluck('id'));
            if (! $user->isOrgWide()) {
                $q->where('branch_id', $user->branch_id);
            }
        });
    }

    public function index(Request $request): Response
    {
        $request->user()->can('verify-payments') || abort(403);
        $user = $request->user();

        $query = $this->inScopePayments($user)->where('status', 'pending')
            ->with(['invoice.student.currentEnrollment.section.grade', 'invoice.items', 'invoice.payments', 'submittedBy']);

        if ($gradeId = $request->query('grade_id')) {
            $query->whereHas('invoice.student.currentEnrollment.section', fn ($q) => $q->where('grade_id', $gradeId));
        }

        if ($method = $request->query('method')) {
            $query->where('method', $method);
        }

        $payments = $query->orderBy('created_at')->orderBy('id')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'review_url' => route('payments.review', $p->id),
                'invoice_id' => $p->invoice_id,
                'invoice_number' => $p->invoice->invoice_number,
                'period_label' => $p->invoice->period_label,
                'student' => $p->invoice->student->fullName(),
                'grade' => $p->invoice->student->currentEnrollment?->section?->grade?->name,
                'grade_id' => $p->invoice->student->currentEnrollment?->section?->grade?->id,
                'amount' => $p->amount,
                'invoice_balance' => max(0, $p->invoice->balance()),
                'invoice_waived' => $p->invoice->is_waived,
                'method' => $p->method,
                'reference_number' => $p->reference_number,
                'submitted_by' => $p->submittedBy->name,
                'submitted_at' => $p->created_at->diffForHumans(),
                'proof_url' => route('payments.proof', $p->id),
                'proof_is_image' => in_array(strtolower(pathinfo((string) $p->proof_path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png']),
            ]);

        return Inertia::render('Payments/Pending', [
            'payments' => $payments,
            'grades' => $user->school->grades()->orderBy('sort_order')->get(['id', 'name']),
            'filters' => $request->only('grade_id', 'method'),
        ]);
    }

    /**
     * One payment, laid out to answer: who paid, how much, for what, how, and what to do next -- with the proof
     * large. It shows nothing that was not already on the payment, its invoice and its student; the decisions
     * still go through verify() / reject() / refund() with all their locks and checks.
     */
    public function review(Request $request, Payment $payment): Response
    {
        $user = $request->user();
        $user->can('verify-payments') || abort(403);
        $this->assertScope($user, $payment);

        $payment->load(['invoice.student.currentEnrollment.section.grade', 'invoice.student.guardians', 'invoice.items', 'invoice.payments.refunds', 'submittedBy', 'verifiedBy', 'refunds.refundedBy']);
        $invoice = $payment->invoice;
        /** @var Student $student */
        $student = $invoice->student;
        $school = $student->school;
        $fig = $invoice->figures();
        $balance = max(0, $fig['outstanding']);
        $pending = $payment->status === 'pending';
        $amount = round((float) $payment->amount, 2);
        $parent = $student->guardians->firstWhere('id', $payment->submitted_by);
        $ext = strtolower(pathinfo((string) $payment->proof_path, PATHINFO_EXTENSION));

        // A heads-up, never a block: the same reference number used again, or the same amount already sent for this invoice.
        $reference = mb_strtolower(trim((string) $payment->reference_number));
        $duplicates = $this->inScopePayments($user)
            ->where('id', '!=', $payment->id)
            ->where(function ($q) use ($payment, $reference) {
                if ($reference !== '') {
                    $q->whereRaw('LOWER(TRIM(reference_number)) = ?', [$reference]);
                }
                $q->orWhere(fn ($w) => $w->where('invoice_id', $payment->invoice_id)->where('amount', $payment->amount)->whereIn('status', ['pending', 'verified']));
            })
            ->with('invoice.student')->orderByDesc('id')->limit(5)->get()
            ->map(fn (Payment $d) => [
                'id' => $d->id,
                'review_url' => route('payments.review', $d->id),
                'student' => $d->invoice->student->fullName(),
                'invoice_number' => $d->invoice->invoice_number,
                'amount' => $d->amount,
                'status' => $d->status,
                'when' => $d->created_at->diffForHumans(),
                'why' => $reference !== '' && mb_strtolower(trim((string) $d->reference_number)) === $reference ? 'same_reference' : 'same_invoice_amount',
            ]);

        $next = $this->inScopePayments($user)->where('status', 'pending')->where('id', '!=', $payment->id)
            ->with('invoice.student')->orderBy('created_at')->orderBy('id')->first();
        $waiting = $this->inScopePayments($user)->where('status', 'pending')->count();

        $canRefund = $payment->status === 'verified' && Gate::forUser($user)->allows('refund', $payment);
        $mayOpenStudent = $user->can('manage-invoices') || $user->can('view-financial-reports') || $user->can('manage-students');

        return Inertia::render('Payments/Review', [
            'doneUrl' => $mayOpenStudent ? route('students.desk', $student->id) : route('payments.pending'),
            'payment' => [
                'id' => $payment->id,
                'status' => $payment->status,
                'amount' => $amount,
                'method' => $payment->method,
                'reference_number' => $payment->reference_number,
                'submitted_by' => $payment->submittedBy->name,
                'submitted_by_relationship' => $parent ? Student::relationshipLabel($parent->pivot->relationship) : null,
                'channel' => $payment->channel,
                'paid_by_name' => $payment->paid_by_name,
                'note' => $payment->note,
                'submitted_at' => SchoolCalendar::dateTime($payment->created_at, $school),
                'submitted_ago' => $payment->created_at->diffForHumans(),
                'verified_by' => $payment->verifiedBy?->name,
                'verified_at' => $payment->verified_at ? SchoolCalendar::dateTime($payment->verified_at, $school) : null,
                'receipt_number' => $payment->receipt_number,
                'rejection_reason' => $payment->rejection_reason,
                'has_proof' => (bool) $payment->proof_path,
                'proof_url' => route('payments.proof', $payment->id),
                'proof_kind' => ! $payment->proof_path ? null : (in_array($ext, ['jpg', 'jpeg', 'png']) ? 'image' : 'pdf'),
                'receipt_url' => $payment->status === 'verified' ? route('payments.receipt', $payment->id) : null,
            ],
            'student' => [
                'name' => $student->fullName(),
                'student_id_number' => $student->student_id_number,
                'class' => $student->currentEnrollment?->section ? "{$student->currentEnrollment->section->grade->name} {$student->currentEnrollment->section->name}" : null,
            ],
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->invoice_number,
                'period_label' => $invoice->period_label,
                'items' => $invoice->items->map(fn ($i) => ['description' => $i->description, 'amount' => (float) $i->amount])->values(),
                'discount' => $fig['discount'],
                'total' => $fig['net'],
                'collected' => $fig['collected'],
                'balance' => $balance,
                'waived' => (bool) $invoice->is_waived,
                'status' => $fig['status'],
                // what is left if THIS pending payment is accepted (an accepted one is already inside `balance`)
                'balance_after' => $pending ? max(0, round($balance - $amount, 2)) : $balance,
            ],
            // the two things that stop a payment being accepted (verify() enforces both again under a lock)
            'blocked' => $pending ? ($invoice->is_waived ? 'waived' : ($amount > $balance + 0.001 ? 'over_balance' : null)) : null,
            'duplicates' => $duplicates,
            'next' => $next ? ['id' => $next->id, 'review_url' => route('payments.review', $next->id), 'student' => $next->invoice->student->fullName(), 'amount' => $next->amount] : null,
            'waiting' => $waiting,
            'refund' => $canRefund ? [
                'refundable' => $payment->refundableAmount(),
                'kinds' => PaymentRefund::KINDS,
            ] : null,
            'refunds' => $payment->refunds->map(fn ($r) => [
                'id' => $r->id,
                'amount' => (float) $r->amount,
                'kind' => $r->kind,
                'reason' => $r->reason,
                'reference' => $r->reference,
                'by' => $r->refundedBy?->name,
                'at' => SchoolCalendar::dateTime($r->refunded_at, $school),
            ])->values(),
        ]);
    }

    public function verify(Request $request, Payment $payment): RedirectResponse
    {
        $request->user()->can('verify-payments') || abort(403);
        $this->assertScope($request->user(), $payment);

        // Everything that decides and records the verification happens in
        // ONE transaction, holding row locks taken in a fixed order
        // (payment -> invoice -> receipt counter), so they cannot deadlock:
        //  - payment row: a second accountant clicking Verify on the same
        //    payment waits here, then sees it is no longer pending;
        //  - invoice row: two payments on one invoice can't both pass the
        //    balance check and together overpay it;
        //  - receipt counter row (taken last, only once every check has
        //    passed): receipt numbers are issued strictly one at a time, and
        //    a failed verification never uses one up.
        $error = DB::transaction(function () use ($request, $payment) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($locked->status !== 'pending') {
                return 'This payment was already '.$locked->status.' by someone else. Nothing was changed.';
            }

            $invoice = Invoice::whereKey($locked->invoice_id)->lockForUpdate()->first();
            $invoice->load('items', 'payments');

            if ($invoice->is_waived) {
                return 'This invoice is waived, so it cannot receive a payment. Reject it instead.';
            }
            if (round((float) $locked->amount, 2) > $invoice->balance()) {
                return 'This payment ('.number_format($locked->amount, 2).' ETB) is more than the invoice balance ('
                    .number_format(max(0, $invoice->balance()), 2).' ETB), so it cannot be verified. Reject it with a reason and ask the parent to resubmit the correct amount.';
            }

            $locked->update([
                'status' => 'verified',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'receipt_number' => $this->nextReceiptNumber($request->user()->school_id),
            ]);

            return null;
        }, 5); // retried automatically if MySQL ever reports a deadlock

        if ($error) {
            return back()->withErrors(['payment' => $error]);
        }

        $payment->refresh();
        $payment->submittedBy->notify(new PaymentVerified($payment));

        try {
            \App\Services\AccountingPostings::postPaymentVerified($payment, $request->user());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Accounting: failed to post payment-verified entry', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }

        return redirect()->route('payments.review', $payment)->with('success', 'Payment verified. Receipt generated.');
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $request->user()->can('verify-payments') || abort(403);
        $this->assertScope($request->user(), $payment);

        $validated = $request->validate(['rejection_reason' => ['required', 'string', 'max:255']]);

        // Same lock + re-check as verify(), so a payment someone else just
        // verified can never be flipped to rejected (or the reverse).
        $error = DB::transaction(function () use ($payment, $validated) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($locked->status !== 'pending') {
                return 'This payment was already '.$locked->status.' by someone else. Nothing was changed.';
            }

            $locked->update(['status' => 'rejected', 'rejection_reason' => $validated['rejection_reason']]);

            return null;
        });

        if ($error) {
            return back()->withErrors(['payment' => $error]);
        }

        $payment->refresh();
        $payment->submittedBy->notify(new PaymentRejected($payment));

        return redirect()->route('payments.review', $payment)->with('success', 'Payment rejected.');
    }

    /**
     * Refund or reverse a VERIFIED payment. The payment itself is never edited or deleted: this
     * writes a separate refund record (amount, kind, reason, who, when) that Finance subtracts
     * from what has been collected, and the audit trail records it. Several partial refunds are
     * allowed as long as together they never exceed the payment.
     */
    public function refund(Request $request, Payment $payment): RedirectResponse
    {
        Gate::authorize('refund', $payment);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'kind' => ['required', 'in:refund,reversal'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $error = DB::transaction(function () use ($request, $payment, $validated, &$refund) {
            // lock the payment first: two staff refunding the same payment at once cannot together exceed it
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($locked->status !== 'verified') {
                return 'Only a verified payment can be refunded.';
            }

            $refundable = $locked->refundableAmount();
            if (round((float) $validated['amount'], 2) > $refundable) {
                return 'You can refund at most '.number_format($refundable, 2).' ETB of this payment (the rest has already been refunded).';
            }

            $refund = $locked->refunds()->create([
                'amount' => round((float) $validated['amount'], 2),
                'kind' => $validated['kind'],
                'reason' => $validated['reason'],
                'reference' => $validated['reference'] ?? null,
                'refunded_by' => $request->user()->id,
                'refunded_at' => now(),
            ]);

            return null;
        });

        if ($error) {
            return back()->withErrors(['amount' => $error]);
        }

        try {
            \App\Services\AccountingPostings::postPaymentRefunded($refund, $request->user());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Accounting: failed to post payment-refunded entry', ['refund_id' => $refund->id, 'error' => $e->getMessage()]);
        }

        $refund->load('payment.invoice.student.guardians');
        foreach ($refund->payment->invoice->student->guardians as $guardian) {
            $guardian->notify(new PaymentRefunded($refund));
        }

        return back()->with('success', ($validated['kind'] === 'refund' ? 'Refund' : 'Reversal').' of '.number_format((float) $validated['amount'], 2).' ETB recorded.');
    }

    public function proof(Request $request, Payment $payment): SymfonyResponse
    {
        Gate::authorize('viewProof', $payment);
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        // Served under a fixed generated name and the type WE detected at upload time, never the
        // uploader's filename; nosniff + sandbox stop a browser treating it as anything else.
        [$ext, $mime] = SafeUpload::servingType($payment->proof_path);

        return Storage::disk('local')->response($payment->proof_path, "payment-{$payment->id}-proof.{$ext}", [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    public function receipt(Request $request, Payment $payment)
    {
        Gate::authorize('viewReceipt', $payment);

        $payment->load('invoice.student');

        $pdf = Pdf::loadView('receipt.pdf', [
            'payment' => $payment,
            'student' => $payment->invoice->student,
        ]);

        return $pdf->download("receipt-{$payment->receipt_number}.pdf");
    }

    private function assertScope($user, Payment $payment): void
    {
        $student = $payment->invoice->student;
        abort_unless($student->school_id === $user->school_id, 403);

        if (! $user->isOrgWide()) {
            abort_unless($student->branch_id === $user->branch_id, 403);
        }
    }

    /**
     * Human-readable receipt number RCT-{year}-{6 digits}, taken from the
     * locked counter in number_sequences. Only call this inside the verify()
     * transaction, after every check has passed.
     */
    private function nextReceiptNumber(int $schoolId): string
    {
        $year = (int) date('Y');
        $prefix = "RCT-{$year}-";

        $n = NumberSequence::next($schoolId, 'receipt', $year, fn () => Payment::whereHas('invoice.student', fn ($q) => $q->where('school_id', $schoolId))
            ->where('receipt_number', 'like', "{$prefix}%")
            ->pluck('receipt_number')
            ->map(fn ($r) => (int) substr($r, strlen($prefix)))
            ->max() ?? 0);

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
