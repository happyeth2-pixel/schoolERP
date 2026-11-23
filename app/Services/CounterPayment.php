<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\NumberSequence;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * "Record money received at the school, against one bill" -- the one place this happens, shared by
 * PaymentController::record() (a single bill) and StudentPaymentController's lump-sum allocator (one
 * family payment split across several bills). Pulled out of PaymentController so neither call site can
 * drift out of sync on the lock order, the balance cap, the just-now duplicate guard, or how a receipt
 * number is issued.
 */
class CounterPayment
{
    /**
     * $fields: method, reference_number, paid_by_name, note, proof_path.
     *
     * @return array{payment: Payment, balance_after: float}|array{error: string}
     */
    public function attempt($actor, Invoice $invoice, float $amount, array $fields): array
    {
        return DB::transaction(function () use ($actor, $invoice, $amount, $fields) {
            // same lock order as PaymentController::verify(): invoice first, receipt counter last
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            $locked->load('items', 'payments');

            if ($locked->is_waived) {
                return ['error' => 'This bill is waived, so it cannot receive a payment.'];
            }
            if ($amount > $locked->balance() + 0.001) {
                return ['error' => 'This is more than the balance of '.number_format(max(0, $locked->balance()), 2).' ETB. Check the amount.'];
            }

            $justNow = Payment::where('invoice_id', $locked->id)->where('channel', Payment::CHANNEL_COUNTER)
                ->where('submitted_by', $actor->id)->where('amount', $amount)->where('method', $fields['method'])
                ->where('created_at', '>=', now()->subSeconds(60))->exists();
            if ($justNow) {
                return ['error' => 'You just recorded this same payment. Look at the payments on the bill before recording it again.'];
            }

            $payment = Payment::create([
                'invoice_id' => $locked->id,
                'amount' => $amount,
                'method' => $fields['method'],
                'reference_number' => $fields['reference_number'] ?? null,
                'proof_path' => $fields['proof_path'] ?? null,
                'submitted_by' => $actor->id,
                'status' => 'verified',
                'channel' => Payment::CHANNEL_COUNTER,
                'paid_by_name' => filled($fields['paid_by_name'] ?? null) ? trim($fields['paid_by_name']) : null,
                'note' => filled($fields['note'] ?? null) ? trim($fields['note']) : null,
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'receipt_number' => $this->nextReceiptNumber($actor->school_id),
            ]);

            return ['payment' => $payment, 'balance_after' => max(0, round($locked->balance() - $amount, 2))];
        }, 5);
    }

    /**
     * Human-readable receipt number RCT-{year}-{6 digits}, taken from the locked counter in
     * number_sequences. Only call this inside a transaction that holds the relevant lock, after every
     * check has passed -- the same rule PaymentController's own verify() still follows for its own copy.
     */
    public function nextReceiptNumber(int $schoolId): string
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
