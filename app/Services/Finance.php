<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Support\SchoolCalendar;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * THE definition of the school's money figures. Every screen and export that shows
 * billed / collected / outstanding (dashboards, invoice list, invoice page, the Excel
 * export, payment checks) calls this class -- nothing else does its own arithmetic.
 *
 * Per invoice (all amounts in ETB, computed in whole cents so nothing drifts):
 *
 *   gross       = sum of the invoice's items
 *   discount    = the discount applied, never more than gross
 *   net         = gross - discount                      "billed"
 *   collected   = verified payments - refunds/reversals of those payments
 *   waived      = if the invoice is waived: the part of net not yet collected; otherwise 0
 *   due         = net - waived                          what the family actually has to pay
 *   outstanding = max(0, due - collected)               "owed"; 0 for a waived invoice
 *   credit      = max(0, collected - due)               paid beyond what was due
 *
 * Only VERIFIED payments count as money: pending and rejected ones never do.
 * A refund or reversal is its own record that subtracts from collected -- it never
 * edits or removes the payment it refers to.
 *
 * Two different questions, kept apart on purpose:
 *   - POSITION (billed / collected / outstanding): summarize() over a set of invoices
 *     (the dashboards and invoice list use the current academic year's invoices).
 *   - CASH FLOW (received / refunded in a period): cashFlow() -- money that moved in a
 *     date range regardless of which year's invoice it paid.
 */
class Finance
{
    /** @return array{gross:int,discount:int,net:int,collected:int,refunded:int,waived:int,due:int,outstanding:int,credit:int,status:string} amounts in CENTS */
    public static function cents(Invoice $invoice): array
    {
        $invoice->loadMissing('items', 'payments.refunds');

        $gross = (float) $invoice->items->sum('amount');
        $verified = $invoice->payments->where('status', 'verified');
        $received = (float) $verified->sum('amount');
        $refunded = (float) $verified->flatMap->refunds->sum('amount');

        return self::centsFromTotals($gross, (float) $invoice->discount_amount, $received, $refunded, (bool) $invoice->is_waived);
    }

    /**
     * The same formula as cents(), taking plain totals instead of a loaded Invoice -- so a screen or report that
     * already has these four numbers from a lightweight SQL aggregate (no need to hydrate the invoice's items/
     * payments/refunds relations just to find out its status) computes EXACTLY the same figures, from the same
     * one definition, rather than a second copy of this arithmetic living somewhere else.
     *
     * @return array{gross:int,discount:int,net:int,collected:int,refunded:int,waived:int,due:int,outstanding:int,credit:int,status:string} amounts in CENTS
     */
    public static function centsFromTotals(float $grossAmount, float $discountAmount, float $verifiedReceived, float $refundedAmount, bool $isWaived): array
    {
        $gross = self::toCents($grossAmount);
        $discount = min($gross, self::toCents($discountAmount));
        $net = $gross - $discount;

        $received = self::toCents($verifiedReceived);
        $refunded = self::toCents($refundedAmount);
        $collected = $received - $refunded;

        $waived = $isWaived ? max(0, $net - $collected) : 0;
        $due = $net - $waived;
        $outstanding = max(0, $due - $collected);
        $credit = max(0, $collected - $due);

        $status = match (true) {
            $isWaived => 'waived',
            $outstanding === 0 => 'paid',
            $collected > 0 => 'partially_paid',
            default => 'unpaid',
        };

        return compact('gross', 'discount', 'net', 'collected', 'refunded', 'waived', 'due', 'outstanding', 'credit', 'status');
    }

    /** The same figures in ETB (two decimals), plus the status label. */
    public static function figures(Invoice $invoice): array
    {
        return self::inEtb(self::cents($invoice));
    }

    /**
     * Totals over a set of invoices, plus the collection rate (collected out of what was due).
     *
     * @param  iterable<Invoice>  $invoices
     */
    public static function summarize(iterable $invoices): array
    {
        $sum = ['gross' => 0, 'discount' => 0, 'net' => 0, 'collected' => 0, 'refunded' => 0, 'waived' => 0, 'due' => 0, 'outstanding' => 0, 'credit' => 0];
        $count = 0;

        foreach ($invoices as $invoice) {
            $c = self::cents($invoice);
            foreach ($sum as $k => $_) {
                $sum[$k] += $c[$k];
            }
            $count++;
        }

        $out = self::inEtb($sum);
        $out['invoice_count'] = $count;
        $out['collection_rate'] = $sum['due'] > 0 ? round(min($sum['collected'], $sum['due']) / $sum['due'] * 100, 1) : null;

        return $out;
    }

    /**
     * The same summary as summarize(), but over lightweight scalar rows (e.g. from a SQL aggregate query) instead
     * of hydrated Invoice models -- used where a summary bar or a status filter must look at every matching
     * invoice in a filtered set without paying to load each one's items/payments/refunds relations. Each row
     * needs: gross, discount_amount, received (verified payments), refunded, is_waived.
     *
     * @param  iterable<object{gross:float,discount_amount:float,received:float,refunded:float,is_waived:bool}>  $rows
     */
    public static function summarizeFromTotals(iterable $rows): array
    {
        $sum = ['gross' => 0, 'discount' => 0, 'net' => 0, 'collected' => 0, 'refunded' => 0, 'waived' => 0, 'due' => 0, 'outstanding' => 0, 'credit' => 0];
        $count = 0;

        foreach ($rows as $row) {
            $c = self::centsFromTotals((float) $row->gross, (float) $row->discount_amount, (float) $row->received, (float) $row->refunded, (bool) $row->is_waived);
            foreach ($sum as $k => $_) {
                $sum[$k] += $c[$k];
            }
            $count++;
        }

        $out = self::inEtb($sum);
        $out['invoice_count'] = $count;
        $out['collection_rate'] = $sum['due'] > 0 ? round(min($sum['collected'], $sum['due']) / $sum['due'] * 100, 1) : null;

        return $out;
    }

    /** The status label (waived/paid/partially_paid/unpaid) for one lightweight aggregate row -- see summarizeFromTotals(). */
    public static function statusFromTotals(object $row): string
    {
        return self::centsFromTotals((float) $row->gross, (float) $row->discount_amount, (float) $row->received, (float) $row->refunded, (bool) $row->is_waived)['status'];
    }

    /** The invoices a person may see money figures for: their school/branch, current academic year. */
    public static function invoicesFor(User $user): Builder
    {
        return Invoice::whereHas('student', function ($q) use ($user) {
            $q->whereIn('branch_id', $user->school->branches()->pluck('id'));
            if (! $user->isOrgWide()) {
                $q->where('branch_id', $user->branch_id);
            }
        })->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->with('items', 'payments.refunds');
    }

    /**
     * Money that moved between two instants (UTC), within the person's branch scope.
     *
     * @return array{received:float,refunded:float,net:float}
     */
    public static function cashFlow(User $user, CarbonInterface $fromUtc, CarbonInterface $toUtc): array
    {
        $scope = function ($q) use ($user) {
            $q->whereIn('branch_id', $user->school->branches()->pluck('id'));
            if (! $user->isOrgWide()) {
                $q->where('branch_id', $user->branch_id);
            }
        };

        $received = Payment::where('status', 'verified')
            ->where('verified_at', '>=', $fromUtc)->where('verified_at', '<', $toUtc)
            ->whereHas('invoice.student', $scope)
            ->sum('amount');

        $refunded = PaymentRefund::where('refunded_at', '>=', $fromUtc)->where('refunded_at', '<', $toUtc)
            ->whereHas('payment.invoice.student', $scope)
            ->sum('amount');

        $r = self::toCents($received);
        $f = self::toCents($refunded);

        return ['received' => $r / 100, 'refunded' => $f / 100, 'net' => ($r - $f) / 100];
    }

    /** [start, end) of the school's business day / month, as UTC instants. */
    public static function today(): array
    {
        $start = SchoolCalendar::today()->utc();

        return [$start, $start->copy()->addDay()];
    }

    public static function thisMonth(): array
    {
        $start = SchoolCalendar::today()->startOfMonth()->utc();

        return [$start, SchoolCalendar::today()->startOfMonth()->addMonth()->utc()];
    }

    public static function toCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private static function inEtb(array $cents): array
    {
        $out = [];
        foreach ($cents as $k => $v) {
            $out[$k] = is_int($v) ? $v / 100 : $v;
        }

        return $out;
    }
}
