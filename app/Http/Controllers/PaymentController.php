<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Notifications\PaymentRejected;
use App\Notifications\PaymentVerified;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PaymentController extends Controller
{
    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole('parent'), 403);
        abort_unless($user->children()->whereKey($invoice->student_id)->exists(), 403);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:bank_transfer,mobile_money,cash,other'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $path = $request->file('proof')->store('payment-proofs', 'local');

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

    public function index(Request $request): Response
    {
        $request->user()->can('verify-payments') || abort(403);
        $user = $request->user();

        $payments = Payment::where('status', 'pending')
            ->whereHas('invoice.student', function ($q) use ($user) {
                $q->whereIn('branch_id', $user->school->branches()->pluck('id'));
                if (! $user->isOrgWide()) {
                    $q->where('branch_id', $user->branch_id);
                }
            })
            ->with(['invoice.student', 'submittedBy'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'invoice_id' => $p->invoice_id,
                'invoice_number' => $p->invoice->invoice_number,
                'student' => $p->invoice->student->fullName(),
                'amount' => $p->amount,
                'method' => $p->method,
                'reference_number' => $p->reference_number,
                'submitted_by' => $p->submittedBy->name,
                'submitted_at' => $p->created_at->diffForHumans(),
            ]);

        return Inertia::render('Payments/Pending', ['payments' => $payments]);
    }

    public function verify(Request $request, Payment $payment): RedirectResponse
    {
        $request->user()->can('verify-payments') || abort(403);
        $this->assertScope($request->user(), $payment);
        abort_unless($payment->status === 'pending', 422);

        $payment->update([
            'status' => 'verified',
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'receipt_number' => $this->nextReceiptNumber($request->user()->school_id),
        ]);

        $payment->submittedBy->notify(new PaymentVerified($payment));

        return back()->with('success', 'Payment verified. Receipt generated.');
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $request->user()->can('verify-payments') || abort(403);
        $this->assertScope($request->user(), $payment);
        abort_unless($payment->status === 'pending', 422);

        $validated = $request->validate(['rejection_reason' => ['required', 'string', 'max:255']]);

        $payment->update(['status' => 'rejected', 'rejection_reason' => $validated['rejection_reason']]);

        $payment->submittedBy->notify(new PaymentRejected($payment));

        return back()->with('success', 'Payment rejected.');
    }

    public function proof(Request $request, Payment $payment): SymfonyResponse
    {
        $this->authorizeViewProof($request->user(), $payment);
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response($payment->proof_path);
    }

    public function receipt(Request $request, Payment $payment)
    {
        $this->authorizeViewProof($request->user(), $payment);
        abort_unless($payment->status === 'verified', 404);

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

    private function authorizeViewProof($user, Payment $payment): void
    {
        if ($user->hasRole('parent')) {
            abort_unless($user->id === $payment->submitted_by, 403);

            return;
        }

        abort_unless($user->can('verify-payments') || $user->can('view-financial-reports'), 403);
        $this->assertScope($user, $payment);
    }

    private function nextReceiptNumber(int $schoolId): string
    {
        $year = date('Y');
        $prefix = "RCT-{$year}-";
        $count = Payment::whereHas('invoice.student', fn ($q) => $q->where('school_id', $schoolId))
            ->where('receipt_number', 'like', "{$prefix}%")
            ->count();

        return $prefix.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
