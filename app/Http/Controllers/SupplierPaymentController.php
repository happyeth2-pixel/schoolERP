<?php

namespace App\Http\Controllers;

use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Services\AccountingPostings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Paying a supplier bill -- always born "confirmed" (no external party submits proof for money going out,
 * so there is no pending/verify step the way a parent's portal payment has). Follows the same lock-then-
 * recheck discipline as Payment::record(): the bill is locked, the balance re-checked under that lock, and
 * only then is the payment written. */
class SupplierPaymentController extends Controller
{
    public function store(Request $request, SupplierBill $supplierBill): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('supplier-payment-manage'), 403);
        abort_unless($supplierBill->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $supplierBill->branch_id === null || $supplierBill->branch_id === $user->branch_id, 403);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:bank_transfer,mobile_money,cash,other'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'paid_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $amount = round((float) $validated['amount'], 2);

        $payment = DB::transaction(function () use ($user, $supplierBill, $validated, $amount) {
            $locked = SupplierBill::whereKey($supplierBill->id)->lockForUpdate()->first();
            $locked->load('items', 'payments');

            if ($amount > $locked->balance() + 0.001) {
                throw ValidationException::withMessages([
                    'amount' => 'This is more than the bill balance of '.number_format(max(0, $locked->balance()), 2).' ETB.',
                ]);
            }

            return $locked->payments()->create([
                'school_id' => $user->school_id,
                'branch_id' => $locked->branch_id,
                'amount' => $amount,
                'method' => $validated['method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'recorded_by' => $user->id,
                'paid_date' => $validated['paid_date'],
            ]);
        });

        try {
            AccountingPostings::postSupplierPaymentRecorded($payment->fresh('supplierBill'), $user);
        } catch (\Throwable $e) {
            Log::warning('Accounting: failed to post supplier-payment entry', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }

        return back()->with('success', 'Payment to supplier recorded.');
    }

    /** A supplier payment is never edited or deleted (see SupplierPayment::booted()) -- this records a
     * refund or reversal as its own new row, the exact mirror of PaymentController::refund() on the
     * receivable side. Same lock-then-recheck discipline: the payment row is locked first, so two staff
     * reversing the same payment at once cannot together exceed it. */
    public function reverse(Request $request, SupplierPayment $supplierPayment): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('supplier-payment-manage'), 403);
        abort_unless($supplierPayment->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $supplierPayment->branch_id === null || $supplierPayment->branch_id === $user->branch_id, 403);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'kind' => ['required', 'in:refund,reversal'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $error = DB::transaction(function () use ($user, $supplierPayment, $validated, &$reversal) {
            $locked = SupplierPayment::whereKey($supplierPayment->id)->lockForUpdate()->first();

            $reversible = $locked->netAmount();
            if (round((float) $validated['amount'], 2) > $reversible + 0.001) {
                return 'You can reverse at most '.number_format($reversible, 2).' ETB of this payment (the rest has already been reversed).';
            }

            $reversal = $locked->reversals()->create([
                'amount' => round((float) $validated['amount'], 2),
                'kind' => $validated['kind'],
                'reason' => $validated['reason'],
                'reference' => $validated['reference'] ?? null,
                'reversed_by' => $user->id,
                'reversed_at' => now(),
            ]);

            return null;
        });

        if ($error) {
            return back()->withErrors(['amount' => $error]);
        }

        try {
            AccountingPostings::postSupplierPaymentReversed($reversal, $user);
        } catch (\Throwable $e) {
            Log::warning('Accounting: failed to post supplier-payment-reversed entry', ['reversal_id' => $reversal->id, 'error' => $e->getMessage()]);
        }

        return back()->with('success', ($validated['kind'] === 'refund' ? 'Refund' : 'Reversal').' of '.number_format((float) $validated['amount'], 2).' ETB recorded.');
    }
}
