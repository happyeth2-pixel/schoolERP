<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Student;

/**
 * "How much does this student currently owe, broken down by what it's for" -- tuition (fee lines), each
 * extra service they're actually billed for, and books -- so the walk-in collection grid can show a
 * separate box per category instead of one undifferentiated total.
 *
 * Payments themselves still apply at the WHOLE INVOICE level (Invoice::balance(), CounterPayment), never
 * per line item -- see PaymentCollectionController's own notes for why. So when one invoice mixes, say, a
 * fee line with a service line, each category's "owed" share below is that line's proportional slice of
 * the invoice's actual remaining balance: an honest estimate for display and for filling in the grid's
 * boxes, never a separate ledger. Summed across every category it always equals Invoice::balance().
 */
class StudentBillingSnapshot
{
    /** @return array{fee: float, service: array<int, float>, book: float, total: float} */
    public static function owedByCategory(Student $student): array
    {
        $invoices = Invoice::where('student_id', $student->id)
            ->where('is_waived', false)
            ->with('items')
            ->get()
            ->filter(fn (Invoice $i) => $i->balance() > 0.001);

        $fee = 0.0;
        $service = [];
        $book = 0.0;

        foreach ($invoices as $invoice) {
            $balance = $invoice->balance();
            $total = (float) $invoice->items->sum('amount');
            if ($total <= 0) {
                continue;
            }

            $byKind = $invoice->items->groupBy(fn ($item) => $item->item_type === 'service' ? "service:{$item->optional_service_id}" : $item->item_type);

            foreach ($byKind as $key => $items) {
                $share = round($balance * ((float) $items->sum('amount') / $total), 2);
                if ($share <= 0) {
                    continue;
                }

                if ($key === 'fee') {
                    $fee += $share;
                } elseif ($key === 'book') {
                    $book += $share;
                } elseif (str_starts_with((string) $key, 'service:')) {
                    $serviceId = (int) substr((string) $key, 8);
                    $service[$serviceId] = ($service[$serviceId] ?? 0) + $share;
                }
            }
        }

        return [
            'fee' => round($fee, 2),
            'service' => array_map(fn ($v) => round($v, 2), $service),
            'book' => round($book, 2),
            'total' => round($fee + array_sum($service) + $book, 2),
        ];
    }
}
